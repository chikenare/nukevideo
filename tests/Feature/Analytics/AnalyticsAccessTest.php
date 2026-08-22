<?php

/**
 * Analytics is readable by ANY authenticated token, a project API key included. It is the read side
 * of the CDN access logs, and reading those back is precisely what an integrating backend holds a
 * project key for — gating it behind an admin account meant the numbers were written to ClickHouse
 * and nobody downstream could ever see them.
 *
 * What that accepts is stated plainly rather than assumed: the figures span the whole instance, so
 * a project key reads totals that include other projects' traffic. NukeVideo sits behind other
 * backends, server to server, so that aggregate is not the boundary being defended here. The
 * boundaries that ARE still hold, and the cases below pin them: no unauthenticated access, and none
 * of the admin surfaces moved along with it.
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
 * The dashboard reads ClickHouse, which the suite has no fixture for and CI does not run. These
 * cases are about who gets through the door, so the service behind it is stubbed empty.
 */
function stubAnalyticsService(): void
{
    app()->instance(AnalyticsService::class, Mockery::mock(AnalyticsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('summary')->andReturn(['total_bytes' => 0, 'unique_videos' => 0, 'unique_ips' => 0, 'unique_tracking_ids' => 0]);
        $mock->shouldReceive('encodingUsage')->andReturn(['cpu' => 0]);
        $mock->shouldReceive('usageSummary')->andReturn(['upload_bytes' => 0, 'encoding_cpu' => 0]);
        $mock->shouldReceive('bandwidthOverTime', 'topIps', 'topVideos', 'topExternalUsers', 'bandwidthByTrackingId', 'bandwidthByVideo', 'encodingUsageOverTime')->andReturn([]);
    }));
}

it('lets a non-admin user read analytics', function (string $endpoint) {
    stubAnalyticsService();
    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

    $this->getJson($endpoint)->assertOk();
})->with([
    'the dashboard' => '/api/analytics?from=2026-01-01&to=2026-01-31',
    'the queue' => '/api/analytics/queue',
]);

it('lets a project API key read analytics', function (string $endpoint) {
    stubAnalyticsService();

    // A project key authenticates AS the project, so `$request->user()` is a Project and not a
    // User. Neither endpoint touches it — the day one does, it has to move back behind
    // `no-project-key` or read the project explicitly.
    $project = Project::factory()->for(User::factory()->create(['is_admin' => false]))->create();
    $key = app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;

    $this->withToken($key)->getJson($endpoint)->assertOk();
})->with([
    'the dashboard' => '/api/analytics?from=2026-01-01&to=2026-01-31',
    'the queue' => '/api/analytics/queue',
]);

it('still refuses analytics to an unauthenticated caller', function (string $endpoint) {
    $this->getJson($endpoint)->assertUnauthorized();
})->with([
    'the dashboard' => '/api/analytics?from=2026-01-01&to=2026-01-31',
    'the queue' => '/api/analytics/queue',
]);

it('did not open the admin surfaces alongside it', function (string $endpoint) {
    // Opening the metrics is a decision about metrics. Everything that operates the instance —
    // nodes, users, keys, CDN settings — is a separate question and was not part of it.
    $project = Project::factory()->for(User::factory()->create(['is_admin' => true]))->create();
    $key = app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;

    $this->withToken($key)->getJson($endpoint)->assertForbidden();

    Sanctum::actingAs(User::factory()->create(['is_admin' => false]));
    $this->getJson($endpoint)->assertForbidden();
})->with([
    'nodes' => '/api/nodes',
    'users' => '/api/users',
    'CDN settings' => '/api/cdn-settings',
    'the node environment' => '/api/node-environment',
]);
