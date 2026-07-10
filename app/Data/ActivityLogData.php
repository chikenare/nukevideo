<?php

namespace App\Data;

use Spatie\Activitylog\Models\Activity;
use Spatie\LaravelData\Data;

class ActivityLogData extends Data
{
    public function __construct(
        public int $id,
        public ?string $logName,
        public string $description,
        public ?string $subjectType,
        public ?int $subjectId,
        public ?string $causerType,
        public ?int $causerId,
        public ?string $event,
        /** @var array<string, mixed> */
        public array $properties,
        public string $createdAt,
        public ?string $updatedAt,
    ) {}

    public static function fromModel(Activity $activity): self
    {
        return new self(
            id: $activity->id,
            logName: $activity->log_name,
            description: $activity->description,
            subjectType: $activity->subject_type,
            subjectId: $activity->subject_id,
            causerType: $activity->causer_type,
            causerId: $activity->causer_id,
            event: $activity->event,
            properties: $activity->properties?->toArray() ?? [],
            createdAt: $activity->created_at->toIso8601String(),
            updatedAt: $activity->updated_at?->toIso8601String(),
        );
    }
}
