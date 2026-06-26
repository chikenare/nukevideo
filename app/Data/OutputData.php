<?php

namespace App\Data;

use App\Models\Output;
use Spatie\LaravelData\Data;

class OutputData extends Data
{
    public function __construct(
        public string $ulid,
        public string $format,
        public string $status,
        public int $progress,
        /** @var StreamData[] */
        public array $streams,
        public string $createdAt,
    ) {}

    public static function fromModel(Output $output): self
    {
        return new self(
            ulid: $output->ulid,
            format: $output->format->value,
            status: $output->status->value,
            progress: $output->progress(),
            streams: StreamData::collect($output->streams)->all(),
            createdAt: $output->created_at->toIso8601String(),
        );
    }
}
