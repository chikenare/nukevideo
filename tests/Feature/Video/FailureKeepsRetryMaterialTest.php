<?php

use App\Jobs\CleanupVideoResourcesJob;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function stagedVideo(string $status): Video
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
        'status' => $status,
    ]);

    Storage::disk('chunks')->put($video->sourceMirrorPath('mkv'), 'source');
    Storage::disk('chunks')->put($video->chunksDir().'/stream/chunk_000.mp4', 'chunk');
    Storage::disk('chunks')->put($video->finalDir().'/stream/audio.mp4', 'audio');

    return $video;
}

beforeEach(function () {
    Storage::fake('chunks');
    Storage::fake('s3');
});

describe('cleanup after a failure', function () {
    it('keeps the source and staged work so a retry stays cheap', function () {
        // Wiping these is what turned one retry into a full re-download plus 54 re-encodes,
        // and made in-flight chunk jobs die on a 404 against the file it had just removed.
        $video = stagedVideo('failed');

        (new CleanupVideoResourcesJob($video->ulid))->handle();

        expect(Storage::disk('chunks')->exists($video->sourceMirrorPath('mkv')))->toBeTrue()
            ->and(Storage::disk('chunks')->exists($video->chunksDir().'/stream/chunk_000.mp4'))->toBeTrue()
            ->and(Storage::disk('chunks')->exists($video->finalDir().'/stream/audio.mp4'))->toBeTrue();
    });

    it('reclaims everything once the video completed', function () {
        $video = stagedVideo('completed');

        (new CleanupVideoResourcesJob($video->ulid))->handle();

        expect(Storage::disk('chunks')->exists($video->sourceMirrorPath('mkv')))->toBeFalse()
            ->and(Storage::disk('chunks')->exists($video->chunksDir().'/stream/chunk_000.mp4'))->toBeFalse()
            ->and(Storage::disk('chunks')->exists($video->finalDir().'/stream/audio.mp4'))->toBeFalse();
    });

    it('still sweeps an orphan with no video row, which nothing can retry', function () {
        $video = stagedVideo('failed');
        $mirror = $video->sourceMirrorPath('mkv');
        $ulid = $video->ulid;
        $video->forceDelete();

        (new CleanupVideoResourcesJob($ulid))->handle();

        expect(Storage::disk('chunks')->exists($mirror))->toBeFalse();
    });

    it('leaves an active video alone entirely', function () {
        $video = stagedVideo('running');

        (new CleanupVideoResourcesJob($video->ulid))->handle();

        expect(Storage::disk('chunks')->exists($video->sourceMirrorPath('mkv')))->toBeTrue();
    });
});
