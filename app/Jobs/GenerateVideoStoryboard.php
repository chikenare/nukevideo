<?php

namespace App\Jobs;

use App\Models\Video;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateVideoStoryboard implements ShouldQueue
{
    use Batchable, Queueable;

    // Configuration constants
    private const THUMBNAIL_INTERVAL = 10; // seconds

    private const THUMBS_PER_SPRITE = 100;

    private const SPRITE_COLUMNS = 10;

    private const SPRITE_ROWS = 10;

    private const THUMB_WIDTH = 160;

    private const DEFAULT_ASPECT_RATIO = 1.777; // 16:9

    private const FFMPEG_QUALITY = 3;

    private const FFMPEG_TIMEOUT = 600; // 10 minutes

    public function __construct(
        private int $videoId,
        private string $originalPath,
    ) {}

    public function handle(): void
    {
        Log::info('GenerateStoryboard started', ['video' => $this->videoId]);

        try {
            $video = $this->getVideo();
            $inputLocalPath = $this->validateAndGetLocalPath();

            $config = $this->calculateStoryboardConfig($video);
            $sprites = $this->generateSprites($video, $inputLocalPath, $config);
            $vttContent = $this->generateVtt($config['totalThumbs'], $config['thumbWidth'], $config['thumbHeight'], $sprites);

            $vttLocalPath = Storage::disk('tmp')->path("{$video->ulid}/storyboard.vtt");
            file_put_contents($vttLocalPath, $vttContent);
        } catch (Throwable $e) {
            Log::warning("Storyboard generation failed for video {$this->videoId}: {$e->getMessage()}");

            $video = Video::with('user')->find($this->videoId);

            if ($video) {
                activity('video')
                    ->performedOn($video)
                    ->causedBy($video->user)
                    ->withProperties(['error' => $e->getMessage()])
                    ->event('storyboard_failed')
                    ->log("Storyboard generation failed: {$e->getMessage()}");
            }
        }
    }

    private function getVideo(): Video
    {
        $video = Video::find($this->videoId);

        if (! $video) {
            Log::error('Video not found for storyboard generation', ['video' => $this->videoId]);
            throw new Exception("Video {$this->videoId} not found");
        }

        return $video;
    }

    private function validateAndGetLocalPath(): string
    {
        $inputLocalPath = Storage::disk('tmp')->path($this->originalPath);

        if (! file_exists($inputLocalPath)) {
            Log::error('Local file not found for storyboard generation', ['path' => $inputLocalPath, 'video' => $this->videoId]);
            throw new Exception("Local file $inputLocalPath not found. File should have been downloaded before processing.");
        }

        return $inputLocalPath;
    }

    private function calculateStoryboardConfig(Video $video): array
    {
        $aspectRatio = $this->parseAspectRatio($video->aspect_ratio);
        $thumbHeight = (int) (self::THUMB_WIDTH / $aspectRatio);
        $thumbHeight = $thumbHeight + ($thumbHeight % 2); // ensure even

        $totalThumbs = (int) ceil($video->duration / self::THUMBNAIL_INTERVAL);
        $totalSprites = (int) ceil($totalThumbs / self::THUMBS_PER_SPRITE);

        return [
            'totalThumbs' => $totalThumbs,
            'thumbWidth' => self::THUMB_WIDTH,
            'thumbHeight' => $thumbHeight,
            'totalSprites' => $totalSprites,
        ];
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

    private function generateSprites(Video $video, string $inputLocalPath, array $config): array
    {
        $sprites = [];

        for ($spriteIndex = 0; $spriteIndex < $config['totalSprites']; $spriteIndex++) {
            $spriteInfo = $this->calculateSpriteInfo($spriteIndex, $config['totalThumbs']);
            $spriteName = "storyboard_{$spriteIndex}.jpg";

            $this->generateSpriteImage(
                $video,
                $inputLocalPath,
                $spriteName,
                $spriteInfo['startTime'],
                $spriteInfo['duration'],
                $config['thumbWidth'],
                $config['thumbHeight'],
            );

            $sprites[] = [
                'filename' => $spriteName,
                'startThumb' => $spriteInfo['startThumb'],
                'endThumb' => $spriteInfo['endThumb'],
            ];
        }

        return $sprites;
    }

    private function calculateSpriteInfo(int $spriteIndex, int $totalThumbs): array
    {
        $startThumb = $spriteIndex * self::THUMBS_PER_SPRITE;
        $endThumb = min(($spriteIndex + 1) * self::THUMBS_PER_SPRITE, $totalThumbs);
        $thumbsInThisSprite = $endThumb - $startThumb;

        return [
            'startThumb' => $startThumb,
            'endThumb' => $endThumb,
            'startTime' => $startThumb * self::THUMBNAIL_INTERVAL,
            'duration' => $thumbsInThisSprite * self::THUMBNAIL_INTERVAL,
        ];
    }

    private function generateSpriteImage(
        Video $video,
        string $inputLocalPath,
        string $spriteName,
        int $startTime,
        int $duration,
        int $thumbWidth,
        int $thumbHeight,
    ): void {
        $spriteLocalPath = Storage::disk('tmp')->path("{$video->ulid}/{$spriteName}");

        $command = [
            'ffmpeg',
            '-ss',
            (string) $startTime,
            '-t',
            (string) $duration,
            '-y',
            '-i',
            $inputLocalPath,
            '-vf',
            'fps=1/'.self::THUMBNAIL_INTERVAL.",scale={$thumbWidth}:{$thumbHeight},tile=".self::SPRITE_COLUMNS.'x'.self::SPRITE_ROWS,
            '-q:v',
            (string) self::FFMPEG_QUALITY,
            $spriteLocalPath,
        ];

        $process = Process::timeout(self::FFMPEG_TIMEOUT)->run($command);

        if (! $process->successful()) {
            throw new Exception('FFmpeg storyboard failed: '.$process->errorOutput());
        }

        if (! file_exists($spriteLocalPath)) {
            throw new Exception("Sprite file not generated: $spriteLocalPath");
        }
    }

    private function generateVtt(int $totalThumbs, int $thumbWidth, int $thumbHeight, array $sprites): string
    {
        $vtt = "WEBVTT\n\n";

        for ($i = 0; $i < $totalThumbs; $i++) {
            $start = $this->formatVttTime($i * self::THUMBNAIL_INTERVAL);
            $end = $this->formatVttTime(($i + 1) * self::THUMBNAIL_INTERVAL);

            $spriteInfo = $this->findSpriteForThumbnail($i, $sprites);

            if (! $spriteInfo) {
                continue;
            }

            $coordinates = $this->calculateThumbnailCoordinates($i, $spriteInfo, $thumbWidth, $thumbHeight);

            $vtt .= "{$start} --> {$end}\n";
            $vtt .= "{$spriteInfo['filename']}#xywh={$coordinates['x']},{$coordinates['y']},{$coordinates['w']},{$coordinates['h']}\n\n";
        }

        return $vtt;
    }

    private function findSpriteForThumbnail(int $thumbnailIndex, array $sprites): ?array
    {
        foreach ($sprites as $sprite) {
            if ($thumbnailIndex >= $sprite['startThumb'] && $thumbnailIndex < $sprite['endThumb']) {
                return $sprite;
            }
        }

        return null;
    }

    private function calculateThumbnailCoordinates(int $thumbnailIndex, array $spriteInfo, int $thumbWidth, int $thumbHeight): array
    {
        $indexInSprite = $thumbnailIndex - $spriteInfo['startThumb'];
        $column = $indexInSprite % self::SPRITE_COLUMNS;
        $row = floor($indexInSprite / self::SPRITE_COLUMNS);

        return [
            'x' => $column * $thumbWidth,
            'y' => $row * $thumbHeight,
            'w' => $thumbWidth,
            'h' => $thumbHeight,
        ];
    }

    private function formatVttTime(int|float $seconds): string
    {
        return gmdate('H:i:s.v', (int) ($seconds * 1000) / 1000);
    }
}
