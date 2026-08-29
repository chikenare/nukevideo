<?php

/**
 * `/api/analytics` deliberately shares instance-wide bandwidth: the figures name nobody, and
 * NukeVideo sits behind other backends server to server ({@see AnalyticsAccessTest}).
 *
 * Two of its numbers were never in that bargain. `usageSummary()` is upload volume per account, and
 * `topExternalUsers()` returns `external_user_id` values — the integrator's OWN customer labels.
 * Both took a `?user_id=` that was validated as `exists:users,id` and nothing more, so any
 * authenticated token, a project API key included, could name another account and read its upload
 * figures and its customer identifiers by guessing an id.
 *
 * What these cases pin: a caller that is not an operator reads its own account and only its own,
 * whatever it asks for.
 */

use App\Models\Project;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Stubs the service and hands back the `user_id` the account-keyed queries were called with.
 *
 * `$topIps` stands in for the identifier breakdowns: one shaped row is enough to tell "the service
 * returned something and the controller withheld it" apart from "the service returned nothing".
 */
function seenAccountId(array $topIps = []): ArrayObject
{
    $seen = new ArrayObject;

    app()->instance(AnalyticsService::class, Mockery::mock(AnalyticsService::class, function (MockInterface $mock) use ($seen, $topIps) {
        $mock->shouldReceive('usageSummary')->andReturnUsing(function ($from, $to, $userId) use ($seen) {
            $seen['usageSummary'] = $userId;

            return ['upload_bytes' => 0, 'encoding_cpu' => 0];
        });

        $mock->shouldReceive('topExternalUsers')->andReturnUsing(function ($from, $to, $userId) use ($seen) {
            $seen['topExternalUsers'] = $userId;

            return [];
        });

        $mock->shouldReceive('summary')->andReturn(['total_bytes' => 0, 'unique_videos' => 0, 'unique_ips' => 0, 'unique_tracking_ids' => 0]);
        $mock->shouldReceive('encodingUsage')->andReturn(['cpu' => 0]);
        $mock->shouldReceive('bandwidthOverTime', 'encodingUsageOverTime')->andReturn([]);
        $mock->shouldReceive('topIps')->andReturn($topIps);
        $mock->shouldReceive('topVideos', 'bandwidthByTrackingId', 'bandwidthByVideo')->andReturn([]);
    }));

    return $seen;
}

/** Burns ids on both sides so a project id can never coincide with its owner's user id. */
function otherAccount(): User
{
    User::factory()->count(3)->create();

    return User::factory()->create();
}

it('ignores a user_id a non-admin has no business naming', function () {
    $victim = otherAccount();
    $caller = User::factory()->create(['is_admin' => false]);
    Sanctum::actingAs($caller);

    $seen = seenAccountId();

    $this->getJson("/api/analytics?from=2026-01-01&to=2026-01-31&user_id={$victim->id}")->assertOk();

    // Its own account, not the one it asked for. Answering 403 instead would confirm the account
    // exists; answering with the caller's own figures tells the prober nothing.
    expect($seen['usageSummary'])->toBe($caller->id)
        ->and($seen['topExternalUsers'])->toBe($caller->id)
        ->and($seen['usageSummary'])->not->toBe($victim->id);
});

it('ignores a user_id a project key names, and reads the owning account', function () {
    // The worst case of the two: a project key is held by an integrating backend, and
    // `external_user_id` is precisely the label THAT backend attached to its own customers.
    $victim = otherAccount();
    $owner = User::factory()->create(['is_admin' => false]);
    $project = Project::factory()->for($owner)->create();
    $key = app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;

    expect($project->id)->not->toBe($owner->id);

    $seen = seenAccountId();

    $this->withToken($key)
        ->getJson("/api/analytics?from=2026-01-01&to=2026-01-31&user_id={$victim->id}")
        ->assertOk();

    // Not the project id either — that is a different sequence, and reading it as a user id would
    // quietly answer with whichever account happens to share the number.
    expect($seen['usageSummary'])->toBe($owner->id)
        ->and($seen['topExternalUsers'])->toBe($owner->id);
});

it('scopes a non-admin to its own account even when it names nobody', function () {
    // The default leaked too: with no `user_id` at all, `topExternalUsers` ran unfiltered and
    // returned every tenant's customer labels mixed together.
    $caller = User::factory()->create(['is_admin' => false]);
    Sanctum::actingAs($caller);

    $seen = seenAccountId();

    $this->getJson('/api/analytics?from=2026-01-01&to=2026-01-31')->assertOk();

    expect($seen['topExternalUsers'])->toBe($caller->id);
});

it('lets an operator name an account it can already read', function () {
    // An admin reaches every account through `/api/users` anyway, so naming one here reveals
    // nothing new — and the panel needs it to report on a single customer.
    $target = otherAccount();
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

    $seen = seenAccountId();

    $this->getJson("/api/analytics?from=2026-01-01&to=2026-01-31&user_id={$target->id}")->assertOk();

    expect($seen['usageSummary'])->toBe($target->id);
});

it('keeps the instance-wide view for an operator that names none', function () {
    // Null is "every account", which is the operator's dashboard and stays as it was.
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

    $seen = seenAccountId();

    $this->getJson('/api/analytics?from=2026-01-01&to=2026-01-31')->assertOk();

    expect($seen['usageSummary'])->toBeNull()
        ->and($seen['topExternalUsers'])->toBeNull();
});

it('withholds the identifier lists from anyone but an operator', function () {
    // A total is instance-wide and names nobody — the trade this endpoint has always documented. A
    // LIST OF IDENTIFIERS is not the same thing: unscoped, these four enumerate other tenants'
    // viewer addresses, viewer labels and video ULIDs, and the caller sets the limit that caps them.
    seenAccountId([['ip' => '203.0.113.7', 'bytes' => 1.0, 'sessions' => 1]]);
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson('/api/analytics?from=2026-01-01&to=2026-01-31&limit=1000')
        ->assertOk()
        ->assertJsonPath('data.topIps', [])
        ->assertJsonPath('data.topVideos', [])
        ->assertJsonPath('data.topTrackingIds', [])
        ->assertJsonPath('data.bandwidthByVideo', []);
});

it('withholds them from a project key too', function () {
    seenAccountId([['ip' => '203.0.113.7', 'bytes' => 1.0, 'sessions' => 1]]);
    $project = Project::factory()->for(User::factory()->create(['is_admin' => false]))->create();
    $key = app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;

    $this->withToken($key)->getJson('/api/analytics?from=2026-01-01&to=2026-01-31')
        ->assertOk()
        ->assertJsonPath('data.topTrackingIds', [])
        ->assertJsonPath('data.topIps', []);
});

it('still gives the aggregates to a tenant', function () {
    // What was withheld is the identifiers, not the numbers. The cards and the bandwidth series
    // name nobody and stay exactly as they were.
    seenAccountId();
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson('/api/analytics?from=2026-01-01&to=2026-01-31')
        ->assertOk()
        ->assertJsonCount(7, 'data.cards')
        ->assertJsonPath('data.cards.0.key', 'total_bandwidth')
        ->assertJsonPath('data.cards.0.unit', 'bytes');
});

it('gives an operator the identifier lists it asked for', function () {
    seenAccountId([['ip' => '203.0.113.7', 'bytes' => 1.0, 'sessions' => 1]]);
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

    $this->getJson('/api/analytics?from=2026-01-01&to=2026-01-31')
        ->assertOk()
        ->assertJsonCount(1, 'data.topIps')
        ->assertJsonPath('data.topIps.0.ip', '203.0.113.7');
});
