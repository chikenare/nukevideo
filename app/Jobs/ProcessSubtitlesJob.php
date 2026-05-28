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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ProcessSubtitlesJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 700;

    public function __construct(
        public int $videoId,
        public string $originalPath,
    ) {}

    public function handle(): void
    {
        Log::info('ProcessSubtitles started', ['video' => $this->videoId]);

        $streams = Stream::where('video_id', $this->videoId)
            ->where('type', 'subtitle')
            ->get();

        if ($streams->isEmpty()) {
            return;
        }

        $inputLocalPath = Storage::disk('tmp')->path($this->originalPath);

        if (! file_exists($inputLocalPath)) {
            Log::error('Original video file not found for subtitle extraction', ['path' => $inputLocalPath, 'video' => $this->videoId]);
            throw new Exception("Original video file not found at: $inputLocalPath");
        }

        $streams->each->update(['status' => VideoStatus::RUNNING->value, 'started_at' => now()]);

        $this->extractSubtitles($streams, $inputLocalPath);

        foreach ($streams as $stream) {
            $localPath = Storage::disk('tmp')->path($stream->path);

            if (! file_exists($localPath)) {
                $stream->update([
                    'status' => VideoStatus::FAILED->value,
                    'error_log' => "Extracted subtitle file not found: {$stream->path}",
                ]);

                continue;
            }

            $stream->update([
                'size' => filesize($localPath),
                'status' => VideoStatus::PENDING->value,
                'progress' => 100,
                'completed_at' => now(),
            ]);
        }
    }

    private function extractSubtitles($streams, string $inputLocalPath): void
    {
        $commandParts = ['ffmpeg', '-hide_banner', '-y', '-i', "\"{$inputLocalPath}\""];

        foreach ($streams as $stream) {
            $streamLocalPath = Storage::disk('tmp')->path($stream->path);

            $outputDir = dirname($streamLocalPath);
            if (! is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $index = $stream->meta['index'] ?? 0;
            $commandParts[] = "-map 0:{$index} -c:s webvtt \"{$streamLocalPath}\"";
        }

        $result = Process::timeout(600)->run(implode(' ', $commandParts));

        if (! $result->successful()) {
            Log::error('Subtitle extraction FFmpeg failed', ['video' => $this->videoId, 'error' => $result->errorOutput()]);
            throw new Exception($result->errorOutput());
        }
    }

    public function failed(Exception $exception): void
    {
        Stream::where('video_id', $this->videoId)
            ->where('type', 'subtitle')
            ->update([
                'status' => VideoStatus::FAILED->value,
                'error_log' => 'Job failed: '.$exception->getMessage(),
            ]);

        $video = \App\Models\Video::with('user')->find($this->videoId);

        if ($video) {
            activity('video')
                ->performedOn($video)
                ->causedBy($video->user)
                ->withProperties(['error' => $exception->getMessage()])
                ->event('subtitles_failed')
                ->log("Subtitle extraction failed: {$exception->getMessage()}");
        }
    }
}
