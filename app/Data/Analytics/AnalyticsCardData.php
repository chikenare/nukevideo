<?php

namespace App\Data\Analytics;

use App\Enums\MetricUnit;
use Spatie\LaravelData\Data;

/**
 * One headline figure of the dashboard composite.
 *
 * `key` is a stable machine name, never a display string. It used to be an English `label` written
 * in the controller and painted straight onto the panel, which had two costs: every external
 * consumer received one client's UI copy and had to map it away, and the panel could never be
 * translated, because the words were not its own. It also meant the panel string-matched
 * `'Total Bandwidth'` to find the denominator of its percentage columns — rename the label and
 * three tables silently read 0%. Matching on a key cannot rot that way.
 *
 * `unit` says what the number IS, not how to draw it ({@see MetricUnit}).
 */
class AnalyticsCardData extends Data
{
    public function __construct(
        public string $key,
        public float $value,
        public MetricUnit $unit,
    ) {}
}
