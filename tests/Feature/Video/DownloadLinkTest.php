<?php

use App\Models\Node;
use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function downloadableVideo(string $status = 'completed'): Video
{
    $video = Video::create([
        'user_id' => test()->user->id,
        'project_id' => test()->project->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => $status,
    ]);

    Output::create(['video_id' => $video->id, 'status' => 'completed']);

    return $video;
}

function track(Video $video, string $type, array $attributes = []): Stream
{
    $stream = $video->streams()->create([
        // `streams.path` is unique, and the file ULID is minted separately from the stream's own.
        'path' => "{$video->ulid}/{$type}/".strtoupper((string) Str::ulid()).'.mp4',
        'type' => $type,
        'meta' => [],
        ...$attributes,
    ]);

    Storage::disk('s3')->put($stream->storedPath($video), 'bytes');

    return $stream;
}

beforeEach(function () {
    Storage::fake('s3');

    Node::create([
        'name' => 'edge',
        'user' => 'root',
        'ip_address' => '10.0.0.1',
        'hostname' => 'edge.example.com',
        'type' => 'proxy',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('mints a link for a track', function () {
    $video = downloadableVideo();
    $stream = track($video, 'audio', ['name' => 'Español', 'language' => 'es']);

    $this->postJson("/api/streams/{$stream->ulid}/download")
        ->assertOk()
        ->assertJsonPath('data.type', 'audio')
        ->assertJsonPath('data.filename', basename($stream->path))
        ->assertJsonStructure(['data' => ['url', 'expiresAt', 'filename', 'type', 'size']]);
});

it('names each track by its stored file, so two never collide', function () {
    $video = downloadableVideo();
    $tall = track($video, 'video', ['height' => 1080]);
    $short = track($video, 'video', ['height' => 720]);

    // Renditions carry no label of their own, so any human-facing name would have to be invented
    // and could repeat. The stored name is a ULID and is unique by construction.
    $first = $this->postJson("/api/streams/{$tall->ulid}/download")->assertOk()->json('data.filename');
    $second = $this->postJson("/api/streams/{$short->ulid}/download")->assertOk()->json('data.filename');

    expect($first)->toBe(basename($tall->path))
        ->and($second)->toBe(basename($short->path))
        ->and($first)->not->toBe($second);
});

it('refuses to hand out the untouched original', function () {
    $video = downloadableVideo();
    $original = $video->streams()->create([
        'path' => "tmp-videos/{$video->ulid}.mkv",
        'type' => 'original',
        'meta' => [],
    ]);

    $this->postJson("/api/streams/{$original->ulid}/download")->assertStatus(422);
});

it('refuses while the video is still processing', function () {
    $video = downloadableVideo('running');
    $stream = track($video, 'audio');

    $this->postJson("/api/streams/{$stream->ulid}/download")->assertStatus(409);
});

it('404s when the track was never retained', function () {
    $video = downloadableVideo();
    $stream = track($video, 'video', ['height' => 720]);

    // A template with keep_processed_files off leaves the row without an object.
    Storage::disk('s3')->delete($stream->storedPath($video));

    $this->postJson("/api/streams/{$stream->ulid}/download")->assertStatus(404);
});

it('does not hand a track to another project', function () {
    $video = downloadableVideo();
    $stream = track($video, 'audio');

    $other = Project::factory()->for(User::factory()->create())->create();
    $this->withHeader('X-Project-Ulid', $other->ulid);

    $this->postJson("/api/streams/{$stream->ulid}/download")->assertStatus(404);
});

it('answers 503 rather than 500 when no node can serve it', function () {
    $video = downloadableVideo();
    $stream = track($video, 'audio');

    Node::query()->update(['is_active' => false]);

    $this->postJson("/api/streams/{$stream->ulid}/download")->assertStatus(503);
});

it('carries the caller tracking id into the link on Bunny', function () {
    CdnSettings::fake(['provider' => 'bunny', 'providers' => [
        'self_hosted' => [],
        'bunny' => ['host' => 'cdn.example.com', 'token_key' => 'k', 'token_window' => 600],
    ]]);

    $video = downloadableVideo();
    $stream = track($video, 'audio');

    $url = $this->postJson("/api/streams/{$stream->ulid}/download", ['tid' => 'client-42'])
        ->assertOk()->json('data.url');

    expect($url)->toContain('tid=client-42');
});

it('carries the tracking id on the self-hosted edge too, unsigned', function () {
    $video = downloadableVideo();
    $stream = track($video, 'audio');

    $url = $this->postJson("/api/streams/{$stream->ulid}/download", ['tid' => 'client-42'])
        ->assertOk()->json('data.url');

    // Appended rather than signed: this edge compares its ACL against the request URI, which
    // excludes the query, so the parameter neither strengthens nor breaks the token. It exists to
    // reach the `bandwidth` log line, which is what attributes the transfer.
    // Also pins the separator: with no token secret configured `sign()` returns a URL with no
    // query at all, and appending `&` there would produce a malformed one.
    expect($url)->toContain('tid=client-42')
        ->and(parse_url($url, PHP_URL_QUERY))->toContain('tid=client-42');
});

it('rejects a tracking id that could reshape the signed parameters', function () {
    $video = downloadableVideo();
    $stream = track($video, 'audio');

    // Bunny serialises the signed parameters as `key=value` joined by `&`; letting either character
    // through would let a caller split its value into parameters of its own.
    foreach (['a&b=c', 'a=b', 'has space', str_repeat('x', 65)] as $bad) {
        $this->postJson("/api/streams/{$stream->ulid}/download", ['tid' => $bad])->assertStatus(422);
    }
});
