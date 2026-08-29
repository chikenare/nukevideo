<?php

/**
 * The batch bandwidth read: delivered bytes for a list of tracking ids the caller names, split by
 * delivery metric. It exists so an integrator can bill a per-subscriber bandwidth quota without
 * one HTTP call and one full `AnalyticsData` per subscriber per period.
 *
 * Two things are pinned here, in two halves.
 *
 * The endpoint half stubs the service — the suite has no ClickHouse fixture and CI does not run
 * one — and covers who gets through the door, what the request may contain and what comes back.
 *
 * The query half stubs the ClickHouse client instead and reads the statement the service builds.
 * That is not a fake standing in for the database: it asserts the two properties that would fail
 * silently and expensively if they regressed — that the id list is a bound `Array(String)`
 * parameter and never text in the SQL, and that the metric constraint is still there, without
 * which the sum would add encoding seconds and upload volume to delivered bytes and invoice the
 * result.
 */

use App\Data\Analytics\TrackingIdBytesQueryData;
use App\Enums\UsageMetric;
use App\Models\Project;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ApiTokenService;
use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

const BATCH_ENDPOINT = '/api/analytics/tracking-ids';

const BATCH_ROWS = [
    ['tracking_id' => 'customer-42', 'metric' => 'streaming_bytes', 'bytes' => 943718400.0],
    ['tracking_id' => 'customer-42', 'metric' => 'download_bytes', 'bytes' => 52428800.0],
    ['tracking_id' => 'reupload-9f1c', 'metric' => 'download_bytes', 'bytes' => 7.0],
];

/**
 * Stubs the service and hands back the arguments it was called with, so a case can assert that the
 * request reached the query intact. An ArrayObject, not an array: a returned array is a copy, and
 * the stub would be writing to one the caller never sees.
 */
function stubBatchService(array $rows = BATCH_ROWS): ArrayObject
{
    $seen = new ArrayObject;

    // `app()->instance`, not the bare `mock()` helper: Mockery ships a global function of that name
    // that builds a mock and never binds it, so the controller would keep resolving the real
    // service.
    app()->instance(AnalyticsService::class, Mockery::mock(AnalyticsService::class, function (MockInterface $mock) use ($seen, $rows) {
        $mock->shouldReceive('bytesByTrackingIds')->andReturnUsing(function (...$args) use ($seen, $rows) {
            $seen['args'] = $args;

            return $rows;
        });
    }));

    return $seen;
}

it('reports bytes for a batch of tracking ids, split by metric', function () {
    stubBatchService();
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&tracking_ids[]=customer-42&tracking_ids[]=reupload-9f1c')
        ->assertOk()
        // Two rows for one id, because streaming and the downloads that reupload to a viewer's own
        // file host are not the same line on an invoice. A caller that wants one number adds them.
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.trackingId', 'customer-42')
        ->assertJsonPath('data.0.metric', 'streaming_bytes')
        ->assertJsonPath('data.0.bytes', 943718400)
        ->assertJsonPath('data.2.trackingId', 'reupload-9f1c');
});

it('passes the range, the whole list and the metric into the query', function () {
    $seen = stubBatchService();
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&tracking_ids[]=a&tracking_ids[]=b&metric=download_bytes')
        ->assertOk();

    expect($seen['args'])->toBe(['2026-04-01', '2026-04-30', ['a', 'b'], 'download_bytes', false]);
});

it('omits ids with no traffic rather than answering with zeros', function () {
    // The service returns nothing for an id that moved no bytes in the range, and the endpoint
    // passes that through: absence is the answer. A caller reading a total for an id it does not
    // find in the response is looking at zero.
    stubBatchService([['tracking_id' => 'customer-42', 'metric' => 'streaming_bytes', 'bytes' => 12.0]]);
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&tracking_ids[]=customer-42&tracking_ids[]=quiet-one')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['trackingId' => 'quiet-one']);
});

it('accepts the same batch in a POST body', function () {
    // The reason the route takes POST at all: a thousand 64-character ids is roughly 80 KB of
    // query string, past what most proxies will accept in a request line. It still only reads.
    $seen = stubBatchService();
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->postJson(BATCH_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'tracking_ids' => ['a', 'b'],
    ])->assertOk();

    expect($seen['args'])->toBe(['2026-04-01', '2026-04-30', ['a', 'b'], null, false]);
});

it('lets a project API key read a batch', function () {
    // The whole point: this is consumed server to server by the backend that minted the ids, which
    // holds a project key and not a user session. The action reads no `$request->user()`, which
    // for a project key is a Project and not a User.
    stubBatchService();
    $project = Project::factory()->for(User::factory()->create(['is_admin' => false]))->create();
    $key = app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;

    $this->withToken($key)
        ->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&tracking_ids[]=customer-42')
        ->assertOk();
});

it('still refuses a batch to an unauthenticated caller', function () {
    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&tracking_ids[]=customer-42')
        ->assertUnauthorized();
});

it('rejects a batch it could not answer honestly', function (string|array $query) {
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    is_array($query)
        ? $this->postJson(BATCH_ENDPOINT, $query)->assertStatus(422)
        : $this->getJson(BATCH_ENDPOINT."?from=2026-04-01&to=2026-04-30&{$query}")->assertStatus(422);
})->with([
    'no list at all' => 'metric=download_bytes',
    'an empty list' => 'tracking_ids=',
    'an id outside the URL-safe alphabet' => 'tracking_ids[]=customer%2042',
    'an id with a dot, which the ingest never kept' => 'tracking_ids[]=customer.42',
    'an id past the column width' => 'tracking_ids[]='.str_repeat('a', 65),
    // Neither reading of an absent id survives a batch: null would reach the Array(String) binding
    // as `\N`, and '' is the bucket for traffic that carried no id, which nobody named here.
    'a null element' => [['from' => '2026-04-01', 'to' => '2026-04-30', 'tracking_ids' => ['ok', null]]],
    'an empty element' => [['from' => '2026-04-01', 'to' => '2026-04-30', 'tracking_ids' => ['ok', '']]],
    'more ids than one call may name' => [[
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'tracking_ids' => array_fill(0, TrackingIdBytesQueryData::MAX_BATCH + 1, 'a'),
    ]],
    // The same table stores upload volume and encoding seconds in the same `value` column.
    'a metric that is not a delivery metric' => 'tracking_ids[]=a&metric=encoding_cpu',
    'a metric that does not exist' => 'tracking_ids[]=a&metric=made_up',
    'a granularity that is not one of the two' => 'tracking_ids[]=a&granularity=hourly',
    'a range with no start' => [['to' => '2026-04-30', 'tracking_ids' => ['a']]],
    'a start that is not a date' => 'tracking_ids[]=a&from=last-tuesday',
]);

it('adds the day to the breakdown when asked for a daily read', function () {
    $seen = stubBatchService([['tracking_id' => 'customer-42', 'metric' => 'streaming_bytes', 'bytes' => 12.0, 'date' => '2026-04-16']]);
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&tracking_ids[]=customer-42&granularity=daily')
        ->assertOk()
        ->assertJsonPath('data.0.date', '2026-04-16');

    expect($seen['args'][4])->toBeTrue();
});

it('leaves the date null on a total read', function () {
    // The property exists either way, so a consumer's type does not change shape with the query;
    // null is what says "this row is the whole range".
    stubBatchService([['tracking_id' => 'customer-42', 'metric' => 'streaming_bytes', 'bytes' => 12.0]]);
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&tracking_ids[]=customer-42')
        ->assertOk()
        ->assertJsonPath('data.0.date', null);
});

it('asks for the unattributed bucket only when the flag says so', function () {
    // The empty id is the column default — traffic whose id did not survive the CDN log. It is
    // rejected as a caller-named id, so the flag is the only way in, and it is how a caller
    // reconciles the sum of its own ids against the total.
    $seen = stubBatchService();
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&tracking_ids[]=a&include_unattributed=1')->assertOk();
    expect($seen['args'][2])->toBe(['a', '']);

    $this->getJson(BATCH_ENDPOINT.'?from=2026-04-01&to=2026-04-30&tracking_ids[]=a')->assertOk();
    expect($seen['args'][2])->toBe(['a']);
});

/**
 * Stubs the ClickHouse client and hands back what the service asked it to run. `select()` is
 * declared and forbidden: it routes bindings through the Bindings degeneration, whose URL
 * parameters go through `http_build_query`, so a list would arrive as `param_in_tracking_id[0]=…`
 * and ClickHouse would never see the parameter at all.
 */
function captureBatchQuery(): ArrayObject
{
    $captured = new ArrayObject(['calls' => 0]);

    $statement = Mockery::mock(Statement::class);
    $statement->shouldReceive('rows')->andReturn([]);

    app()->instance(Client::class, Mockery::mock(Client::class, function (MockInterface $mock) use ($captured, $statement) {
        $mock->shouldReceive('selectWithParams')->andReturnUsing(function (string $sql, array $params) use ($captured, $statement) {
            $captured['sql'] = $sql;
            $captured['params'] = $params;
            $captured['calls'] = $captured['calls'] + 1;

            return $statement;
        });

        $mock->shouldReceive('select')->never();
    }));

    return $captured;
}

it('binds the id list as a ClickHouse array parameter, never as SQL text', function () {
    $captured = captureBatchQuery();

    app(AnalyticsService::class)->bytesByTrackingIds('2026-04-01', '2026-04-30', ["o'brien", 'customer-42']);

    expect($captured['params']['in_tracking_id'])->toBe(["o'brien", 'customer-42'])
        ->and($captured['sql'])->toContain('tracking_id IN {in_tracking_id:Array(String)}')
        // These ids are the integrator's own labels, but they arrive over HTTP like anything else.
        ->and($captured['sql'])->not->toContain("o'brien")
        ->and($captured['params'])->toMatchArray(['from' => '2026-04-01', 'to' => '2026-04-30']);
});

it('constrains the batch to the delivery metrics and groups by both dimensions', function () {
    $captured = captureBatchQuery();

    app(AnalyticsService::class)->bytesByTrackingIds('2026-04-01', '2026-04-30', ['customer-42']);

    expect($captured['sql'])
        // Without this the sum would add `encoding_cpu` seconds and `upload_bytes` to delivered
        // bytes and return a number that means nothing — one that would then be invoiced.
        ->toContain("metric IN ('".implode("', '", UsageMetric::delivery())."')")
        ->toContain('GROUP BY tracking_id, metric')
        // The batch IS the filter; the single-id predicate as well would AND them into one id.
        ->not->toContain('tracking_id = {tracking_id:String}');
});

it('narrows the batch to one metric when asked', function () {
    $captured = captureBatchQuery();

    app(AnalyticsService::class)->bytesByTrackingIds('2026-04-01', '2026-04-30', ['customer-42'], 'download_bytes');

    expect($captured['params']['metrics'])->toBe(['download_bytes'])
        ->and($captured['sql'])->toContain('metric IN {metrics:Array(String)}');
});

it('folds duplicate ids and never asks ClickHouse about an empty list', function () {
    $captured = captureBatchQuery();
    $service = app(AnalyticsService::class);

    $service->bytesByTrackingIds('2026-04-01', '2026-04-30', ['a', 'b', 'a']);

    // Duplicates are the caller's list, not a query concern — `IN` folds them anyway — so they are
    // dropped rather than turned into a 422 over something harmless. Keys stay a list, or the
    // binding would serialise as an object.
    expect($captured['params']['in_tracking_id'])->toBe(['a', 'b']);

    expect($service->bytesByTrackingIds('2026-04-01', '2026-04-30', []))->toBe([])
        ->and($captured['calls'])->toBe(1);
});

it('groups by the day as well on a daily read', function () {
    $captured = captureBatchQuery();

    app(AnalyticsService::class)->bytesByTrackingIds('2026-04-01', '2026-04-30', ['customer-42'], daily: true);

    expect($captured['sql'])->toContain('GROUP BY tracking_id, metric, date');
});
