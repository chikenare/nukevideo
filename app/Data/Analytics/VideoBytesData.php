<?php

namespace App\Data\Analytics;

use App\Enums\UsageMetric;
use App\Services\AnalyticsService;
use Spatie\LaravelData\Data;

/**
 * One (video, delivery metric) total from the per-title batch read
 * ({@see AnalyticsService::bytesByVideos()}). A ULID that is not the caller's project's, and one
 * that moved nothing in the range, both produce no row — deliberately the same answer, so the
 * endpoint cannot be used to find out which videos exist.
 */
class VideoBytesData extends Data
{
    public function __construct(
        public string $video,
        /** The delivery metric these bytes were served under, from {@see UsageMetric::delivery()}. */
        public string $metric,
        /** Bytes served. A `Float64` sum in ClickHouse, so it is a number and not an integer type. */
        public float $bytes,
        /** The day, on a daily read. Null when the row is the total for the whole range. */
        public ?string $date = null,
    ) {}
}
