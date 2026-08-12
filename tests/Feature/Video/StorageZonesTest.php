<?php

use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function zonedVideo(): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    return Video::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
    ]);
}

it('gives every zone its own prefix', function () {
    $video = zonedVideo();

    expect($video->playPrefix())->toBe("{$video->ulid}/play")
        ->and($video->downloadPrefix())->toBe("{$video->ulid}/download")
        ->and($video->assetsPrefix())->toBe("{$video->ulid}/assets")
        ->and($video->sourcePrefix())->toBe("{$video->ulid}/source");
});

it('keeps every zone under the one prefix a delete sweeps', function () {
    $video = zonedVideo();

    // VideoObserver dispatches a single DeleteResourceWithPath for the video's prefix, so a zone
    // that escaped it would leak its objects forever.
    foreach ([$video->playPrefix(), $video->downloadPrefix(), $video->assetsPrefix(), $video->sourcePrefix()] as $zone) {
        expect($zone)->toStartWith("{$video->ulid}/");
    }
});

it('keeps the public asset URL flat while the object lives in its zone', function () {
    $video = zonedVideo();

    // The route whitelists these filenames and the responses are cached for a week, so the URL is
    // frozen even though the object moved.
    expect(Video::assetPath($video->ulid, 'thumbnail.jpg'))->toBe("{$video->ulid}/thumbnail.jpg")
        ->and($video->assetKey('thumbnail.jpg'))->toBe("{$video->ulid}/assets/thumbnail.jpg");
});

it('names a stored track from its path, never from the stream ulid', function () {
    $video = zonedVideo();

    $stream = Stream::create([
        'video_id' => $video->id,
        'type' => 'video',
        'path' => "{$video->ulid}/video/FILEULID000000000000000000.mp4",
        'meta' => [],
    ]);

    // The file ULID and the stream ULID are minted separately; using the wrong one silently points
    // at an object that does not exist.
    expect($stream->storedPath($video))->toBe("{$video->ulid}/download/video/FILEULID000000000000000000.mp4")
        ->and($stream->storedPath($video))->not->toContain($stream->ulid)
        ->and($stream->segmentsPath($video))->toBe("{$video->ulid}/play/{$stream->ulid}");
});

it('archives the original outside everything a manifest can name', function () {
    $video = zonedVideo();

    $original = Stream::create([
        'video_id' => $video->id,
        'type' => 'original',
        'path' => "tmp-videos/{$video->ulid}.mkv",
        'meta' => [],
    ]);

    expect($original->archivePath($video))->toBe("{$video->ulid}/source/{$original->ulid}.mkv")
        ->and($original->archivePath($video))->not->toStartWith($video->playPrefix());
});

it('resolves the manifest prefix through the output as well', function () {
    $video = zonedVideo();
    $output = Output::create(['video_id' => $video->id, 'status' => 'completed']);

    expect($output->packagePrefix())->toBe("{$video->ulid}/play")
        ->and($output->manifestPath('dash'))->toBe("{$video->ulid}/play/{$output->ulid}.mpd")
        ->and($output->manifestPath('hls', 720))->toBe("{$video->ulid}/play/{$output->ulid}.720.m3u8");
});
