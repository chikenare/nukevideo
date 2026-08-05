<?php

/**
 * Deleting a template used to cascade at the DB level into every video encoded with it —
 * whole libraries gone from one panel click, with the Eloquent observers (and therefore the
 * S3 cleanup and webhooks) never firing. Two layers now stand in the way: the endpoint
 * refuses while videos reference the template, and the FK nulls instead of cascading.
 */

use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

    $this->template = Template::create([
        'name' => 'AV1',
        'query' => [],
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

function videoFor(Template $template): Video
{
    return Video::create([
        'user_id' => $template->user_id,
        'project_id' => $template->project_id,
        'template_id' => $template->id,
        'name' => 'Movie',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
    ]);
}

it('refuses to delete a template that videos were encoded with', function () {
    $video = videoFor($this->template);

    $this->deleteJson("/api/templates/{$this->template->ulid}")->assertStatus(422);

    expect(Template::find($this->template->id))->not->toBeNull()
        ->and(Video::find($video->id))->not->toBeNull();
});

it('deletes a template no video references', function () {
    $this->deleteJson("/api/templates/{$this->template->ulid}")->assertOk();

    expect(Template::find($this->template->id))->toBeNull();
});

it('never takes the videos with it, even when deleted outside the endpoint', function () {
    // The DB-level backstop: any other deletion path (a future console command, a project
    // sweep) must orphan the reference, not the library.
    $video = videoFor($this->template);

    $this->template->delete();

    expect($video->fresh())->not->toBeNull()
        ->and($video->fresh()->template_id)->toBeNull();
});
