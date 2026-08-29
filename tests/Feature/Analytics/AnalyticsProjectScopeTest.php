<?php

/**
 * `/api/analytics` shares instance-wide aggregates on purpose: the figures name nobody, and
 * NukeVideo sits behind other backends server to server ({@see AnalyticsAccessTest}).
 *
 * Two of its numbers were never in that bargain. `usageSummary()` is upload volume and
 * `topExternalUsers()` returns `external_user_id` values — the integrator's OWN customer labels.
 * They used to be keyed by a `?user_id=` validated as `exists:users,id` and nothing more, so any
 * authenticated token, a project API key included, could name another account and read both by
 * guessing an id. `topIps`, `topVideos`, `topTrackingIds` and `bandwidthByVideo` had the same shape
 * of problem without needing a parameter at all: unnarrowed they enumerate whoever else is on the
 * installation.
 *
 * There is no account axis on this endpoint any more, and no operator exception either. Everything
 * narrows to the project the caller named, and the breakdowns that name things are answered only
 * when one was named — which is what these cases pin.
 */

use App\Models\Project;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/** Stubs the service and hands back the project scope each query was called with. */
function seenProjectScope(): ArrayObject
{
    $seen = new ArrayObject;

    app()->instance(AnalyticsService::class, Mockery::mock(AnalyticsService::class, function (MockInterface $mock) use ($seen) {
        $mock->shouldReceive('usageSummary')->andReturnUsing(function ($from, $to, $projectId) use ($seen) {
            $seen['usageSummary'] = $projectId;

            return ['upload_bytes' => 0, 'encoding_cpu' => 0];
        });

        // Shaped rows, so "the controller withheld this" and "there was nothing to show" cannot be
        // read as the same empty array.
        $mock->shouldReceive('topExternalUsers')->andReturnUsing(function ($from, $to, $projectId) use ($seen) {
            $seen['topExternalUsers'] = $projectId;

            return [['external_user_id' => 'customer-1', 'bytes' => 1.0]];
        });

        $mock->shouldReceive('summary')->andReturnUsing(function (...$args) use ($seen) {
            $seen['summary'] = $args[5] ?? null;

            return ['total_bytes' => 0, 'unique_videos' => 0, 'unique_ips' => 0, 'unique_tracking_ids' => 0];
        });

        $mock->shouldReceive('topIps')->andReturn([['ip' => '203.0.113.7', 'bytes' => 1.0, 'sessions' => 1]]);
        $mock->shouldReceive('encodingUsage')->andReturn(['cpu' => 0]);
        $mock->shouldReceive('bandwidthOverTime', 'encodingUsageOverTime', 'topVideos', 'bandwidthByTrackingId', 'bandwidthByVideo')->andReturn([]);
    }));

    return $seen;
}

it('narrows every query to the project the caller named', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $project = Project::factory()->for($user)->create();
    Sanctum::actingAs($user);

    $seen = seenProjectScope();

    $this->withHeader('X-Project-Ulid', $project->ulid)
        ->getJson('/api/analytics?from=2026-01-01&to=2026-01-31')
        ->assertOk();

    expect($seen['summary'])->toBe($project->id)
        ->and($seen['usageSummary'])->toBe($project->id)
        ->and($seen['topExternalUsers'])->toBe($project->id);
});

it('narrows to the project a key belongs to, without any header', function () {
    // A project API key authenticates AS the project, so it resolves one on its own — which is how
    // an integrating backend reads its own numbers.
    $project = Project::factory()->for(User::factory()->create(['is_admin' => false]))->create();
    $key = app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;

    $seen = seenProjectScope();

    $this->withToken($key)->getJson('/api/analytics?from=2026-01-01&to=2026-01-31')
        ->assertOk()
        ->assertJsonCount(1, 'data.topIps')
        ->assertJsonPath('data.topExternalUsers.0.externalUserId', 'customer-1');

    expect($seen['summary'])->toBe($project->id);
});

it('answers instance-wide aggregates when no project was named', function () {
    // Null is unnarrowed, and the aggregates are what this endpoint has always shared: they are
    // totals, and a total names nobody.
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $seen = seenProjectScope();

    $this->getJson('/api/analytics?from=2026-01-01&to=2026-01-31')
        ->assertOk()
        ->assertJsonCount(7, 'data.cards')
        ->assertJsonPath('data.cards.0.key', 'total_bandwidth');

    expect($seen['summary'])->toBeNull();
});

it('withholds every breakdown that names something when no project was named', function () {
    // The service is stubbed to return rows for two of these; what comes back is empty, so the
    // controller withheld them rather than simply having nothing to show.
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));
    seenProjectScope();

    $this->getJson('/api/analytics?from=2026-01-01&to=2026-01-31&limit=1000')
        ->assertOk()
        ->assertJsonPath('data.topIps', [])
        ->assertJsonPath('data.topVideos', [])
        ->assertJsonPath('data.topTrackingIds', [])
        ->assertJsonPath('data.topExternalUsers', [])
        ->assertJsonPath('data.bandwidthByVideo', []);
});

it('gives an operator no more than anyone else here', function () {
    // There is no operator exception on this endpoint, which is one branch fewer to get wrong. An
    // administrator that names no project reads the same aggregates, and the same empty identifier
    // lists, as a tenant would; the instance-wide view lives on `/api/metrics`, where naming the
    // fleet dimensions is what requires being one.
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

    $seen = seenProjectScope();

    $this->getJson('/api/analytics?from=2026-01-01&to=2026-01-31')
        ->assertOk()
        ->assertJsonPath('data.topIps', []);

    expect($seen['summary'])->toBeNull();
});

it('ignores a user_id, which is no longer an axis of this endpoint', function () {
    // It used to be how you read another account's upload volume and customer labels. Removing the
    // parameter is the fix: there is nothing left whose ownership has to be checked.
    $victim = User::factory()->create();
    $user = User::factory()->create(['is_admin' => false]);
    $project = Project::factory()->for($user)->create();
    Sanctum::actingAs($user);

    $seen = seenProjectScope();

    $this->withHeader('X-Project-Ulid', $project->ulid)
        ->getJson("/api/analytics?from=2026-01-01&to=2026-01-31&user_id={$victim->id}")
        ->assertOk();

    expect($seen['usageSummary'])->toBe($project->id)
        ->and($seen['topExternalUsers'])->toBe($project->id);
});
