<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DownloadOriginalFileJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Transient storage/network blips shouldn't fail the whole video.
    public $tries = 3;

    public $backoff = [10, 30, 60];

    private const CHUNK_BYTES = 8 * 1024 * 1024; // 8 MiB

    // Abort if no bytes arrive for this long, instead of blocking on a hung
    // socket until the (multi-hour) worker timeout.
    private const STALL_TIMEOUT_SECONDS = 60;

    public function __construct(
        public int $videoId,
        public string $originalPath,
    ) {}

    public function handle(): void
    {
        Log::info('DownloadOriginalFile started', ['video' => $this->videoId, 'path' => $this->originalPath]);

        $video = Video::findOrFail($this->videoId);
        $video->heartbeat();

        $inputPath = $this->originalPath;

        if (! Storage::exists($inputPath)) {
            Log::error('Original file does not exist in storage', ['path' => $inputPath, 'video' => $this->videoId]);
            throw new Exception("Original file $inputPath does not exist in storage");
        }

        $video->update(['status' => VideoStatus::DOWNLOADING->value]);

        $inputLocalPath = Storage::disk('tmp')->path($inputPath);

        // A fully-downloaded original is left at the final path only after the
        // atomic rename below, so its presence means "genuinely complete".
        if (file_exists($inputLocalPath)) {
            $video->update(['status' => VideoStatus::RUNNING->value]);

            return;
        }

        $outputDir = dirname($inputLocalPath);
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Download to a temp file and atomically rename, so a worker that dies
        // mid-copy never leaves a truncated file that the check above would later
        // mistake for a complete original and feed to ffmpeg.
        $partialPath = $inputLocalPath.'.partial';
        $sourceStream = null;
        $destStream = null;

        try {
            $sourceStream = Storage::readStream($inputPath);
            if (! $sourceStream) {
                Log::error('Failed to open source stream', ['path' => $inputPath, 'video' => $this->videoId]);
                throw new Exception("Failed to open source stream for $inputPath");
            }

            $destStream = fopen($partialPath, 'w');
            if (! $destStream) {
                Log::error('Failed to open destination file', ['path' => $partialPath, 'video' => $this->videoId]);
                throw new Exception("Failed to open destination file $partialPath");
            }

            $this->copyWithHeartbeat($sourceStream, $destStream, $video);
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
            if (is_resource($destStream)) {
                fclose($destStream);
            }
        }

        if (! rename($partialPath, $inputLocalPath)) {
            @unlink($partialPath);
            throw new Exception("Failed to finalize downloaded file $inputLocalPath");
        }

        $video->update(['status' => VideoStatus::RUNNING->value]);
    }

    /**
     * Copy the source to the destination in chunks, emitting a throttled heartbeat
     * (so the reaper can tell a large download apart from a dead worker) and
     * aborting if the source stalls.
     *
     * @param  resource  $sourceStream
     * @param  resource  $destStream
     */
    private function copyWithHeartbeat($sourceStream, $destStream, Video $video): void
    {
        stream_set_timeout($sourceStream, self::STALL_TIMEOUT_SECONDS);
        $lastBeat = microtime(true);

        while (! feof($sourceStream)) {
            $chunk = fread($sourceStream, self::CHUNK_BYTES);

            if ($chunk === false || (stream_get_meta_data($sourceStream)['timed_out'] ?? false)) {
                throw new Exception("Read stalled/failed while downloading {$this->originalPath}");
            }

            if ($chunk !== '' && fwrite($destStream, $chunk) === false) {
                throw new Exception("Write error while downloading {$this->originalPath}");
            }

            if ((microtime(true) - $lastBeat) >= 15) {
                $video->heartbeat();
                $lastBeat = microtime(true);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        $video = Video::with('user')->find($this->videoId);

        if ($video) {
            activity('video')
                ->performedOn($video)
                ->causedBy($video->user)
                ->withProperties(['error' => $e->getMessage()])
                ->event('download_failed')
                ->log("Original file download failed: {$e->getMessage()}");
        }
    }
}
