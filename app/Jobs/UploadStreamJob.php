<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Stream;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadStreamJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public function __construct(
        public int $streamId,
    ) {
    }

    public function handle(): void
    {
        $stream = Stream::with('video')->findOrFail($this->streamId);

        $localPath = Storage::disk('tmp')->path($stream->path);

        $this->upload($stream, $localPath);

        $completionData = $this->getCompletionData($stream, $localPath);

        $stream->update(array_merge([
            'size' => filesize($localPath),
            'status' => VideoStatus::COMPLETED->value,
            'progress' => 100,
            'completed_at' => now(),
        ], $completionData));

        $this->markVideoCompletedIfReady($stream->video);
    }

    private function markVideoCompletedIfReady($video): void
    {
        $allCompleted = !$video->streams()
            ->whereIn('type', ['video', 'audio'])
            ->where('status', '!=', VideoStatus::COMPLETED->value)
            ->exists();

        if ($allCompleted) {
            $video->update(['status' => VideoStatus::COMPLETED->value]);
        }
    }

    private function upload(Stream $stream, string $localPath): void
    {
        $stream->update(['status' => VideoStatus::UPLOADING->value]);

        $fileResource = fopen($localPath, 'r');

        if (!$fileResource) {
            throw new Exception("Could not open local file: $localPath");
        }

        try {
            $uploaded = Storage::put($stream->path, $fileResource);

            if (!$uploaded) {
                throw new Exception("Error uploading stream to S3: {$stream->path}");
            }
        } finally {
            if (is_resource($fileResource)) {
                fclose($fileResource);
            }
        }
    }

    private function getCompletionData(Stream $stream, string $localPath): array
    {
        if ($stream->type !== 'video') {
            return [];
        }

        $command = "ffprobe -v quiet -print_format json -show_streams \"{$localPath}\"";
        $process = Process::run($command);

        if (!$process->successful()) {
            return [];
        }

        $data = json_decode($process->output(), true)['streams'][0] ?? null;

        if (!$data) {
            return [];
        }

        return [
            'width' => $data['width'],
            'height' => $data['height'],
        ];
    }

    public function failed(Throwable $e): void
    {
        Stream::where('id', $this->streamId)->update([
            'status' => VideoStatus::FAILED->value,
            'error_log' => $e->getMessage(),
        ]);
    }
}
