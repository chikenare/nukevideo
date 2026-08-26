<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function listedVideo(string $name, string $status = 'completed', float $duration = 10, array $streams = []): Video
{
    $video = Video::create([
        'user_id' => test()->user->id,
        'project_id' => test()->project->id,
        'name' => $name,
        'duration' => $duration,
        'aspect_ratio' => '16:9',
        'status' => $status,
    ]);

    foreach ($streams as $i => [$package, $file]) {
        $video->streams()->create(['path' => "{$video->ulid}/video/{$i}.mp4", 'type' => 'video', 'meta' => [], 'package_size' => $package, 'file_size' => $file]);
    }

    return $video;
}

function listedNames(string $query = ''): array
{
    return collect(test()->getJson("/api/videos?{$query}")->assertOk()->json('data'))->pluck('name')->all();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('lists newest first by default', function () {
    listedVideo('first');
    $this->travel(1)->minute();
    listedVideo('second');

    expect(listedNames())->toBe(['second', 'first']);
});

it('filters by one status, or several', function () {
    listedVideo('done', 'completed');
    listedVideo('broken', 'failed');
    listedVideo('busy', 'running');

    expect(listedNames('status=failed'))->toBe(['broken'])
        ->and(listedNames('status=completed,failed&sort=name&direction=asc'))->toBe(['broken', 'done']);
});

it('refuses a status it does not know rather than silently listing everything', function () {
    $this->getJson('/api/videos?status=finished')->assertStatus(422)->assertJsonValidationErrors(['status']);
});

it('sorts by name, duration and status in either direction', function () {
    listedVideo('bravo', 'running', 30);
    listedVideo('alpha', 'completed', 10);
    listedVideo('charlie', 'failed', 20);

    expect(listedNames('sort=name&direction=asc'))->toBe(['alpha', 'bravo', 'charlie'])
        ->and(listedNames('sort=name&direction=desc'))->toBe(['charlie', 'bravo', 'alpha'])
        ->and(listedNames('sort=duration&direction=asc'))->toBe(['alpha', 'charlie', 'bravo'])
        ->and(listedNames('sort=status&direction=asc'))->toBe(['alpha', 'charlie', 'bravo']);
});

it('sorts by size as the listing shows it: package plus file bytes over every stream', function () {
    listedVideo('mid', streams: [[500, 500]]);
    listedVideo('big', streams: [[1000, 0], [0, 2000]]);
    listedVideo('empty');

    // `size` is not a column; a video with no streams still sorts, at zero.
    expect(listedNames('sort=size&direction=desc'))->toBe(['big', 'mid', 'empty'])
        ->and(listedNames('sort=size&direction=asc'))->toBe(['empty', 'mid', 'big']);
});

it('refuses an unknown sort column', function () {
    // Anything else would reach `orderBy` as a column name.
    $this->getJson('/api/videos?sort=user_id')->assertStatus(422)->assertJsonValidationErrors(['sort']);
    $this->getJson('/api/videos?sort=name&direction=sideways')->assertStatus(422)->assertJsonValidationErrors(['direction']);
});
