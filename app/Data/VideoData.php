<?php

namespace App\Data;

use App\Models\Video;
use Spatie\LaravelData\Data;

class VideoData extends Data
{
    public function __construct(
        public string $ulid,
        public string $name,
        public float $duration,
        public string $aspectRatio,
        public string $status,
        public string $createdAt,
        public ?string $externalUserId,
        public ?string $externalResourceId,
        public string $thumbnailUrl,
        public string $storyboardUrl,
        /** @var OutputData[] */
        public array $outputs,
        /** @var StreamData[] */
        public array $streams,
        public int $size,
    ) {}

    public static function fromModel(Video $video): self
    {
        return new self(
            ulid: $video->ulid,
            name: $video->name,
            duration: $video->duration,
            aspectRatio: $video->aspect_ratio,
            status: $video->status,
            createdAt: $video->created_at->toIso8601String(),
            externalUserId: $video->external_user_id,
            externalResourceId: $video->external_resource_id,
            thumbnailUrl: url('/api/videos/'.Video::assetPath($video->ulid, Video::THUMBNAIL_FILENAME)),
            storyboardUrl: url('/api/videos/'.Video::assetPath($video->ulid, Video::STORYBOARD_VTT_FILENAME)),
            outputs: OutputData::collect($video->outputs)->all(),
            streams: StreamData::collect($video->streams)->all(),
            size: $video->streams->sum('size'),
        );
    }
}
