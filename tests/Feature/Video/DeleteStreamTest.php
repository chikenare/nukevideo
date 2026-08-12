<?php

/**
 * Two ways a delete used to leave a published manifest that no player can use: dropping the last
 * audio track kept every variant pointing at an AUDIO group with no members, and the
 * "last video rendition" guard counted video-wide while renditions attach per output, so an
 * output holding a single rendition could be stripped down to no video at all.
 *
 * Fixtures come from UpdateStreamTest — the same trimmed shaka-packager v3.7.2 output.
 */

use App\Models\Output;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');

    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

    $template = Template::create([
        'name' => 'Template',
        'query' => [],
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);

    $this->video = Video::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'template_id' => $template->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
    ]);

    $this->output = Output::create(['video_id' => $this->video->id, 'status' => 'completed']);

    $this->make = fn (string $type, array $attributes) => $this->video->streams()->create([
        'path' => "{$this->video->ulid}/{$type}/".Str::random(8).'.mp4',
        'type' => $type,
        'meta' => [],
        ...$attributes,
    ]);

    $this->videoStream = ($this->make)('video', ['name' => 'Video', 'width' => 1920, 'height' => 800]);
    $this->audio = ($this->make)('audio', ['name' => 'Espanol', 'language' => 'es-419', 'channels' => 2]);
    $this->subtitle = ($this->make)('subtitle', ['name' => 'English', 'language' => 'en']);

    $this->output->streams()->attach([$this->videoStream->id, $this->audio->id]);

    $ulids = [
        '{video}' => $this->videoStream->ulid,
        '{audio}' => $this->audio->ulid,
        '{sub}' => $this->subtitle->ulid,
    ];

    $this->dashPath = "{$this->video->playPrefix()}/{$this->output->ulid}.mpd";
    $this->hlsPath = "{$this->video->playPrefix()}/{$this->output->ulid}.m3u8";

    Storage::disk('s3')->put($this->dashPath, dashFixture($ulids));
    Storage::disk('s3')->put($this->hlsPath, hlsFixture($ulids));

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('drops the AUDIO group reference when the last audio track goes', function () {
    $this->deleteJson("/api/streams/{$this->audio->ulid}")->assertOk();

    $hls = Storage::disk('s3')->get($this->hlsPath);

    expect($hls)->not->toContain('TYPE=AUDIO')
        ->and($hls)->not->toContain('AUDIO="audio"')
        // The subtitle group still has a member, so its reference stays.
        ->and($hls)->toContain('SUBTITLES="subs"');
});

it('keeps the AUDIO reference while another audio track remains', function () {
    $second = ($this->make)('audio', ['name' => 'English', 'language' => 'en', 'channels' => 2]);
    $second->outputs()->attach($this->output->id);

    // The group only survives if a member is still listed, so the second track has to be in
    // the manifest too — the fixture ships one audio line.
    $hls = Storage::disk('s3')->get($this->hlsPath);
    Storage::disk('s3')->put($this->hlsPath, str_replace(
        '#EXT-X-MEDIA:TYPE=SUBTITLES',
        "#EXT-X-MEDIA:TYPE=AUDIO,URI=\"{$second->ulid}/index.m3u8\",GROUP-ID=\"audio\",LANGUAGE=\"en\",NAME=\"English\",DEFAULT=NO,AUTOSELECT=YES,CHANNELS=\"2\"\n#EXT-X-MEDIA:TYPE=SUBTITLES",
        $hls,
    ));

    $this->deleteJson("/api/streams/{$this->audio->ulid}")->assertOk();

    $edited = Storage::disk('s3')->get($this->hlsPath);
    expect($edited)->toContain('AUDIO="audio"')
        ->and($edited)->toContain('NAME="English"')
        ->and($edited)->not->toContain('NAME="Espanol"');
});

it('refuses to strip an output down to no video rendition', function () {
    // A second output carrying its own rendition: video-wide there are now two, but this
    // output still holds only one.
    $other = Output::create(['video_id' => $this->video->id, 'status' => 'completed']);
    ($this->make)('video', ['name' => 'Video', 'width' => 854, 'height' => 480])
        ->outputs()->attach($other->id);

    $this->deleteJson("/api/streams/{$this->videoStream->ulid}")->assertStatus(400);

    expect($this->videoStream->fresh())->not->toBeNull()
        ->and(Storage::disk('s3')->get($this->hlsPath))->toContain('#EXT-X-STREAM-INF');
});

it('deletes a rendition an output can spare', function () {
    ($this->make)('video', ['name' => 'Video', 'width' => 854, 'height' => 480])
        ->outputs()->attach($this->output->id);

    $this->deleteJson("/api/streams/{$this->videoStream->ulid}")->assertOk();

    expect($this->videoStream->fresh())->toBeNull();
});
