<?php

/**
 * A failed package or sidecar pass settles the video at once, with its own cause. The completion
 * used to throw on the reason it was given and roll the FAILED status back, leaving the video for
 * the reaper 41 minutes later with a generic one.
 */

use App\Jobs\EncodeSidecarTracksJob;
use App\Jobs\PackageVideoJob;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('chunks'));

function failureReason(int $videoId): ?string
{
    return Activity::where('subject_id', $videoId)->where('event', 'video_failed')->first()?->properties['reason'];
}

it('records a failed package pass with its reason', function () {
    $video = projectVideo(Project::factory()->create(), status: 'uploading');
    $video->outputs()->create(['status' => 'running']);

    (new PackageVideoJob($video->id))->failed(new RuntimeException('shaka-packager died'));

    expect($video->fresh()->status)->toBe('failed')
        ->and(failureReason($video->id))->toBe('shaka-packager died');
});

it('records a failed sidecar pass with its reason', function () {
    $video = projectVideo(Project::factory()->create(), status: 'running');
    $video->outputs()->create(['status' => 'running']);
    $video->streams()->create(['path' => "{$video->ulid}/audio/a.mp4", 'type' => 'audio', 'meta' => []]);

    (new EncodeSidecarTracksJob($video->id, '/tmp/src.mkv'))->failed(new RuntimeException('opus encoder died'));

    expect($video->fresh()->status)->toBe('failed')
        ->and(failureReason($video->id))->toBe('opus encoder died');
});

it('never holds the package lock forever', function () {
    $job = new PackageVideoJob(1);

    expect($job->uniqueFor)->toBeGreaterThanOrEqual($job->timeout * $job->tries);
});
