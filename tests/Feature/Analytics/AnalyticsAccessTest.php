<?php

/**
 * Analytics spans the whole instance — bandwidth totals, viewer IPs, top videos across every
 * tenant — and its `user_id` parameter selects whose upload volume to read. It is an admin
 * surface, and it used to sit in the plain authenticated group where any user could read it.
 */

use App\Models\Project;
use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('refuses analytics to a non-admin user', function (string $endpoint) {
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson($endpoint)->assertForbidden();
})->with([
    'the dashboard' => '/api/analytics?from=2026-01-01&to=2026-01-31',
    'the queue' => '/api/analytics/queue',
]);

it('refuses analytics to a project API key', function () {
    // A project key authenticates AS the project; account-wide surfaces are not its business,
    // even when the project belongs to an admin.
    $project = Project::factory()->for(User::factory()->create(['is_admin' => true]))->create();
    $key = app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;

    $this->withToken($key)->getJson('/api/analytics/queue')->assertForbidden();
});

it('lets an admin through', function () {
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

    $this->getJson('/api/analytics/queue')->assertOk();
});
