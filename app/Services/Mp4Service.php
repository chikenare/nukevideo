<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Exceptions\EncodeInterruptedException;
use App\Models\Stream;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class Mp4Service
{
    use Concerns\BuildsArguments, Concerns\DetectsStreamCopy, Concerns\EmitsHeartbeat, Concerns\ResolvesScale, Concerns\ResolvesTimeout;

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
            UsageService::record($this->stream->video->user_id, 'encoding_cpu', round(microtime(true) - $start, 2), $this->stream->video->external_user_id ?? '');
        }

        $this->stream->update(['status' => VideoStatus::PENDING->value, 'completed_at' => now()]);
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
        $this->heartbeat($this->stream->video);

        $args = $this->buildArguments();
        $command = "ffmpeg -hide_banner -y -i \"{$inputPath}\" {$args} \"{$this->outputPath}\"";

        $isCopy = $this->shouldCopyVideo() && $this->shouldCopyAudio();
        $timeout = $this->resolveProcessTimeout($isCopy);

        $process = Process::timeout($timeout)->run($command, function ($type, $output) {
            $this->parseProgress($output);
        });

        if (! $process->successful()) {
            if (EncodeInterruptedException::causedTermination($process->errorOutput())) {
                Log::warning('FFmpeg killed by signal; leaving stream for the reaper', ['stream' => $this->stream->id]);
                throw EncodeInterruptedException::fromErrorOutput($process->errorOutput());
            }

            Log::error('FFmpeg process failed', ['stream' => $this->stream->id, 'error' => $process->errorOutput()]);
            throw new RuntimeException($process->errorOutput());
        }
    }

    private function parseProgress(string $output): void
    {
        $this->heartbeat($this->stream->video);

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

        $indexes = $this->textSubtitleIndexes();

        if ($indexes === null) {
            // Legacy stream rows ingested before bitmap filtering: keep the old
            // blanket mapping rather than silently dropping their subtitles.
            $args[] = '-map 0:s?';

            return;
        }

        foreach ($indexes as $index) {
            $args[] = '-map 0:'.(int) $index;
        }
    }

    /**
     * Source indexes of text-based subtitle tracks, recorded at ingestion.
     * null = row predates the field.
     */
    private function textSubtitleIndexes(): ?array
    {
        $indexes = ($this->stream->meta ?? [])['text_subtitle_indexes'] ?? null;

        return is_array($indexes) ? $indexes : null;
    }

    private function appendVideoParams(array &$args, array $params): void
    {
        if ($this->shouldCopyVideo()) {
            $args[] = '-c:v copy';

            return;
        }

        if (isset($params['video_codec'])) {
            $args[] = '-c:v '.$this->assertSafeArgValue($params['video_codec']);
        }

        $scale = $this->buildScaleFilter($this->stream->width, $this->stream->height);
        if ($scale) {
            $args[] = $scale;
        }

        $args = array_merge($args, $this->buildParamsArguments($params, 'video'));
    }

    private function appendAudioParams(array &$args, array $params): void
    {
        if ($this->shouldCopyAudio()) {
            $args[] = '-c:a copy';

            return;
        }

        if (isset($params['audio_codec'])) {
            $args[] = '-c:a '.$this->assertSafeArgValue($params['audio_codec']);
        }

        $args = array_merge($args, $this->buildParamsArguments($params, 'audio'));
    }

    private function appendSubtitleParams(array &$args): void
    {
        if ($this->textSubtitleIndexes() === []) {
            return; // no text subtitles mapped; a codec flag would be meaningless
        }

        $args[] = '-c:s mov_text';
    }
}
