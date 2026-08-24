<?php

/**
 * The fallback attribution for minted links. A session or personal-token request that names no
 * `tid` is the account user acting as their own viewer — the panel never sends one — so the link
 * carries their ULID and the traffic lands in the analytics instead of the unattributed bucket.
 * A project key without a `tid` stays unattributed on purpose: there is no user behind it, and
 * inventing one would pollute the integrator's own id space.
 */

use App\Models\Node;
use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use App\Services\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function attributableVideo(): Video
{
    return Video::create([
        'user_id' => test()->user->id,
        'project_id' => test()->project->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
    ]);
}

function attributableTrack(Video $video): Stream
{
    $stream = $video->streams()->create([
        'path' => "{$video->ulid}/audio/".strtoupper((string) Str::ulid()).'.mp4',
        'type' => 'audio',
        'meta' => [],
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
});

it('attributes a session-minted download link to the user when no tracking id is sent', function () {
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);

    $stream = attributableTrack(attributableVideo());

    $url = $this->postJson("/api/streams/{$stream->ulid}/download")->assertOk()->json('data.url');

    expect(parse_url($url, PHP_URL_PATH))->toStartWith("/{$this->user->ulid}/");
});

it('lets an explicit tracking id override the session fallback', function () {
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);

    $stream = attributableTrack(attributableVideo());

    $url = $this->postJson("/api/streams/{$stream->ulid}/download", ['tracking_id' => 'client-42'])
        ->assertOk()->json('data.url');

    expect(parse_url($url, PHP_URL_PATH))->toStartWith('/client-42/');
});

it('leaves a project-key download link unattributed when no tracking id is sent', function () {
    // A real Bearer token, not acting-as: the fallback keys off who the actor is, and a project
    // key authenticates as the project itself.
    $key = app(ApiTokenService::class)->regenerateProjectKey($this->project)->plainTextToken;

    $video = attributableVideo();
    $stream = attributableTrack($video);

    $url = $this->withToken($key)->postJson("/api/streams/{$stream->ulid}/download")
        ->assertOk()->json('data.url');

    expect(parse_url($url, PHP_URL_PATH))->toStartWith("/{$video->ulid}/download/");
});

it('attributes a session-minted playback link to the user when no tracking id is sent', function () {
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);

    $video = attributableVideo();
    $output = Output::create(['video_id' => $video->id, 'status' => 'completed']);
    $output->recordFormats(['dash', 'hls']);

    $url = $this->postJson("/api/outputs/{$output->ulid}")->assertOk()->json('data.url');

    expect(parse_url($url, PHP_URL_PATH))->toStartWith("/{$this->user->ulid}/{$video->ulid}/");
});
