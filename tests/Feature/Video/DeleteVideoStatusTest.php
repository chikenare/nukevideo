<?php

/**
 * Which statuses a video may be deleted in. A PENDING video is deletable because nothing has
 * started on it — it holds no worker, has no chunk and no batch — while anything between PENDING
 * and a terminal status is mid-flight, and deleting there strands jobs encoding against a row
 * that is gone. What a delete then takes with it lives in {@see DeleteVideoCleanupTest}.
 */

use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function videoWithStatus(Project $project, string $status): Video
{
    $template = Template::create([
        'name' => 'Template',
        'query' => [],
        'user_id' => $project->user_id,
        'project_id' => $project->id,
    ]);

    $video = Video::create([
        'user_id' => $project->user_id,
        'project_id' => $project->id,
        'template_id' => $template->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => $status,
    ]);

    $video->streams()->create(['path' => "tmp-videos/{$video->ulid}.mkv", 'type' => 'original', 'meta' => []]);

    return $video;
}

beforeEach(function () {
    Storage::fake('s3');
    Queue::fake();
    Redis::shouldReceive('del')->andReturn(1);

    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('deletes a video that is still pending', function () {
    $video = videoWithStatus($this->project, 'pending');

    $this->deleteJson("/api/videos/{$video->ulid}")->assertOk();

    expect(Video::find($video->id))->toBeNull();
});

it('deletes a terminal video', function (string $status) {
    $video = videoWithStatus($this->project, $status);

    $this->deleteJson("/api/videos/{$video->ulid}")->assertOk();

    expect(Video::find($video->id))->toBeNull();
})->with(['completed', 'failed']);

it('refuses a video the pipeline has already started on', function (string $status) {
    $video = videoWithStatus($this->project, $status);

    $this->deleteJson("/api/videos/{$video->ulid}")
        ->assertStatus(400)
        ->assertJsonPath('message', 'You cannot delete a video if it is still in progress.');

    expect(Video::find($video->id))->not->toBeNull();
})->with(['downloading', 'running', 'uploading']);
