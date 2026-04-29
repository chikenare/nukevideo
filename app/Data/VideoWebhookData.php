<?php

namespace App\Data;

use App\Models\Video;
use Spatie\LaravelData\Data;

class VideoWebhookData extends Data
{
    public function __construct(
        public string $ulid,
        public string $project,
        public string $name,
        public string $status,
        public string $duration,
        public string $aspectRatio,
        public ?string $externalUserId,
        public ?string $externalResourceId,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    public static function fromModel(Video $video)
    {
        return new self(
            $video->ulid,
            $video->project?->ulid,
            $video->name,
            $video->status,
            $video->duration,
            $video->aspect_ratio,
            $video->external_user_id,
            $video->external_resource_id,
            $video->created_at->toIso8601String(),
            $video->updated_at->toIso8601String(),
        );

    }
}
