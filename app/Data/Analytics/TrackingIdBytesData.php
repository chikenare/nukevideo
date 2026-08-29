<?php

namespace App\Data\Analytics;

use App\Enums\UsageMetric;
use App\Services\AnalyticsService;
use Spatie\LaravelData\Data;

/**
 * One (tracking id, delivery metric) total from the batch read
 * ({@see AnalyticsService::bytesByTrackingIds()}). An id with traffic under two metrics gets two
 * rows; an id with no traffic in the range gets none.
 */
class TrackingIdBytesData extends Data
{
    public function __construct(
        /**
         * One of the ids the request named, or the empty id — the traffic whose id did not survive
         * the CDN log — when `include_unattributed` asked for it.
         */
        public string $trackingId,
        /** The delivery metric these bytes were served under, from {@see UsageMetric::delivery()}. */
        public string $metric,
        /** Bytes served. A `Float64` sum in ClickHouse, so it is a number and not an integer type. */
        public float $bytes,
        /** The day, on a daily read. Null when the row is the total for the whole range. */
        public ?string $date = null,
    ) {}
}
