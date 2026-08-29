<?php

/**
 * The wide shape: the pivot and the zero fill a chart needs, and that every consumer would
 * otherwise write again in its own language.
 *
 * Neither is presentation — no labels, no formats, no colours reach this class. What it does is the
 * part the client cannot do well on its own: turn one row per metric into one field per series, and
 * put back the days ClickHouse omitted because nothing happened on them. A line drawn straight over
 * a missing day interpolates traffic that never occurred.
 *
 * A Feature test despite touching no HTTP and no database: `tests/Unit` runs without the Laravel
 * application (see `tests/Pest.php`, which extends TestCase in `Feature` only), and the row cap
 * refuses through a ValidationException, which needs the container to build its validator.
 */

use App\Data\Analytics\MetricsQueryData;
use App\Enums\MetricDimension;
use App\Services\MetricShaper;
use Illuminate\Validation\ValidationException;

it('pivots the metric out into one field per series', function () {
    $wide = (new MetricShaper)->wide([
        ['date' => '2026-04-01', 'metric' => 'streaming_bytes', 'value' => 100.0],
        ['date' => '2026-04-01', 'metric' => 'download_bytes', 'value' => 7.0],
    ], [MetricDimension::DATE, MetricDimension::METRIC], '2026-04-01', '2026-04-01');

    expect($wide)->toBe([
        ['date' => '2026-04-01', 'download_bytes' => 7.0, 'streaming_bytes' => 100.0],
    ]);
});

it('fills the days that produced nothing with zero', function () {
    // The whole reason this exists: ClickHouse omits 04-02 entirely, and a chart would draw a
    // straight line from the 1st to the 3rd across traffic that did not happen.
    $wide = (new MetricShaper)->wide([
        ['date' => '2026-04-01', 'metric' => 'streaming_bytes', 'value' => 100.0],
        ['date' => '2026-04-03', 'metric' => 'streaming_bytes', 'value' => 300.0],
    ], [MetricDimension::DATE, MetricDimension::METRIC], '2026-04-01', '2026-04-03');

    expect($wide)->toBe([
        ['date' => '2026-04-01', 'streaming_bytes' => 100.0],
        ['date' => '2026-04-02', 'streaming_bytes' => 0.0],
        ['date' => '2026-04-03', 'streaming_bytes' => 300.0],
    ]);
});

it('fills each series separately without inventing series', function () {
    // A gap INSIDE a series that exists is filled. A tracking id with no traffic anywhere in the
    // range gets no rows at all — absence is the answer everywhere else in this API, and padding a
    // thousand-id request with a month of zeros each would be almost entirely filler.
    $wide = (new MetricShaper)->wide([
        ['tracking_id' => 'a', 'date' => '2026-04-01', 'metric' => 'streaming_bytes', 'value' => 1.0],
        ['tracking_id' => 'b', 'date' => '2026-04-02', 'metric' => 'streaming_bytes', 'value' => 2.0],
    ], [MetricDimension::TRACKING_ID, MetricDimension::DATE, MetricDimension::METRIC], '2026-04-01', '2026-04-02');

    expect($wide)->toBe([
        ['tracking_id' => 'a', 'date' => '2026-04-01', 'streaming_bytes' => 1.0],
        ['tracking_id' => 'a', 'date' => '2026-04-02', 'streaming_bytes' => 0.0],
        ['tracking_id' => 'b', 'date' => '2026-04-01', 'streaming_bytes' => 0.0],
        ['tracking_id' => 'b', 'date' => '2026-04-02', 'streaming_bytes' => 2.0],
    ]);
});

it('keeps a single value column when the metric is not a dimension', function () {
    $wide = (new MetricShaper)->wide([
        ['date' => '2026-04-02', 'value' => 50.0],
    ], [MetricDimension::DATE], '2026-04-01', '2026-04-02');

    expect($wide)->toBe([
        ['date' => '2026-04-01', 'value' => 0.0],
        ['date' => '2026-04-02', 'value' => 50.0],
    ]);
});

it('emits a column only for metrics that actually have data', function () {
    // Read off the rows, not off the request: a caller that named four metrics and has traffic
    // under one should not get three columns of zeros. A missing key already means zero.
    $wide = (new MetricShaper)->wide([
        ['date' => '2026-04-01', 'metric' => 'asset_bytes', 'value' => 9.0],
    ], [MetricDimension::DATE, MetricDimension::METRIC], '2026-04-01', '2026-04-01');

    expect(array_keys($wide[0]))->toBe(['date', 'asset_bytes']);
});

it('refuses to fill a range into more rows than the cap', function () {
    // Filling multiplies the response by the length of the range, so it has to be able to say no
    // rather than build a response nobody can use.
    $rows = [];
    for ($i = 0; $i < 200; $i++) {
        $rows[] = ['tracking_id' => "id-{$i}", 'date' => '2026-01-01', 'metric' => 'streaming_bytes', 'value' => 1.0];
    }

    expect(fn () => (new MetricShaper)->wide(
        $rows,
        [MetricDimension::TRACKING_ID, MetricDimension::DATE, MetricDimension::METRIC],
        '2026-01-01',
        '2026-12-31',
    ))->toThrow(ValidationException::class);
})->skip(fn () => 200 * 365 <= MetricsQueryData::MAX_ROWS, 'the cap is above what this case builds');
