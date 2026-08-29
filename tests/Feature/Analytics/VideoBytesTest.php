<?php

/**
 * The per-title batch read: delivered bytes for a list of video ULIDs, split by delivery metric.
 * Same shape as the tracking-id batch on the other dimension, with one difference that is the whole
 * point of the file — this one IS scoped to a project.
 *
 * `usage` has no project column, so none of the other metrics endpoints can be scoped; a video does
 * have one, so the list is narrowed to the caller's own videos before it ever reaches ClickHouse.
 * What is pinned here is that the narrowing actually happens, and that a ULID belonging to someone
 * else is answered exactly like one with no traffic — with nothing — so the endpoint cannot be used
 * to find out which videos exist.
 *
 * The service is stubbed: the suite has no ClickHouse fixture and CI does not run one. What these
 * cases test is the tenant boundary, which is Eloquent's side of the call.
 */

use App\Models\Project;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

const VIDEOS_ENDPOINT = '/api/analytics/videos';

/**
 * Stubs the service and hands back the arguments it was called with — the third of which is the
 * list that survived the ownership filter, which is what every case here is really asking about.
 */
function stubVideoBatchService(array $rows = []): ArrayObject
{
    $seen = new ArrayObject;

    app()->instance(AnalyticsService::class, Mockery::mock(AnalyticsService::class, function (MockInterface $mock) use ($seen, $rows) {
        $mock->shouldReceive('bytesByVideos')->andReturnUsing(function (...$args) use ($seen, $rows) {
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

it('reports bytes for a batch of the project videos, split by metric', function () {
    $video = projectVideo($this->project);
    stubVideoBatchService([
        ['video' => $video->ulid, 'metric' => 'streaming_bytes', 'bytes' => 943718400.0],
        ['video' => $video->ulid, 'metric' => 'download_bytes', 'bytes' => 52428800.0],
    ]);

    $this->getJson(VIDEOS_ENDPOINT."?from=2026-04-01&to=2026-04-30&videos[]={$video->ulid}")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.video', $video->ulid)
        ->assertJsonPath('data.0.metric', 'streaming_bytes')
        ->assertJsonPath('data.0.bytes', 943718400)
        ->assertJsonPath('data.0.date', null);
});

it('never lets a ULID from another project reach the query', function () {
    // The tenant boundary. `usage` cannot enforce it — `video_ulid` there is a string parsed out of
    // a public request path — so it has to be enforced before the query, and this is the case that
    // says it is.
    $mine = projectVideo($this->project);
    $theirs = projectVideo(Project::factory()->for(User::factory()->create())->create(), 'theirs');

    $seen = stubVideoBatchService();

    $this->getJson(VIDEOS_ENDPOINT."?from=2026-04-01&to=2026-04-30&videos[]={$mine->ulid}&videos[]={$theirs->ulid}")
        ->assertOk();

    expect($seen['args'][2])->toBe([$mine->ulid]);
});

it('answers a ULID it does not own exactly like one with no traffic', function () {
    // Both produce no row, on purpose: a 403 or a 404 for the first would turn the endpoint into a
    // way to ask whether a given video exists on the instance.
    $theirs = projectVideo(Project::factory()->for(User::factory()->create())->create(), 'theirs');
    $seen = stubVideoBatchService();

    $this->getJson(VIDEOS_ENDPOINT."?from=2026-04-01&to=2026-04-30&videos[]={$theirs->ulid}")
        ->assertOk()
        ->assertExactJson(['data' => []]);

    // Nothing owned survived the filter, so the service is handed an empty list and short-circuits.
    expect($seen['args'][2])->toBe([]);
});

it('adds the day to the breakdown when asked for a daily read', function () {
    $video = projectVideo($this->project);
    $seen = stubVideoBatchService([
        ['video' => $video->ulid, 'metric' => 'streaming_bytes', 'bytes' => 12.0, 'date' => '2026-04-16'],
    ]);

    $this->getJson(VIDEOS_ENDPOINT."?from=2026-04-01&to=2026-04-30&videos[]={$video->ulid}&granularity=daily")
        ->assertOk()
        ->assertJsonPath('data.0.date', '2026-04-16');

    expect($seen['args'][4])->toBeTrue();
});

it('accepts the same batch in a POST body', function () {
    $video = projectVideo($this->project);
    $seen = stubVideoBatchService();

    $this->postJson(VIDEOS_ENDPOINT, [
        'from' => '2026-04-01',
        'to' => '2026-04-30',
        'videos' => [$video->ulid],
        'metric' => 'streaming_bytes',
    ])->assertOk();

    expect($seen['args'])->toBe(['2026-04-01', '2026-04-30', [$video->ulid], 'streaming_bytes', false]);
});

it('lets a project API key read its own titles', function () {
    $video = projectVideo($this->project);
    stubVideoBatchService();
    $key = app(ApiTokenService::class)->regenerateProjectKey($this->project)->plainTextToken;

    // Drop the session the beforeEach set up, or it would answer this request instead of the key.
    auth()->forgetGuards();

    // A project key authenticates AS the project, so it resolves one without any header — which is
    // how the integrating backend calls this.
    $this->withHeader('X-Project-Ulid', '')
        ->withToken($key)
        ->getJson(VIDEOS_ENDPOINT."?from=2026-04-01&to=2026-04-30&videos[]={$video->ulid}")
        ->assertOk();
});

it('refuses a batch with no project context at all', function () {
    // Unlike its unscoped siblings, this one is behind `resolve.project`: without a project there
    // is nothing to scope the ULIDs to, and answering anyway would mean answering instance-wide.
    $video = projectVideo($this->project);
    stubVideoBatchService();

    $this->withHeader('X-Project-Ulid', '')
        ->getJson(VIDEOS_ENDPOINT."?from=2026-04-01&to=2026-04-30&videos[]={$video->ulid}")
        ->assertStatus(400);
});

it('still refuses a batch to an unauthenticated caller', function () {
    auth()->forgetGuards();

    $this->getJson(VIDEOS_ENDPOINT.'?from=2026-04-01&to=2026-04-30&videos[]=01HZXW3V5N8Q9R2T4Y6B8D0F1G')
        ->assertUnauthorized();
});

it('rejects a batch it could not answer honestly', function (string $query) {
    $this->getJson(VIDEOS_ENDPOINT."?from=2026-04-01&to=2026-04-30&{$query}")->assertStatus(422);
})->with([
    'no list at all' => 'metric=streaming_bytes',
    'an empty list' => 'videos=',
    'something that is not a ULID' => 'videos[]=not-a-ulid',
    'a ULID with the letters Crockford excludes' => 'videos[]=01HZXW3V5N8Q9R2T4Y6B8D0FIL',
    'a metric that is not a delivery metric' => 'videos[]=01HZXW3V5N8Q9R2T4Y6B8D0F1G&metric=encoding_cpu',
    'a granularity that is not one of the two' => 'videos[]=01HZXW3V5N8Q9R2T4Y6B8D0F1G&granularity=hourly',
]);
