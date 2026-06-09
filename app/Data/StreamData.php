<?php

namespace App\Data;

use App\Models\Stream;
use Spatie\LaravelData\Data;

class StreamData extends Data
{
    public function __construct(
        public string $ulid,
        public string $name,
        public string $type,
        public int $size,
        public ?array $inputParams,
        public ?array $meta,
        public string $status,
        public int $progress,
        public ?float $fps,
        public ?int $width,
        public ?int $height,
        public ?string $language,
        public ?int $channels,
        public ?string $startedAt,
        public ?string $completedAt,
        public ?string $errorLog,
        public string $createdAt,
    ) {}

    public static function fromModel(Stream $stream): self
    {
        return new self(
            ulid: $stream->ulid,
            name: $stream->name,
            type: $stream->type,
            size: $stream->size,
            inputParams: $stream->input_params,
            meta: $stream->meta,
            status: $stream->status,
            progress: $stream->progress,
            fps: $stream->fps,
            width: $stream->width,
            height: $stream->height,
            language: $stream->language,
            channels: $stream->channels,
            startedAt: $stream->started_at,
            completedAt: $stream->completed_at,
            errorLog: $stream->error_log,
            createdAt: $stream->created_at->toIso8601String(),
        );
    }
}
