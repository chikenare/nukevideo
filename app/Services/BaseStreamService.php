<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Models\Stream;
use Exception;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

abstract class BaseStreamService
{
    protected string $streamLocalPath;

    public function __construct(
        protected Stream $stream,
    ) {
    }

    public function handle(): void
    {
        $this->streamLocalPath = Storage::disk('local')->path($this->stream->path);

        $this->ensureOutputDirectory();

        $inputLocalPath = $this->resolveOriginalPath();

        $this->process($inputLocalPath);

        $this->uploadToStorage();

        $this->updateStreamCompletion();
    }

    abstract protected function buildArguments(): string;

    abstract protected function getCompletionData(): array;

    protected function ensureOutputDirectory(): void
    {
        $outputDir = dirname($this->streamLocalPath);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
    }

    protected function resolveOriginalPath(): string
    {
        $originalStream = $this->stream->video->streams()->where('type', 'original')->first();

        if (!$originalStream || !Storage::exists($originalStream->path)) {
            throw new Exception("Original video file not found");
        }

        return Storage::disk('local')->path($originalStream->path);
    }

    protected function process(string $inputPath): void
    {
        $this->stream->update(['status' => VideoStatus::RUNNING->value, 'started_at' => now()]);

        $command = $this->buildCommand($inputPath);

        $process = Process::timeout(3600)->run($command, function ($type, $output) {
            $this->parseProgress($output);
        });

        if (!$process->successful()) {
            throw new Exception($process->errorOutput());
        }
    }

    protected function parseProgress(string $output): void
    {
        $duration = $this->stream->video->duration;

        if (preg_match('/time=(\d{2}):(\d{2}):(\d{2})\.\d+/', $output, $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $seconds = (int) $matches[3];

            $currentSeconds = ($hours * 3600) + ($minutes * 60) + $seconds;

            if ($duration > 0) {
                $percentage = min(100, round(($currentSeconds / $duration) * 100));

                if ($this->stream->progress != $percentage) {
                    $this->stream->updateQuietly(['progress' => $percentage]);
                }
            }
        }
    }

    protected function uploadToStorage(): void
    {
        $this->stream->update(['status' => VideoStatus::UPLOADING->value]);

        $fileResource = fopen($this->streamLocalPath, 'r');

        if (!$fileResource) {
            throw new Exception("Could not open local file: $this->streamLocalPath");
        }

        $uploaded = Storage::put($this->stream->path, $fileResource);

        if (!$uploaded) {
            throw new Exception("Error uploading stream to S3: {$this->stream->path}");
        }

        if (is_resource($fileResource)) {
            fclose($fileResource);
        }
    }

    protected function updateStreamCompletion(): void
    {
        $data = array_merge([
            'size' => filesize($this->streamLocalPath),
            'status' => VideoStatus::COMPLETED->value,
            'progress' => 100,
            'completed_at' => now(),
        ], $this->getCompletionData());

        $this->stream->update($data);
    }

    protected function getFFProbeInfo(string $selectStreams = ''): ?array
    {
        $selectFlag = $selectStreams ? "-select_streams $selectStreams" : '';

        $command = "ffprobe -v quiet -print_format json -show_streams $selectFlag \"{$this->streamLocalPath}\"";

        $process = Process::run($command);

        if (!$process->successful()) {
            return null;
        }

        $data = json_decode($process->output(), true)['streams'][0] ?? null;

        if ($data === null) {
            return null;
        }

        return [
            'width' => $data['width'],
            'height' => $data['height'],
        ];
    }

    protected function buildCommand(string $inputPath): string
    {
        $args = $this->buildArguments();

        return "ffmpeg -hide_banner -y -i \"{$inputPath}\" {$args} \"{$this->streamLocalPath}\"";
    }

    protected function appendArgument(array &$args, array $config, $value): void
    {
        if (!isset($config['template'])) {
            return;
        }

        $inputType = $config['input_type'] ?? $config['type'] ?? null;

        if ($inputType === 'boolean') {
            if ($value) {
                $args[] = str_contains($config['template'], '%s')
                    ? sprintf($config['template'], 1)
                    : $config['template'];
            }
            return;
        }

        if ($value === null || $value === '') {
            return;
        }

        $args[] = sprintf($config['template'], $value);
    }
}
