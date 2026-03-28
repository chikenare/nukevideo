<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Models\Stream;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class Mp4Service
{
    private string $outputPath;

    public function __construct(
        private Stream $stream,
    ) {}

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

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
    }

    private function resolveOriginalPath(): string
    {
        $originalStream = $this->stream->video->streams()->where('type', 'original')->first();

        if (! $originalStream || ! Storage::disk('tmp')->exists($originalStream->path)) {
            throw new RuntimeException('Original video file not found on local disk');
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

        if (! $process->successful()) {
            throw new RuntimeException($process->errorOutput());
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

        $this->appendStreamMappings($args);
        $this->appendVideoParams($args, $params);
        $this->appendAudioParams($args, $params);
        $this->appendSubtitleParams($args);

        return implode(' ', $args);
    }

    private function appendStreamMappings(array &$args): void
    {
        $args[] = '-map 0:v:0';
        $args[] = '-map 0:a?';
        $args[] = '-map 0:s?';
    }

    private function appendVideoParams(array &$args, array $params): void
    {
        if (isset($params['video_codec'])) {
            $args[] = "-c:v {$params['video_codec']}";
        }

        $parametersConfig = config('ffmpeg.parameters', []);
        $videoParams = collect($parametersConfig)
            ->filter(fn ($config) => isset($config['type']) && $config['type'] === 'video')
            ->toArray();

        foreach ($params as $key => $value) {
            if ($key === 'video_codec') {
                continue;
            }
            if (isset($videoParams[$key])) {
                $this->appendArgument($args, $videoParams[$key], $value);
            }
        }
    }

    private function appendAudioParams(array &$args, array $params): void
    {
        if (isset($params['audio_codec'])) {
            $args[] = "-c:a {$params['audio_codec']}";
        }

        $parametersConfig = config('ffmpeg.parameters', []);
        $audioParams = collect($parametersConfig)
            ->filter(fn ($config) => isset($config['type']) && $config['type'] === 'audio')
            ->toArray();

        foreach ($params as $key => $value) {
            if ($key === 'audio_codec' || $key === 'channels') {
                continue;
            }
            if (isset($audioParams[$key])) {
                $this->appendArgument($args, $audioParams[$key], $value);
            }
        }
    }

    private function appendSubtitleParams(array &$args): void
    {
        $args[] = '-c:s mov_text';
    }

    private function appendArgument(array &$args, array $config, $value): void
    {
        if (! isset($config['template'])) {
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
