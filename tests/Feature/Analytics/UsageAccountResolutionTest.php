<?php

/**
 * `usage` is keyed by `user_id` in ClickHouse, and a project API key authenticates AS the project:
 * `$request->user()` is a Project, whose `id` comes from a different sequence than a user's. Now
 * that a project key may read this endpoint, taking that id at face value would not error — it
 * would answer with whatever account happens to share the number, or with nothing at all.
 *
 * The metric filter is pinned here too, for a related reason: this endpoint is what an integrator
 * builds an invoice out of, and an unrecognised metric name used to answer with an empty result —
 * which reads as zero, not as a typo.
 */

use App\Enums\UsageMetric;
use App\Models\Project;
use App\Models\User;
use App\Services\ApiTokenService;
use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Runs the endpoint against a fake ClickHouse and returns the parameters it bound. */
function usageQueryParams(callable $request): array
{
    $captured = [];

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('select')->andReturnUsing(function ($sql, $params) use (&$captured) {
        $captured = $params;

        return Mockery::mock(Statement::class, fn ($mock) => $mock->shouldReceive('rows')->andReturn([]));
    });

    app()->instance(Client::class, $client);

    $request();

    return $captured;
}

it('reads the owning account when the caller is a project API key', function () {
    // Burn a few ids on each side so a project id can never coincide with its owner's user id —
    // the bug this guards against is invisible when the two happen to match.
    User::factory()->count(3)->create();
    $owner = User::factory()->create();
    Project::factory()->count(5)->for(User::factory()->create())->create();
    $project = Project::factory()->for($owner)->create();

    expect($project->id)->not->toBe($owner->id);

    $key = app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;

    $params = usageQueryParams(fn () => $this->withToken($key)
        ->getJson('/api/usage?from=2026-01-01&to=2026-01-31')
        ->assertOk());

    expect($params['user_id'])->toBe($owner->id);
});

it('reads the authenticated user when the caller holds a personal token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $params = usageQueryParams(fn () => $this->getJson('/api/usage?from=2026-01-01&to=2026-01-31')->assertOk());

    expect($params['user_id'])->toBe($user->id);
});

it('accepts every metric an account can actually be charged for', function (string $metric) {
    Sanctum::actingAs(User::factory()->create());

    $params = usageQueryParams(fn () => $this->getJson("/api/usage?from=2026-01-01&to=2026-01-31&metric={$metric}")->assertOk());

    expect($params['metric'])->toBe($metric);
})->with(UsageMetric::account());

it('refuses a metric it could only answer emptily', function (string $metric) {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/usage?from=2026-01-01&to=2026-01-31&metric={$metric}")->assertStatus(422);
})->with([
    // A typo used to be indistinguishable from "you consumed nothing".
    'a typo' => 'streamming_bytes',
    'something invented' => 'made_up',
    // Real, but booked under account 0 — the operator's origin egress, which no account-scoped read
    // can ever be. Accepting it would answer empty for a reason the caller could not tell apart.
    'the origin egress' => 'origin_bytes',
]);

it('refuses an external user id wider than the column can hold', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/usage?from=2026-01-01&to=2026-01-31&external_user_id='.str_repeat('a', 256))
        ->assertStatus(422);
});
