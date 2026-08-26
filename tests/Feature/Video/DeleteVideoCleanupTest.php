<?php

/**
 * What a video delete has to take with it beyond the rows and the S3 prefix. Each of these is a
 * resource the DB cascade knows nothing about, so it only goes if an observer takes it — and a
 * resource that is not taken is not merely a leak: a live `upload_meta` resurrects the video, and
 * a live batch keeps running chunk jobs against a row that no longer exists.
 */

use App\DTOs\UploadMeta;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use App\Services\UppyS3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function deletableVideo(string $status = 'completed'): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $template = Template::create(['name' => 'T', 'query' => [], 'user_id' => $user->id, 'project_id' => $project->id]);

    $video = Video::create([
        'user_id' => $user->id,
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
});

it('forgets the upload metadata of the original, so the sweep cannot re-ingest it', function () {
    $video = deletableVideo();
    $key = $video->streams()->where('type', 'original')->value('path');

    app(UppyS3Service::class)->storeUploadMeta($key, new UploadMeta(
        user: (string) $video->user_id, project: (string) $video->project_id, template: 'x', filename: 'clip.mkv',
    ));

    $video->delete();

    // The metadata is what PruneScratchJob replays a lost webhook from. If the object survived a
    // failed delete, live metadata would have handed the user back the video they just removed.
    expect(app(UppyS3Service::class)->getUploadMeta($key))->toBeNull();
});

it('cancels the unfinished encode batches of a video that is deleted mid-flight', function () {
    $video = deletableVideo('failed');

    foreach (['video-processing', 'video-processing-nvidia'] as $queue) {
        DB::table('job_batches')->insert([
            'id' => "batch-{$queue}",
            'name' => "encode video {$video->id} {$queue}",
            'total_jobs' => 10,
            'pending_jobs' => 6,
            'failed_jobs' => 1,
            'failed_job_ids' => '[]',
            'options' => serialize([]),
            'created_at' => now()->timestamp,
            'finished_at' => null,
        ]);
    }

    $video->delete();

    // Cancelled, not deleted: a cancelled batch is what makes the queued chunk jobs bail out on
    // pickup instead of loading a video that is gone.
    expect(DB::table('job_batches')->whereNull('cancelled_at')->count())->toBe(0);
});
