<?php

/**
 * `users -> projects -> videos` cascades in the schema, so both tear-down paths used to drop
 * video rows at the DB level — VideoObserver never ran, and every package, source and thumbnail
 * stayed on primary S3 with nothing left pointing at it. Both now walk down through Eloquent,
 * and neither will delete a project whose videos are still mid-encode.
 */

use App\Jobs\DeleteResourceWithPath;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function projectVideo(Project $project, string $status = 'completed'): Video
{
    $template = Template::create([
        'name' => 'AV1',
        'query' => [],
        'user_id' => $project->user_id,
        'project_id' => $project->id,
    ]);

    return Video::create([
        'user_id' => $project->user_id,
        'project_id' => $project->id,
        'template_id' => $template->id,
        'name' => 'Movie',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => $status,
    ]);
}

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();
    // The endpoint refuses to delete a user's only project.
    $this->spare = Project::factory()->for($this->user)->create();

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('deletes a project through Eloquent so the video observer runs', function () {
    $video = projectVideo($this->project);

    $this->deleteJson("/api/projects/{$this->project->ulid}")->assertOk();

    expect(Video::find($video->id))->toBeNull();
    Queue::assertPushed(DeleteResourceWithPath::class);
});

it('refuses to delete a project while a video is still encoding', function () {
    $video = projectVideo($this->project, 'running');

    $this->deleteJson("/api/projects/{$this->project->ulid}")->assertStatus(409);

    expect(Project::find($this->project->id))->not->toBeNull()
        ->and(Video::find($video->id))->not->toBeNull();
});

it('cleans up every video when a user is deleted', function () {
    $video = projectVideo($this->project);
    $admin = User::factory()->create(['is_admin' => true]);

    Sanctum::actingAs($admin);
    $this->deleteJson("/api/users/{$this->user->id}")->assertOk();

    expect(User::find($this->user->id))->toBeNull()
        ->and(Video::find($video->id))->toBeNull();
    Queue::assertPushed(DeleteResourceWithPath::class);
});
