<?php

/**
 * The general read over `usage`: any breakdown the allowlist permits, rather than a new endpoint
 * per question.
 *
 * A query endpoint over an instance-wide table is only defensible because of what these cases pin.
 * The dimensions of `usage` are not equally shareable, and each one carries its own requirement:
 * the operator's fleet columns need an operator; the video and viewer-address columns need a
 * resolved project and an explicit list of titles the caller owns; the customer-label column pins
 * the whole query to the caller's account; and `tracking_id`, which nothing in the table can attach
 * an owner to, may only be read for ids the caller can name.
 *
 * The service is stubbed — the suite has no ClickHouse fixture — so what is under test is the
 * authorization and the shaping, which is where this endpoint can go wrong.
 */

use App\Models\Project;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

const METRICS_ENDPOINT = '/api/metrics';

/** Stubs the service and hands back the arguments the query was built with. */
function stubMetricsQuery(array $rows = []): ArrayObject
{
    $seen = new ArrayObject;

    app()->instance(AnalyticsService::class, Mockery::mock(AnalyticsService::class, function (MockInterface $mock) use ($seen, $rows) {
        $mock->shouldReceive('query')->andReturnUsing(function (...$args) use ($seen, $rows) {
            $seen['args'] = $args;

            return $rows;
        });
    }));

    return $seen;
}

beforeEach(function () {
    $this->owner = User::factory()->create(['is_admin' => false]);
    $this->project = Project::factory()->for($this->owner)->create();

    Sanctum::actingAs($this->owner);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('breaks delivery down by the dimensions asked for', function () {
    $seen = stubMetricsQuery([
        ['date' => '2026-04-16', 'metric' => 'streaming_bytes', 'value' => 1024.0],
    ]);

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['date', 'metric'],
        'tracking_ids' => ['customer-42'],
    ])->assertOk()
        ->assertJsonPath('data.0.date', '2026-04-16')
        ->assertJsonPath('data.0.value', 1024);

    // from, to, dimensions, filters, metrics, accountId — delivery only, so no account pin.
    expect($seen['args'][3]['tracking_id'])->toBe(['customer-42'])
        ->and($seen['args'][5])->toBeNull();
});

it('pins the query to the caller account as soon as it touches customer labels', function () {
    // `external_user_id` IS the integrator's own customer identifier. Instance-wide it would hand
    // one tenant another's customers, which is the leak this whole allowlist exists to prevent.
    $seen = stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['external_user_id'],
    ])->assertOk();

    expect($seen['args'][5])->toBe($this->owner->id);
});

it('pins the query to the account for metrics that are booked per account', function () {
    // Upload volume and encoding seconds are the account's, not a delivery's.
    $seen = stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['date'],
        'metrics' => ['upload_bytes', 'encoding_cpu'],
    ])->assertOk();

    expect($seen['args'][5])->toBe($this->owner->id)
        ->and($seen['args'][4])->toBe(['upload_bytes', 'encoding_cpu']);
});

it('resolves a project key to its owning account, not to the project id', function () {
    $key = app(ApiTokenService::class)->regenerateProjectKey($this->project)->plainTextToken;
    auth()->forgetGuards();

    $seen = stubMetricsQuery();

    $this->withToken($key)->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['external_user_id'],
    ])->assertOk();

    expect($seen['args'][5])->toBe($this->owner->id)
        ->and($seen['args'][5])->not->toBe($this->project->id);
});

it('narrows a video list to the titles the project owns', function () {
    $mine = projectVideo($this->project);
    $theirs = projectVideo(Project::factory()->for(User::factory()->create())->create(), 'theirs');

    $seen = stubMetricsQuery();

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['video'],
        'videos' => [$mine->ulid, $theirs->ulid],
    ])->assertOk();

    expect($seen['args'][3]['video_ulid'])->toBe([$mine->ulid]);
});

it('answers nothing rather than instance-wide when no named video is the caller\'s', function () {
    // The dangerous case: an ownership filter that empties out must not fall through to "no filter".
    $theirs = projectVideo(Project::factory()->for(User::factory()->create())->create(), 'theirs');
    $seen = stubMetricsQuery([['video' => 'x', 'value' => 1.0]]);

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['video'],
        'videos' => [$theirs->ulid],
    ])->assertOk()->assertExactJson(['data' => []]);

    expect($seen)->not->toHaveKey('args');
});

it('refuses the identifier dimensions unless the caller says what it is asking about', function (array $payload, string $field) {
    $this->postJson(METRICS_ENDPOINT, $payload + ['from' => '2026-04-01', 'to' => '2026-04-30'])
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    // Each of these, unbounded, enumerates something belonging to other tenants.
    'videos, which would list every title on the instance' => [['dimensions' => ['video']], 'videos'],
    'viewer addresses, which are personal data' => [['dimensions' => ['ip']], 'videos'],
    'viewer labels, which nothing can attach an owner to' => [['dimensions' => ['tracking_id']], 'tracking_ids'],
]);

it('refuses the operator fleet dimensions to a tenant', function (string $dimension) {
    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => [$dimension],
    ])->assertStatus(422)->assertJsonValidationErrors('dimensions');
})->with(['node_id', 'cache']);

it('lets an operator read the instance without naming anything', function () {
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    $seen = stubMetricsQuery();

    // Without clearing it, the beforeEach header still names the tenant's project, which this
    // operator does not own — ResolveProject would 404 before the query was ever considered.
    $this->withHeader('X-Project-Ulid', '')->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['node_id', 'cache', 'ip', 'tracking_id'],
    ])->assertOk();

    // No account pin, no list required: the operator's own fleet and its own instance.
    expect($seen['args'][5])->toBeNull();
});

it('demands project context for a dimension that can only be answered inside one', function () {
    $video = projectVideo($this->project);

    $this->withHeader('X-Project-Ulid', '')->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['video'],
        'videos' => [$video->ulid],
    ])->assertStatus(400);
});

it('needs no project context for a query that names no project data', function () {
    stubMetricsQuery();

    $this->withHeader('X-Project-Ulid', '')->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['date', 'metric'],
        'tracking_ids' => ['customer-42'],
    ])->assertOk();
});

it('rejects a query it could not answer honestly', function (array $payload) {
    $this->postJson(METRICS_ENDPOINT, $payload + ['from' => '2026-04-01', 'to' => '2026-04-30'])->assertStatus(422);
})->with([
    'no dimensions' => [['dimensions' => []]],
    'a dimension that is not a column' => [['dimensions' => ['user_agent']]],
    'a metric that does not exist' => [['dimensions' => ['date'], 'metrics' => ['made_up']]],
    'a video that is not a ULID' => [['dimensions' => ['date'], 'videos' => ['not-a-ulid']]],
    'a tracking id outside the alphabet' => [['dimensions' => ['date'], 'tracking_ids' => ['bad id']]],
    'a shape that is not one of the two' => [['dimensions' => ['date'], 'shape' => 'pivoted']],
]);

it('still refuses a query to an unauthenticated caller', function () {
    auth()->forgetGuards();

    $this->postJson(METRICS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'dimensions' => ['date'],
    ])->assertUnauthorized();
});
