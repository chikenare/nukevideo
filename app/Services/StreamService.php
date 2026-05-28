<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Models\Stream;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StreamService
{
    use Concerns\BuildsArguments, Concerns\DetectsStreamCopy, Concerns\ResolvesScale, Concerns\ResolvesTimeout;

    private string $outputPath;

    public function __construct(
        private Stream $stream,
    ) {}

    public function handle(): void
    {
        $this->outputPath = Storage::disk('tmp')->path($this->stream->path);

        $this->ensureOutputDirectory();

        $inputPath = $this->resolveOriginalPath();

        $start = microtime(true);
        try {
            $this->process($inputPath);
        } finally {
            $codec = $this->stream->input_params['video_codec'] ?? $this->stream->input_params['audio_codec'] ?? null;
            $metric = 'encoding_'.(CodecService::isGpuCodec($codec) ? 'gpu' : 'cpu');
            UsageService::record($this->stream->video->user_id, $metric, round(microtime(true) - $start, 2), $this->stream->video->external_user_id ?? '');
        }

        $this->stream->update(['status' => VideoStatus::PENDING->value]);
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
            Log::error('Original video file not found on local disk', ['stream' => $this->stream->id, 'video' => $this->stream->video->id]);
            throw new RuntimeException('Original video file not found on local disk');
        }

        return Storage::disk('tmp')->path($originalStream->path);
    }

    private function process(string $inputPath): void
    {
        $this->stream->update(['status' => VideoStatus::RUNNING->value, 'started_at' => now()]);

        $canCopy = match ($this->stream->type) {
            'video' => $this->shouldCopyVideo(),
            'audio' => $this->shouldCopyAudio(),
            default => false,
        };

        $args = $this->buildArguments($canCopy);
        $command = "ffmpeg -hide_banner -y -i \"{$inputPath}\" {$args} \"{$this->outputPath}\"";
        Log::debug($command);

        $timeout = $this->resolveProcessTimeout($canCopy);

        $process = Process::timeout($timeout)->run($command, function ($type, $output) {
            $this->parseProgress($output);
        });

        if (! $process->successful()) {
            Log::error('FFmpeg process failed', ['stream' => $this->stream->id, 'error' => $process->errorOutput()]);
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

    private function buildArguments(bool $canCopy): string
    {
        if ($canCopy) {
            $args = [];
            $codecFlag = $this->stream->type === 'video' ? '-c:v' : '-c:a';
            $args[] = "{$codecFlag} copy";
            $this->appendStreamMapping($args);

            return implode(' ', $args);
        }

        $params = $this->stream->input_params ?? [];
        $args = [];

        // Set codec first
        $codecKey = $this->stream->type === 'video' ? 'video_codec' : 'audio_codec';
        $codecFlag = $this->stream->type === 'video' ? '-c:v' : '-c:a';
        if (isset($params[$codecKey])) {
            $args[] = "{$codecFlag} {$params[$codecKey]}";
        }

        if ($this->stream->type === 'video') {
            $scale = $this->buildScaleFilter($this->stream->width, $this->stream->height);
            if ($scale) {
                $args[] = $scale;
            }
        }

        $args = array_merge($args, $this->buildParamsArguments($params, $this->stream->type));

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
}
