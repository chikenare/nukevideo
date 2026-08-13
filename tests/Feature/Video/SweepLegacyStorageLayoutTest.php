<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function sweepVideo(array $keys): Video
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

    foreach ($keys as $key => $body) {
        Storage::disk('s3')->put("{$video->ulid}/{$key}", $body);
    }

    return $video;
}

beforeEach(fn () => Storage::fake('s3'));

it('deletes a flat object once its zoned copy matches', function () {
    $video = sweepVideo([
        'thumbnail.jpg' => 'bytes',
        'assets/thumbnail.jpg' => 'bytes',
    ]);

    $this->artisan('videos:sweep-legacy-layout', ['video' => [$video->ulid]])
        ->expectsConfirmation('Delete the pre-zone objects of 1 video(s)? This cannot be undone.', 'yes')
        ->assertSuccessful();

    expect(Storage::disk('s3')->exists("{$video->ulid}/thumbnail.jpg"))->toBeFalse()
        ->and(Storage::disk('s3')->exists("{$video->ulid}/assets/thumbnail.jpg"))->toBeTrue();
});

it('holds back the whole video when one object is unverified', function () {
    $video = sweepVideo([
        'thumbnail.jpg' => 'bytes',
        'assets/thumbnail.jpg' => 'bytes',
        'video/FILE.mp4' => 'the master',   // never copied
    ]);

    // Deleting the verified half would still leave the video broken, and would destroy the only
    // remaining copy of the rest.
    $this->artisan('videos:sweep-legacy-layout', ['video' => [$video->ulid]])
        ->expectsConfirmation('Delete the pre-zone objects of 1 video(s)? This cannot be undone.', 'yes')
        ->expectsOutputToContain('not verified in the new layout')
        ->assertSuccessful();

    expect(Storage::disk('s3')->exists("{$video->ulid}/thumbnail.jpg"))->toBeTrue()
        ->and(Storage::disk('s3')->exists("{$video->ulid}/video/FILE.mp4"))->toBeTrue();
});

it('refuses a copy that exists but is the wrong size', function () {
    $video = sweepVideo([
        'thumbnail.jpg' => 'the whole thing',
        'assets/thumbnail.jpg' => 'trunc',    // a copy that died halfway
    ]);

    // Existence alone would accept this: the sizes come from the same listing, so checking them
    // costs nothing and is the difference between a safe delete and losing the original.
    $this->artisan('videos:sweep-legacy-layout', ['video' => [$video->ulid]])
        ->expectsConfirmation('Delete the pre-zone objects of 1 video(s)? This cannot be undone.', 'yes')
        ->assertSuccessful();

    expect(Storage::disk('s3')->exists("{$video->ulid}/thumbnail.jpg"))->toBeTrue();
});

it('never deletes anything already inside a zone', function () {
    $video = sweepVideo(['play/manifest.mpd' => 'bytes', 'assets/thumbnail.jpg' => 'bytes']);

    $this->artisan('videos:sweep-legacy-layout', ['video' => [$video->ulid]])
        ->expectsConfirmation('Delete the pre-zone objects of 1 video(s)? This cannot be undone.', 'yes')
        ->expectsOutputToContain('nothing left from the old layout')
        ->assertSuccessful();

    expect(Storage::disk('s3')->exists("{$video->ulid}/play/manifest.mpd"))->toBeTrue()
        ->and(Storage::disk('s3')->exists("{$video->ulid}/assets/thumbnail.jpg"))->toBeTrue();
});

it('deletes nothing on a dry run', function () {
    $video = sweepVideo(['thumbnail.jpg' => 'bytes', 'assets/thumbnail.jpg' => 'bytes']);

    $this->artisan('videos:sweep-legacy-layout', ['video' => [$video->ulid], '--dry-run' => true])
        ->expectsOutputToContain('Would delete 1')
        ->assertSuccessful();

    expect(Storage::disk('s3')->exists("{$video->ulid}/thumbnail.jpg"))->toBeTrue();
});
