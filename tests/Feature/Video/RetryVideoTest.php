<?php

use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function failedVideo(array $attributes = []): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $template = Template::create([
        'name' => 'Template',
        'query' => [],
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);

    $video = Video::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'template_id' => $template->id,
        'name' => 'Clip',
        'duration' => 600,
        'aspect_ratio' => '16:9',
        'status' => 'failed',
        ...$attributes,
    ]);

    $video->streams()->create(['path' => originalPath($video), 'type' => 'original', 'meta' => []]);
    $video->streams()->create([
        'path' => "{$video->ulid}/video/rendition.mp4",
        'type' => 'video',
        'meta' => [],
        'error_log' => 'chunk 4 blew up',
    ]);
    $video->outputs()->create(['status' => 'failed']);

    Storage::disk('s3')->put(originalPath($video), 'x');

    return $video;
}

/** The upload key PrepareVideoJob would pull from when the internal mirror is gone. */
function originalPath(Video $video): string
{
    return "tmp-videos/{$video->ulid}.mkv";
}

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('chunks');
    // Chunk progress is a Redis hash the command has to drop; no Redis in CI.
    Redis::shouldReceive('del')->andReturn(1);
});

describe('videos:retry', function () {
    it('requeues a failed video and clears what the failed run left behind', function () {
        $video = failedVideo();

        $this->artisan('videos:retry', ['video' => [$video->id]])->assertSuccessful();

        expect($video->fresh()->status)->toBe('pending')
            ->and($video->fresh()->last_heartbeat_at)->toBeNull()
            ->and($video->streams()->whereNotNull('error_log')->count())->toBe(0)
            ->and($video->outputs()->where('status', 'pending')->count())->toBe(1);
    });

    it('accepts a ULID as well as an id', function () {
        $video = failedVideo();

        $this->artisan('videos:retry', ['video' => [$video->ulid]])->assertSuccessful();

        expect($video->fresh()->status)->toBe('pending');
    });

    it('refuses a video that is not failed', function () {
        $video = failedVideo(['status' => 'completed']);

        $this->artisan('videos:retry', ['video' => [$video->id]])->assertFailed();

        expect($video->fresh()->status)->toBe('completed');
    });

    it('refuses when the source is gone from both the mirror and S3', function () {
        $video = failedVideo();
        Storage::disk('s3')->delete(originalPath($video));

        // A retry would re-download nothing and die 20 minutes later.
        $this->artisan('videos:retry', ['video' => [$video->id]])->assertFailed();

        expect($video->fresh()->status)->toBe('failed');
    });

    it('retries off the internal mirror when the upload is already gone', function () {
        $video = failedVideo();
        Storage::disk('s3')->delete(originalPath($video));
        Storage::disk('chunks')->put($video->sourceMirrorPath('mkv'), 'x');

        $this->artisan('videos:retry', ['video' => [$video->id]])->assertSuccessful();

        expect($video->fresh()->status)->toBe('pending');
    });

    it('refuses while an encode batch is still unfinished', function () {
        $video = failedVideo();

        DB::table('job_batches')->insert([
            'id' => 'batch-1',
            'name' => "encode video {$video->id} video-processing-intel",
            'total_jobs' => 10,
            'pending_jobs' => 3,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'created_at' => now()->timestamp,
        ]);

        $this->artisan('videos:retry', ['video' => [$video->id]])->assertFailed();

        expect($video->fresh()->status)->toBe('failed');
    });

    it('clears the finished batch of the run that failed, which would block fan-out', function () {
        $video = failedVideo();

        DB::table('job_batches')->insert([
            'id' => 'batch-1',
            'name' => "encode video {$video->id} video-processing-intel",
            'total_jobs' => 10,
            'pending_jobs' => 0,
            'failed_jobs' => 1,
            'failed_job_ids' => '[]',
            'created_at' => now()->timestamp,
            'cancelled_at' => now()->timestamp,
            'finished_at' => now()->timestamp,
        ]);

        $this->artisan('videos:retry', ['video' => [$video->id]])->assertSuccessful();

        // PrepareVideoJob reads any such row as "fan-out already ran" and plans nothing.
        expect($video->fresh()->status)->toBe('pending')
            ->and(DB::table('job_batches')->where('name', 'like', "encode video {$video->id} %")->count())->toBe(0);
    });

    it('leaves another video\'s batches alone', function () {
        $video = failedVideo();
        $other = failedVideo();

        DB::table('job_batches')->insert([
            'id' => 'batch-other',
            'name' => "encode video {$other->id} video-processing-intel",
            'total_jobs' => 10,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'created_at' => now()->timestamp,
            'finished_at' => now()->timestamp,
        ]);

        $this->artisan('videos:retry', ['video' => [$video->id]])->assertSuccessful();

        expect(DB::table('job_batches')->where('id', 'batch-other')->count())->toBe(1);
    });

    it('drops derived streams and outputs with --reprobe so the source is probed again', function () {
        $video = failedVideo();

        $this->artisan('videos:retry', ['video' => [$video->id], '--reprobe' => true])->assertSuccessful();

        // What PrepareVideoJob checks before re-running CreateVideoStreamsService.
        expect($video->streams()->where('type', '!=', 'original')->count())->toBe(0)
            ->and($video->streams()->where('type', 'original')->count())->toBe(1)
            ->and($video->outputs()->count())->toBe(0);
    });

    it('changes nothing with --dry-run', function () {
        $video = failedVideo();

        DB::table('job_batches')->insert([
            'id' => 'batch-1',
            'name' => "encode video {$video->id} video-processing-intel",
            'total_jobs' => 10,
            'pending_jobs' => 0,
            'failed_jobs' => 1,
            'failed_job_ids' => '[]',
            'created_at' => now()->timestamp,
            'finished_at' => now()->timestamp,
        ]);

        $this->artisan('videos:retry', ['video' => [$video->id], '--dry-run' => true])->assertSuccessful();

        expect($video->fresh()->status)->toBe('failed')
            ->and($video->streams()->whereNotNull('error_log')->count())->toBe(1)
            ->and(DB::table('job_batches')->count())->toBe(1);
    });

    it('reports an unknown video instead of failing silently', function () {
        $this->artisan('videos:retry', ['video' => ['01ZZZZZZZZZZZZZZZZZZZZZZZZ']])->assertFailed();
    });
});
