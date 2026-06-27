<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\ThumbnailService;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds the WebVTT storyboard (sprite sheets + cue file) for seek-bar previews. One ffmpeg
 * pass emits every sheet; the VTT is then pure arithmetic since each cue's sheet and cell are
 * a fixed function of its index.
 */
class GenerateVideoStoryboard implements ShouldQueue
{
    use Batchable, Queueable;

    /** Seconds between thumbnails (also the VTT cue length). */
    private const THUMBNAIL_INTERVAL = 10;

    private const SPRITE_COLUMNS = 10;

    private const SPRITE_ROWS = 10;

    /** Keep THUMB_WIDTH * SPRITE_COLUMNS <= 4096 to stay within the max GPU texture size. */
    private const THUMB_WIDTH = 320;

    private const DEFAULT_ASPECT_RATIO = 1.777; // 16:9

    private const FFMPEG_QUALITY = 5; // ffmpeg -q:v (2 best .. 31 worst)

    private const FFMPEG_TIMEOUT = 600; // 10 minutes

    public function __construct(
        private int $videoId,
        private string $mirrorPath,
    ) {}

    public function handle(): void
    {
        Log::info('GenerateStoryboard started', ['video' => $this->videoId]);

        try {
            $video = $this->getVideo();
            $sourceUrl = SegmentVideoJob::sourceUrl($this->mirrorPath);

            $thumbHeight = $this->thumbHeight($video);
            $perSprite = self::SPRITE_COLUMNS * self::SPRITE_ROWS;
            $totalThumbs = max(1, (int) ceil($video->duration / self::THUMBNAIL_INTERVAL));

            $tmpDir = Storage::disk('tmp')->path($video->ulid);
            if (! is_dir($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }

            $video->heartbeat();

            $spriteCount = app(ThumbnailService::class)->generateStoryboardSprites(
                inputPath: $sourceUrl,
                outputPattern: "{$tmpDir}/storyboard_%d.jpg",
                interval: self::THUMBNAIL_INTERVAL,
                columns: self::SPRITE_COLUMNS,
                rows: self::SPRITE_ROWS,
                thumbWidth: self::THUMB_WIDTH,
                thumbHeight: $thumbHeight,
                quality: self::FFMPEG_QUALITY,
                timeout: self::FFMPEG_TIMEOUT,
            );

            $vtt = $this->buildVtt($totalThumbs, $perSprite, $spriteCount, $thumbHeight);
            file_put_contents("{$tmpDir}/storyboard.vtt", $vtt);

            $this->uploadArtifacts($video, $spriteCount);
        } catch (Throwable $e) {
            $this->reportFailure($e);
        }
    }

    private function getVideo(): Video
    {
        $video = Video::find($this->videoId);

        if (! $video) {
            throw new Exception("Video {$this->videoId} not found");
        }

        return $video;
    }

    private function thumbHeight(Video $video): int
    {
        $aspectRatio = $this->parseAspectRatio($video->aspect_ratio);
        $height = (int) (self::THUMB_WIDTH / $aspectRatio);

        return $height + ($height % 2); // ffmpeg needs an even height
    }

    private function parseAspectRatio(?string $aspectRatio): float
    {
        if ($aspectRatio && str_contains($aspectRatio, ':')) {
            [$w, $h] = explode(':', $aspectRatio);

            if ((float) $h > 0) {
                return (float) $w / (float) $h;
            }
        }

        return self::DEFAULT_ASPECT_RATIO;
    }

    /** Cues beyond the sheets ffmpeg actually produced are skipped. */
    private function buildVtt(int $totalThumbs, int $perSprite, int $spriteCount, int $thumbHeight): string
    {
        $vtt = "WEBVTT\n\n";

        for ($i = 0; $i < $totalThumbs; $i++) {
            $sprite = intdiv($i, $perSprite);

            if ($sprite >= $spriteCount) {
                break;
            }

            $cell = $i % $perSprite;
            $x = ($cell % self::SPRITE_COLUMNS) * self::THUMB_WIDTH;
            $y = intdiv($cell, self::SPRITE_COLUMNS) * $thumbHeight;

            $start = $this->formatVttTime($i * self::THUMBNAIL_INTERVAL);
            $end = $this->formatVttTime(($i + 1) * self::THUMBNAIL_INTERVAL);

            $vtt .= "{$start} --> {$end}\n";
            $vtt .= "storyboard_{$sprite}.jpg#xywh={$x},{$y},".self::THUMB_WIDTH.",{$thumbHeight}\n\n";
        }

        return $vtt;
    }

    private function formatVttTime(int $seconds): string
    {
        return gmdate('H:i:s', $seconds).'.000';
    }

    private function uploadArtifacts(Video $video, int $spriteCount): void
    {
        $names = ['storyboard.vtt'];
        for ($i = 0; $i < $spriteCount; $i++) {
            $names[] = "storyboard_{$i}.jpg";
        }

        $tmp = Storage::disk('tmp');
        $mirror = Storage::disk('chunks');

        foreach ($names as $name) {
            $localPath = $tmp->path("{$video->ulid}/{$name}");

            $handle = fopen($localPath, 'r');
            try {
                $mirror->writeStream($video->stagingKey($name), $handle);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                @unlink($localPath);
            }
        }
    }

    private function reportFailure(Throwable $e): void
    {
        Log::warning("Storyboard generation failed for video {$this->videoId}: {$e->getMessage()}");
    }
}
