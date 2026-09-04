<?php

/**
 * The video-level playback mint. Its reason to exist is the same as the batch download's — auth,
 * the project resolution, the status check and the delivery node are per VIDEO — plus one the
 * download batch does not have: a playback token is scoped to the manifest's directory, and every
 * output of a video packages into the same one, so the whole answer authorizes the same bytes.
 *
 * The answer is FLAT: one entry per (output, format), each one self-sufficient enough to hand
 * straight to a player.
 */

use App\Models\Node;
use App\Models\Output;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Services\Cdn\TrackingRegistry;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function playVideo(string $status = 'completed'): Video
{
    return Video::create([
        'user_id' => test()->user->id,
        'project_id' => test()->project->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => $status,
    ]);
}

/**
 * An output as packaging leaves it: a frozen format list and the renditions it packages, which is
 * what the ladder cap is resolved against.
 *
 * @param  list<int>  $heights
 * @param  list<string>|null  $formats
 */
function playOutput(
    Video $video,
    array $heights = [1080],
    ?array $formats = ['hls', 'dash'],
    string $status = 'completed',
    string $videoCodec = 'libx264',
    array $audioLanguages = [],
    string $audioCodec = 'aac',
): Output {
    $output = Output::create(['video_id' => $video->id, 'status' => $status]);
    $output->forceFill(['packaged_formats' => $formats])->save();

    // One codec's ABR ladder, which is what an output is: the variants differ in resolution only.
    foreach ($heights as $height) {
        $stream = $video->streams()->create([
            'path' => "{$video->ulid}/play/".strtoupper((string) Str::ulid()),
            'type' => 'video',
            'meta' => [],
            'input_params' => ['video_codec' => $videoCodec],
            'height' => $height,
            'width' => (int) ($height * 16 / 9),
            'package_size' => 10,
        ]);

        $output->streams()->attach($stream->id);
    }

    // Every language of an output shares its codec — the template names one `audio_codec` and
    // applies it to all of them.
    foreach ($audioLanguages as $language) {
        $stream = $video->streams()->create([
            'path' => "{$video->ulid}/play/".strtoupper((string) Str::ulid()),
            'type' => 'audio',
            'meta' => [],
            'input_params' => ['audio_codec' => $audioCodec],
            'language' => $language,
            'package_size' => 10,
        ]);

        $output->streams()->attach($stream->id);
    }

    return $output;
}

beforeEach(function () {
    Storage::fake('s3');

    CdnSettings::fake([
        'provider' => 'self_hosted',
        'providers' => ['self_hosted' => ['token_secret' => bin2hex('secret'), 'token_window' => 3600], 'bunny' => []],
    ]);

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

it('answers one flat source per output and format', function () {
    $video = playVideo();
    $both = playOutput($video);
    $dashOnly = playOutput($video, formats: ['dash']);

    $data = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data');

    // Three manifests out of two outputs, not two rows holding a map of formats.
    expect($data['sources'])->toHaveCount(3)
        ->and(array_column($data['sources'], 'format'))->toBe(['hls', 'dash', 'dash'])
        ->and(array_column($data['sources'], 'outputUlid'))
        ->toBe([$both->ulid, $both->ulid, $dashOnly->ulid]);

    // The manifest is named after its own output, in the video's single play prefix.
    expect($data['sources'][0]['url'])->toContain("/{$video->ulid}/play/{$both->ulid}.m3u8")
        ->and($data['sources'][2]['url'])->toContain("/{$video->ulid}/play/{$dashOnly->ulid}.mpd");

    // The published contract, pinned: this is what `docs/api/videos.md` documents, and an
    // integrator reads a field that is not here as a field that does not exist.
    expect(array_keys($data))->toBe(['thumbnailUrl', 'storyboardUrl', 'expiresAt', 'sources'])
        ->and(array_keys($data['sources'][0]))
        ->toBe(['url', 'format', 'outputUlid', 'videoCodec', 'audioCodec']);
});

it('reports the decodable codec of each source, not the encoder that wrote it', function () {
    $video = playVideo();
    // Three ways to spell H.264 and one AV1: what a player can act on is the bitstream, so the
    // NVENC and QSV encoders must not leak into the answer.
    playOutput($video, formats: ['hls'], videoCodec: 'h264_nvenc', audioLanguages: ['es', 'en'], audioCodec: 'libfdk_aac');
    playOutput($video, formats: ['dash'], videoCodec: 'libsvtav1', audioLanguages: ['es'], audioCodec: 'libopus');

    $sources = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data.sources');

    // One value per source, not one per stream: the first output carries two languages and still
    // reports the single codec they share.
    expect($sources[0]['videoCodec'])->toBe('h264')
        ->and($sources[0]['audioCodec'])->toBe('aac')
        ->and($sources[1]['videoCodec'])->toBe('av1')
        ->and($sources[1]['audioCodec'])->toBe('opus');
});

it('reports no audio codec for an output that carries no audio', function () {
    $video = playVideo();
    playOutput($video, formats: ['hls']);

    $sources = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data.sources');

    expect($sources[0]['audioCodec'])->toBeNull();
});

it('hoists the thumbnail and the storyboard out of the outputs', function () {
    $video = playVideo();
    playOutput($video);

    $data = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data');

    expect($data['thumbnailUrl'])->toContain("{$video->ulid}/assets/thumbnail.jpg")
        ->and($data['storyboardUrl'])->toContain("{$video->ulid}/assets/storyboard.vtt")
        ->and($data['sources'][0])->not->toHaveKey('thumbnailUrl')
        ->and($data['expiresAt'])->not->toBeEmpty();
});

it('leaves out an output that never finished packaging, without costing the others their links', function () {
    $video = playVideo();
    $good = playOutput($video, formats: ['hls']);
    // A failed output keeps `packaged_formats` null, so `formats()` falls back to a live
    // computation and would happily name a manifest that was never written. A video is completed
    // once ONE output succeeded, so this pair is an ordinary state, not a contradiction.
    playOutput($video, formats: null, status: 'failed');

    $data = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data');

    expect($data['sources'])->toHaveCount(1)
        ->and($data['sources'][0]['outputUlid'])->toBe($good->ulid);
});

it('answers with no sources at all rather than an error when nothing can be served', function () {
    $video = playVideo();
    playOutput($video, formats: []);

    $data = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data');

    // Still a 200 with the video's assets: the video exists and the caller is entitled to it,
    // there is simply nothing to play.
    expect($data['sources'])->toBe([])
        ->and($data['thumbnailUrl'])->not->toBeEmpty();
});

it('resolves the ladder cap against each output on its own', function () {
    $video = playVideo();
    $tall = playOutput($video, heights: [480, 720, 1080], formats: ['hls']);
    $short = playOutput($video, heights: [360, 480], formats: ['hls']);

    $sources = $this->postJson("/api/videos/{$video->ulid}/play", ['resolution' => 720])
        ->assertOk()->json('data.sources');

    // 720 is a real rung of the first output and above every rung of the second, where the
    // uncapped master already is the answer. The cap is not echoed back — it only shows in which
    // manifest was signed.
    expect($sources[0]['url'])->toContain("{$tall->ulid}.720.m3u8")
        ->and($sources[1]['url'])->toContain("{$short->ulid}.m3u8");
});

it('attributes every link in the answer to the tracking id', function () {
    $video = playVideo();
    playOutput($video);
    playOutput($video, formats: ['dash']);

    $sources = $this->postJson("/api/videos/{$video->ulid}/play", ['trackingId' => 'client-42'])
        ->assertOk()->json('data.sources');

    $registry = app(TrackingRegistry::class);
    $urls = collect($sources)->pluck('url');

    expect($urls)->toHaveCount(3);

    foreach ($urls as $url) {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        expect($registry->resolve(hash('sha256', $query['__hdnea__'])))->toBe('client-42');
    }
});

it('does not query per output', function () {
    $video = playVideo();
    foreach (range(1, 6) as $i) {
        playOutput($video, heights: [360 * $i]);
    }

    DB::enableQueryLog();
    $this->postJson("/api/videos/{$video->ulid}/play")->assertOk();
    $log = collect(DB::getQueryLog())->pluck('query');

    // The renditions come in one eager load, and the fleet is resolved once for the request.
    expect($log->filter(fn ($q) => str_contains($q, '"streams"'))->count())->toBe(1)
        ->and($log->filter(fn ($q) => str_contains($q, '"nodes"'))->count())->toBe(1);
});

it('refuses the whole video while it is still processing', function () {
    $video = playVideo('running');
    playOutput($video);

    $this->postJson("/api/videos/{$video->ulid}/play")->assertStatus(409);
});

it('answers 503 for the whole video when no node can serve it', function () {
    $video = playVideo();
    playOutput($video);

    Node::query()->update(['is_active' => false]);

    $this->postJson("/api/videos/{$video->ulid}/play")->assertStatus(503);
});

it('does not hand another project a video of ours', function () {
    $video = playVideo();
    playOutput($video);

    $other = Project::factory()->for(User::factory()->create())->create();
    $this->withHeader('X-Project-Ulid', $other->ulid);

    $this->postJson("/api/videos/{$video->ulid}/play")->assertNotFound();
});

it('validates the body it takes', function () {
    $video = playVideo();
    playOutput($video);

    $this->postJson("/api/videos/{$video->ulid}/play", ['resolution' => 12])->assertStatus(422);
    $this->postJson("/api/videos/{$video->ulid}/play", ['ip' => 'nope'])->assertStatus(422);
    $this->postJson("/api/videos/{$video->ulid}/play", ['trackingId' => 'no spaces'])->assertStatus(422);
});
