<?php

namespace App\Data;

use App\Enums\VideoStatus;
use App\Models\Stream;
use App\Models\Video;
use Spatie\LaravelData\Data;

class VideoData extends Data
{
    public function __construct(
        public string $ulid,
        public string $name,
        public float $duration,
        public string $aspectRatio,
        public VideoStatus $status,
        public string $createdAt,
        public ?string $externalUserId,
        public ?string $externalResourceId,
        public string $thumbnailUrl,
        public string $storyboardUrl,
        /** @var OutputData[] */
        public array $outputs,
        /** @var StreamData[] */
        public array $streams,
        // Total bytes stored on S3 (CMAF packages + kept processed files + the original while it exists).
        public int $size,
        // Playback-only bytes (CMAF packages); `size` minus this is the retained processed/source overhead.
        public int $servedSize,
    ) {}

    public static function fromModel(Video $video): self
    {
        return new self(
            ulid: $video->ulid,
            name: $video->name,
            duration: $video->duration,
            aspectRatio: $video->aspect_ratio,
            status: VideoStatus::from($video->status),
            createdAt: $video->created_at->toIso8601String(),
            externalUserId: $video->external_user_id,
            externalResourceId: $video->external_resource_id,
            thumbnailUrl: url('/api/videos/'.Video::assetPath($video->ulid, Video::THUMBNAIL_FILENAME)),
            storyboardUrl: url('/api/videos/'.Video::assetPath($video->ulid, Video::STORYBOARD_VTT_FILENAME)),
            outputs: OutputData::collect($video->outputs)->all(),
            streams: StreamData::collect($video->streams)->all(),
            size: $video->streams->sum(fn (Stream $s) => (int) $s->package_size + (int) $s->file_size),
            servedSize: $video->streams->sum(fn (Stream $s) => (int) $s->package_size),
        );
    }
}
