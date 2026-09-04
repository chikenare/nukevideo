<?php

/**
 * The panel's way back into the pipeline. The requeue itself is covered by the CLI's suite
 * ({@see tests/Feature/Video/RetryVideoTest.php} — both go through `VideoService::retry()`); what
 * is tested here is what the endpoint adds: project scoping and the refusal shape.
 */

use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function retryableVideo(Project $project, string $status = 'failed'): Video
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
        'duration' => 600,
        'aspect_ratio' => '16:9',
        'status' => $status,
    ]);

    $video->streams()->create(['path' => "tmp-videos/{$video->ulid}.mkv", 'type' => 'original', 'meta' => []]);
    $video->streams()->create([
        'path' => "{$video->ulid}/video/rendition.mp4",
        'type' => 'video',
        'meta' => [],
        'error_log' => 'chunk 4 blew up',
    ]);
    $video->outputs()->create(['status' => 'failed']);

    // What makes the video retryable at all: the source has to still be readable somewhere.
    Storage::disk('s3')->put("tmp-videos/{$video->ulid}.mkv", 'x');

    return $video;
}

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('chunks');
    // Chunk progress is a Redis hash: the retry drops it, and serializing the video back reads
    // it for each output's progress. No Redis in CI.
    Redis::shouldReceive('del')->andReturn(1);
    Redis::shouldReceive('hvals')->andReturn([]);

    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('requeues a failed video', function () {
    $video = retryableVideo($this->project);

    $this->postJson("/api/videos/{$video->ulid}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    expect($video->fresh()->status)->toBe('pending')
        ->and($video->streams()->whereNotNull('error_log')->count())->toBe(0)
        // Kept, so the retry is a cache hit on everything the failed run had already staged.
        ->and($video->streams()->where('type', 'video')->count())->toBe(1)
        ->and($video->outputs()->where('status', 'pending')->count())->toBe(1);
});

it('always reuses what the failed run staged, whatever the body says', function () {
    $video = retryableVideo($this->project);

    // The endpoint takes no body. `reprobe` is a `videos:retry --reprobe` decision, so a caller
    // sending it must not get a re-probe through the back door — the derived rows stay.
    $this->postJson("/api/videos/{$video->ulid}/retry", ['reprobe' => true])->assertOk();

    expect($video->streams()->where('type', 'video')->count())->toBe(1)
        ->and($video->outputs()->count())->toBe(1);
});

it('refuses a video that is not failed', function () {
    $video = retryableVideo($this->project, 'completed');

    $this->postJson("/api/videos/{$video->ulid}/retry")
        ->assertStatus(409)
        ->assertJsonPath('message', 'This video is completed, not failed — there is nothing to retry.');

    expect($video->fresh()->status)->toBe('completed');
});

it('refuses when the source is gone from both the mirror and S3', function () {
    $video = retryableVideo($this->project);
    Storage::disk('s3')->delete("tmp-videos/{$video->ulid}.mkv");

    // A retry would re-download nothing and die 20 minutes later.
    $this->postJson("/api/videos/{$video->ulid}/retry")->assertStatus(409);

    expect($video->fresh()->status)->toBe('failed');
});

it('lets a retry through when a store cannot be asked at all', function () {
    $video = retryableVideo($this->project);
    // S3 would answer "no", so the verdict rests entirely on the mirror — which cannot answer.
    Storage::disk('s3')->delete("tmp-videos/{$video->ulid}.mkv");

    $unreachable = Mockery::mock(Filesystem::class);
    $unreachable->shouldReceive('exists')->andThrow(new RuntimeException('endpoint down'));
    Storage::set('chunks', $unreachable);

    // "Your source is gone" is the one answer that must never be invented from a transport error:
    // it is unarguable, and it would send the operator looking for a file that is still there.
    $this->postJson("/api/videos/{$video->ulid}/retry")->assertOk();

    expect($video->fresh()->status)->toBe('pending');
});

it('cannot reach another project\'s video', function () {
    $foreign = retryableVideo(Project::factory()->for(User::factory())->create());

    $this->postJson("/api/videos/{$foreign->ulid}/retry")->assertNotFound();

    expect($foreign->fresh()->status)->toBe('failed');
});
