<?php

/**
 * A chunk job can outlive its run: cancelling a batch only stops the jobs not yet started, and
 * `videos:retry` deletes the failed run's batch rows. Such a job must not touch the retry.
 */

use App\Jobs\ProcessChunkJob;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

function chunkJobOnRetriedVideo(?string $batchRow): array
{
    $video = projectVideo(Project::factory()->create(), status: 'running');
    $stream = $video->streams()->create(['path' => "{$video->ulid}/video/r.mp4", 'type' => 'video', 'meta' => [], 'height' => 720]);

    if ($batchRow !== null) {
        DB::table('job_batches')->insert([
            'id' => $batchRow, 'name' => "encode video {$video->id} video-processing",
            'total_jobs' => 5, 'pending_jobs' => 5, 'failed_jobs' => 1, 'failed_job_ids' => '[]', 'options' => serialize([]),
            'created_at' => now()->timestamp, 'cancelled_at' => now()->timestamp, 'finished_at' => now()->timestamp,
        ]);
    }

    $job = (new ProcessChunkJob($stream->id, '/tmp/src.mkv', 3, 30.0, 40.0))->withBatchId('run-1');

    return [$video, $job];
}

it('does not fail a retried video when a job of the old run fails late', function () {
    [$video, $job] = chunkJobOnRetriedVideo(batchRow: null);

    $job->failed(new RuntimeException('ffmpeg died'));

    expect($video->fresh()->status)->toBe('running');
});

it('still fails the video on the failure that cancelled its own run', function () {
    // The framework cancels the batch before calling failed() on the job that failed it.
    [$video, $job] = chunkJobOnRetriedVideo(batchRow: 'run-1');

    $job->failed(new RuntimeException('ffmpeg died'));

    expect($video->fresh()->status)->toBe('failed');
});

it('never encodes a window for a run that was retried', function () {
    Process::fake();
    [, $job] = chunkJobOnRetriedVideo(batchRow: null);

    $job->handle();

    Process::assertNothingRan();
});
