<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Exceptions\EncodeInterruptedException;
use App\Jobs\Concerns\FencedByRun;
use App\Models\Stream;
use App\Models\Video;
use App\Services\Concerns\EmitsHeartbeat;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessSubtitlesJob implements ShouldQueue
{
    use Batchable, Dispatchable, EmitsHeartbeat, FencedByRun, InteractsWithQueue, Queueable, SerializesModels;

    // Extraction demuxes the whole source, so its ceiling scales with duration
    // (see extractionTimeout); the job timeout just needs to sit above that cap.
    public $timeout = 3700;

    private const MAX_EXTRACTION_SECONDS = 3600;

    public function __construct(
        public int $videoId,
        public string $originalPath,
        ?int $runAttempt = null,
    ) {
        $this->runAttempt = $runAttempt;
    }

    public function handle(): void
    {
        Log::info('ProcessSubtitles started', ['video' => $this->videoId]);

        $video = Video::find($this->videoId);

        if ($this->supersededRun($video)) {
            return;
        }

        $video->heartbeat();

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

        $this->extractSubtitles($video, $streams, $inputLocalPath);

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

    private function extractSubtitles(Video $video, Collection $streams, string $inputLocalPath): void
    {
        $commandParts = ['ffmpeg', '-hide_banner', '-y', '-i', "\"{$inputLocalPath}\""];

        foreach ($streams as $stream) {
            $streamLocalPath = Storage::disk('tmp')->path($stream->path);

            $outputDir = dirname($streamLocalPath);
            if (! is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $index = (int) ($stream->meta['index'] ?? 0);
            $commandParts[] = "-map 0:{$index} -c:s webvtt \"{$streamLocalPath}\"";
        }

        // ffmpeg only prints progress here when it flushes packets, which for a
        // pure subtitle demux can be sparse — but each line keeps the (throttled)
        // heartbeat fresh so the reaper never mistakes a long extraction over a
        // big source for a dead worker and forks a duplicate chain.
        $result = Process::timeout($this->extractionTimeout($video))
            ->run(implode(' ', $commandParts), function () use ($video) {
                $this->heartbeat($video);
            });

        if (! $result->successful()) {
            if (EncodeInterruptedException::causedTermination($result->errorOutput())) {
                Log::warning('Subtitle extraction killed by signal; leaving recovery to the reaper', ['video' => $this->videoId]);
                throw EncodeInterruptedException::fromErrorOutput($result->errorOutput());
            }

            Log::error('Subtitle extraction FFmpeg failed', ['video' => $this->videoId, 'error' => $result->errorOutput()]);
            throw new Exception($result->errorOutput());
        }
    }

    /**
     * Demuxing is I/O bound: allow 1.5x realtime with a sane floor and ceiling
     * instead of a flat 10 minutes that long sources kept exceeding.
     */
    private function extractionTimeout(Video $video): int
    {
        $duration = (float) ($video->duration ?? 0);

        return (int) min(max(600, $duration * 1.5), self::MAX_EXTRACTION_SECONDS);
    }

    public function failed(Throwable $exception): void
    {
        if ($exception instanceof EncodeInterruptedException) {
            Log::warning('ProcessSubtitles interrupted by shutdown; leaving recovery to the reaper', [
                'video' => $this->videoId,
            ]);

            return;
        }

        $video = Video::with('user')->find($this->videoId);

        // Never let a superseded run (or a stray redelivery after the video
        // already settled) overwrite subtitle state the current run owns.
        if ($this->supersededRun($video) || ! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            Log::info('ProcessSubtitles failure ignored: run superseded or video terminal', [
                'video' => $this->videoId,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        Stream::where('video_id', $this->videoId)
            ->where('type', 'subtitle')
            ->update([
                'status' => VideoStatus::FAILED->value,
                'error_log' => 'Job failed: '.$exception->getMessage(),
            ]);

        activity('video')
            ->performedOn($video)
            ->causedBy($video->user)
            ->withProperties(['error' => $exception->getMessage()])
            ->event('subtitles_failed')
            ->log("Subtitle extraction failed: {$exception->getMessage()}");
    }
}
