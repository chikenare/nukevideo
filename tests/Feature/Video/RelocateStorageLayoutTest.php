<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const STREAM_ULID = '01HZXW3V5N8Q9R2T4Y6B8D0F1G';
const OUTPUT_ULID = '01HZXW3V5N8Q9R2T4Y6B8D0F2H';

function flatVideo(array $keys): Video
{
    $user = User::factory()->create();

    $video = Video::create([
        'user_id' => $user->id,
        'project_id' => Project::factory()->for($user)->create()->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
    ]);

    foreach ($keys as $key) {
        Storage::disk('s3')->put("{$video->ulid}/{$key}", 'bytes');
    }

    return $video;
}

beforeEach(fn () => Storage::fake('s3'));

it('routes every kind of object to its zone', function () {
    $video = flatVideo([
        STREAM_ULID.'/init.mp4',          // segments
        OUTPUT_ULID.'.mpd',               // manifest
        'video/FILE.mp4',                 // master
        'thumbnail.jpg',                  // image
        STREAM_ULID.'.mkv',               // retained original: same 26 chars, but a file
    ]);

    // The tally, not a sample: sorted alphabetically the first keys are always manifests and
    // segments, so a misrouted image would hide behind them.
    $this->artisan('videos:relocate-storage', ['video' => [$video->ulid], '--dry-run' => true])
        ->expectsOutputToContain('play       2')
        ->expectsOutputToContain('download   1')
        ->expectsOutputToContain('assets     1')
        ->expectsOutputToContain('original   1')
        ->assertSuccessful();
});

it('tells a segment directory apart from the retained original', function () {
    // Both start with 26 base32 characters; only the slash says which is which. Getting this wrong
    // files the untouched upload into the zone a playback token can reach.
    $video = flatVideo([STREAM_ULID.'/seg-1.m4s', STREAM_ULID.'.mkv']);

    $this->artisan('videos:relocate-storage', ['video' => [$video->ulid], '--dry-run' => true])
        ->expectsOutputToContain('play       1')
        ->expectsOutputToContain('original   1')
        ->assertSuccessful();
});

it('plans nothing for a video already relocated', function () {
    $video = flatVideo(['play/'.OUTPUT_ULID.'.mpd', 'download/audio/FILE.mp4', 'assets/thumbnail.jpg']);

    $this->artisan('videos:relocate-storage', ['video' => [$video->ulid], '--dry-run' => true])
        ->expectsOutputToContain('already in the zoned layout')
        ->assertSuccessful();
});

it('leaves a video that is still processing alone', function () {
    $video = flatVideo(['thumbnail.jpg']);
    $video->update(['status' => 'running']);

    // The packager is syncing into this very prefix; a listing here would be a half-written tree.
    $this->artisan('videos:relocate-storage', ['video' => [$video->ulid], '--dry-run' => true])
        ->expectsOutputToContain('still processing, skipping')
        ->assertSuccessful();
});

it('does not touch an object it cannot classify', function () {
    $video = flatVideo(['something-unexpected.bin']);

    // Left where they are rather than guessed into a zone — but a dry run must SAY so, or the
    // silence reads as "everything is accounted for".
    $this->artisan('videos:relocate-storage', ['video' => [$video->ulid], '--dry-run' => true])
        ->expectsOutputToContain('already in the zoned layout')
        ->assertSuccessful();

    // The reporting path is exercised where there is also something to move.
    $mixed = flatVideo(['thumbnail.jpg', 'something-unexpected.bin']);

    $this->artisan('videos:relocate-storage', ['video' => [$mixed->ulid], '--dry-run' => true])
        ->expectsOutputToContain('not classified, staying put')
        ->assertSuccessful();
});
