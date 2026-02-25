<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Models\Stream;
use Exception;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class StreamService
{
    private string $outputPath;

    public function __construct(
        private Stream $stream,
    ) {
    }

    public function handle(): void
    {
        $this->outputPath = Storage::disk('tmp')->path($this->stream->path);

        $this->ensureOutputDirectory();

        $inputPath = $this->resolveOriginalPath();

        $this->process($inputPath);
    }

    private function ensureOutputDirectory(): void
    {
        $outputDir = dirname($this->outputPath);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
    }

    private function resolveOriginalPath(): string
    {
        $originalStream = $this->stream->video->streams()->where('type', 'original')->first();

        if (!$originalStream || !Storage::disk('tmp')->exists($originalStream->path)) {
            throw new Exception("Original video file not found on local disk");
        }

        return Storage::disk('tmp')->path($originalStream->path);
    }

    private function process(string $inputPath): void
    {
        $this->stream->update(['status' => VideoStatus::RUNNING->value, 'started_at' => now()]);

        $args = $this->buildArguments();
        $command = "ffmpeg -hide_banner -y -i \"{$inputPath}\" {$args} \"{$this->outputPath}\"";

        $process = Process::timeout(3600)->run($command, function ($type, $output) {
            $this->parseProgress($output);
        });

        if (!$process->successful()) {
            throw new Exception($process->errorOutput());
        }
    }

    private function parseProgress(string $output): void
    {
        $duration = $this->stream->video->duration;

        if (preg_match('/time=(\d{2}):(\d{2}):(\d{2})\.\d+/', $output, $matches)) {
            $currentSeconds = ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];

            if ($duration > 0) {
                $percentage = min(100, round(($currentSeconds / $duration) * 100));

                if ($this->stream->progress != $percentage) {
                    $this->stream->updateQuietly(['progress' => $percentage]);
                }
            }
        }
    }

    private function buildArguments(): string
    {
        $params = $this->stream->input_params ?? [];
        $args = [];
        $parametersConfig = config('ffmpeg.parameters', []);

        $availableParams = collect($parametersConfig)
            ->filter(fn($config) => isset($config['type']) && $config['type'] === $this->stream->type)
            ->toArray();

        foreach ($params as $key => $value) {
            if (isset($availableParams[$key])) {
                $this->appendArgument($args, $availableParams[$key], $value);
            }
        }

        $this->appendStreamMapping($args);

        return implode(' ', $args);
    }

    private function appendStreamMapping(array &$args): void
    {
        $streamIndex = $this->stream->meta['index'];

        $args[] = "-map 0:$streamIndex";

        match ($this->stream->type) {
            'video' => $args[] = '-an -sn',
            'audio' => $args[] = '-vn -sn',
            'subtitle', 'subtitles' => $args[] = '-vn -an',
            default => null,
        };
    }

    private function appendArgument(array &$args, array $config, $value): void
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
