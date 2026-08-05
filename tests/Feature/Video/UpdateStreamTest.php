<?php

use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Manifest fixtures are trimmed copies of real shaka-packager v3.7.2 output — the attribute set,
 * spelling and nesting are what the packager actually emits, since that is exactly what the editor
 * has to match. `{audio}`/`{sub}`/`{video}` stand in for the stream ULIDs the segment paths are
 * keyed by ({@see \App\Models\Stream::segmentsPath}).
 */
function dashFixture(array $ulids): string
{
    return strtr(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" profiles="urn:mpeg:dash:profile:isoff-live:2011" minBufferTime="PT2S" type="static" mediaPresentationDuration="PT10S">
          <Period id="0">
            <AdaptationSet id="0" contentType="video" maxWidth="1920" maxHeight="800" segmentAlignment="true">
              <Representation id="0" bandwidth="7679580" codecs="av01.0.08M.10.0.110.01.01.01.0" mimeType="video/mp4" width="1920" height="800">
                <SegmentTemplate timescale="24000" initialization="{video}/init.mp4" media="{video}/$Number$.m4s" startNumber="1">
                  <SegmentTimeline><S t="0" d="240240"/></SegmentTimeline>
                </SegmentTemplate>
              </Representation>
            </AdaptationSet>
            <AdaptationSet id="1" contentType="audio" lang="es-419" segmentAlignment="true">
              <Label>Espanol</Label>
              <Representation id="1" bandwidth="146973" codecs="opus" mimeType="audio/mp4" audioSamplingRate="48000">
                <AudioChannelConfiguration schemeIdUri="urn:mpeg:dash:23003:3:audio_channel_configuration:2011" value="2"/>
                <SegmentTemplate timescale="48000" initialization="{audio}/init.mp4" media="{audio}/$Number$.m4s" startNumber="1">
                  <SegmentTimeline><S t="0" d="480000"/></SegmentTimeline>
                </SegmentTemplate>
              </Representation>
            </AdaptationSet>
            <AdaptationSet id="2" contentType="text" lang="en" segmentAlignment="true">
              <Role schemeIdUri="urn:mpeg:dash:role:2011" value="subtitle"/>
              <Label>English</Label>
              <Representation id="2" bandwidth="82" codecs="wvtt" mimeType="application/mp4">
                <SegmentTemplate timescale="1000" initialization="{sub}/init.mp4" media="{sub}/$Number$.m4s" startNumber="1">
                  <SegmentTimeline><S t="0" d="7490000"/></SegmentTimeline>
                </SegmentTemplate>
              </Representation>
            </AdaptationSet>
          </Period>
        </MPD>
        XML, $ulids);
}

function hlsFixture(array $ulids): string
{
    return strtr(<<<'M3U8'
        #EXTM3U
        ## Generated with https://github.com/shaka-project/shaka-packager version v3.7.2-64a6a6c-release

        #EXT-X-INDEPENDENT-SEGMENTS

        #EXT-X-MEDIA:TYPE=AUDIO,URI="{audio}/index.m3u8",GROUP-ID="audio",LANGUAGE="es-419",NAME="Espanol",DEFAULT=NO,AUTOSELECT=YES,CHANNELS="2"
        #EXT-X-MEDIA:TYPE=SUBTITLES,URI="{sub}/index.m3u8",GROUP-ID="subs",LANGUAGE="en",NAME="English",DEFAULT=NO,AUTOSELECT=YES

        #EXT-X-STREAM-INF:BANDWIDTH=3050279,AVERAGE-BANDWIDTH=3050279,CODECS="av01.0.08M.08",RESOLUTION=1920x800,FRAME-RATE=24.000,AUDIO="audio",SUBTITLES="subs",CLOSED-CAPTIONS=NONE
        {video}/index.m3u8
        M3U8, $ulids);
}

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

    $make = fn (string $type, array $attributes) => $this->video->streams()->create([
        'path' => "{$this->video->ulid}/{$type}/".Str::random(8).'.mp4',
        'type' => $type,
        'meta' => [],
        ...$attributes,
    ]);

    $this->videoStream = $make('video', ['name' => 'Video', 'width' => 1920, 'height' => 800]);
    $this->audio = $make('audio', ['name' => 'Espanol', 'language' => 'es-419', 'channels' => 2]);
    $this->subtitle = $make('subtitle', ['name' => 'English', 'language' => 'en']);

    $this->output->streams()->attach([$this->videoStream->id, $this->audio->id]);

    $ulids = [
        '{video}' => $this->videoStream->ulid,
        '{audio}' => $this->audio->ulid,
        '{sub}' => $this->subtitle->ulid,
    ];

    $this->dashPath = "{$this->video->ulid}/{$this->output->ulid}.mpd";
    $this->hlsPath = "{$this->video->ulid}/{$this->output->ulid}.m3u8";

    Storage::disk('s3')->put($this->dashPath, dashFixture($ulids));
    Storage::disk('s3')->put($this->hlsPath, hlsFixture($ulids));

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('renames an audio track in the database and in both manifests', function () {
    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => 'Latin American Spanish',
        'language' => 'es-MX',
        'forced' => false,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Latin American Spanish')
        ->assertJsonPath('data.language', 'es-MX');

    $dash = Storage::disk('s3')->get($this->dashPath);
    expect($dash)->toContain('<Label>Latin American Spanish</Label>')
        ->and($dash)->toContain('contentType="audio" lang="es-MX"')
        ->and($dash)->not->toContain('<Label>Espanol</Label>');

    $hls = Storage::disk('s3')->get($this->hlsPath);
    expect($hls)->toContain('NAME="Latin American Spanish"')
        ->and($hls)->toContain('LANGUAGE="es-MX"')
        // The packager sets these for every track; they are not ours to touch.
        ->and($hls)->toContain('DEFAULT=NO,AUTOSELECT=YES,CHANNELS="2"');
});

it('leaves the subtitle track untouched when only the audio is edited', function () {
    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => 'Renamed',
        'language' => 'es-419',
        'forced' => false,
    ])->assertOk();

    $hls = Storage::disk('s3')->get($this->hlsPath);
    expect($hls)->toContain('NAME="English",DEFAULT=NO,AUTOSELECT=YES');
});

it('marks a subtitle as forced in both manifests', function () {
    $this->putJson("/api/streams/{$this->subtitle->ulid}", [
        'name' => 'English (Forced)',
        'language' => 'en',
        'forced' => true,
    ])->assertOk()
        ->assertJsonPath('data.forced', true);

    expect(Storage::disk('s3')->get($this->dashPath))
        ->toContain('<Role schemeIdUri="urn:mpeg:dash:role:2011" value="forced-subtitle"/>')
        ->not->toContain('value="subtitle"');

    expect(Storage::disk('s3')->get($this->hlsPath))
        ->toContain('NAME="English (Forced)",DEFAULT=NO,AUTOSELECT=YES,FORCED=YES');
});

it('drops the forced attributes again when a subtitle is unforced', function () {
    $this->subtitle->update(['forced' => true]);
    Storage::disk('s3')->put($this->hlsPath, str_replace(
        'NAME="English",DEFAULT=NO,AUTOSELECT=YES',
        'NAME="English",DEFAULT=NO,AUTOSELECT=YES,FORCED=YES',
        Storage::disk('s3')->get($this->hlsPath),
    ));

    $this->putJson("/api/streams/{$this->subtitle->ulid}", [
        'name' => 'English',
        'language' => 'en',
        'forced' => false,
    ])->assertOk();

    expect(Storage::disk('s3')->get($this->hlsPath))->not->toContain('FORCED=');
    expect(Storage::disk('s3')->get($this->dashPath))->toContain('value="subtitle"');
});

it('keeps a language the user did not touch as the packager normalized it', function () {
    // The ingest stores the container's raw code; the packager emitted the short form.
    $this->subtitle->update(['language' => 'eng']);

    $this->putJson("/api/streams/{$this->subtitle->ulid}", [
        'name' => 'Renamed only',
        'language' => 'eng',
        'forced' => false,
    ])->assertOk();

    $dash = Storage::disk('s3')->get($this->dashPath);
    expect($dash)->toContain('<Label>Renamed only</Label>')
        ->and($dash)->toContain('contentType="text" lang="en"')
        ->and($dash)->not->toContain('lang="eng"');
});

it('adds a Label and a Role to a set that has neither', function () {
    Storage::disk('s3')->put($this->dashPath, str_replace(
        ['<Label>Espanol</Label>', '<Role schemeIdUri="urn:mpeg:dash:role:2011" value="subtitle"/>', '<Label>English</Label>'],
        '',
        Storage::disk('s3')->get($this->dashPath),
    ));

    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => 'Espanol LATAM',
        'language' => 'es-419',
        'forced' => false,
    ])->assertOk();

    $this->putJson("/api/streams/{$this->subtitle->ulid}", [
        'name' => 'English',
        'language' => 'en',
        'forced' => true,
    ])->assertOk();

    $dash = Storage::disk('s3')->get($this->dashPath);

    // Created elements sit before the first Representation, where the packager puts them, and inherit
    // the MPD's default namespace rather than being emitted with an empty xmlns.
    expect($dash)->toContain('<Label>Espanol LATAM</Label>')
        ->and($dash)->toContain('value="forced-subtitle"')
        ->and($dash)->not->toContain('xmlns=""')
        ->and(strpos($dash, '<Label>Espanol LATAM</Label>'))->toBeLessThan(strpos($dash, '<Representation id="1"'));

    expect(fn () => new SimpleXMLElement($dash))->not->toThrow(Exception::class);
});

it('rejects a name that would break out of the manifest syntax', function (string $name) {
    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => $name,
        'language' => 'es-419',
        'forced' => false,
    ])->assertStatus(422)->assertJsonValidationErrors('name');

    expect(Storage::disk('s3')->get($this->hlsPath))->toContain('NAME="Espanol"');
})->with([
    'comma' => ['Spanish, Latin America'],
    'quote' => ['Spanish "LATAM"'],
    'newline' => ["Spanish\n#EXT-X-KEY:METHOD=NONE"],
    'carriage return' => ["Spanish\rEspanol"],
]);

it('never writes a bare newline into the master playlist', function () {
    // Laravel's TrimStrings makes a trailing newline valid; what matters is that the master keeps
    // one #EXT-X-MEDIA line per track, since it is edited as text.
    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => "Spanish\n",
        'language' => 'es-419',
        'forced' => false,
    ])->assertOk()->assertJsonPath('data.name', 'Spanish');

    $hls = Storage::disk('s3')->get($this->hlsPath);
    expect($hls)->toContain('NAME="Spanish"')
        ->and(substr_count($hls, '#EXT-X-MEDIA'))->toBe(2);
});

it('rejects an unknown language', function () {
    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => 'Espanol',
        'language' => 'zz',
        'forced' => false,
    ])->assertStatus(422)->assertJsonValidationErrors('language');
});

it('accepts a null language', function () {
    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => 'Espanol',
        'language' => null,
        'forced' => false,
    ])->assertOk()->assertJsonPath('data.language', null);

    $dash = Storage::disk('s3')->get($this->dashPath);
    expect($dash)->toContain('contentType="audio" segmentAlignment="true"');
    expect(Storage::disk('s3')->get($this->hlsPath))->not->toContain('LANGUAGE="es-419"');
});

it('lets a forced subtitle share its name with the full track', function () {
    // Apple's own HLS examples label forced subs identically to their full counterpart —
    // the FORCED attribute is the distinction, not the name.
    $this->video->streams()->create([
        'path' => "{$this->video->ulid}/subtitle/full.mp4",
        'type' => 'subtitle',
        'meta' => [],
        'name' => 'Español',
        'forced' => false,
    ]);

    $this->putJson("/api/streams/{$this->subtitle->ulid}", [
        'name' => 'Español',
        'language' => 'es',
        'forced' => true,
    ])->assertOk()->assertJsonPath('data.name', 'Español');
});

it('still rejects a duplicate name within the same forced flag', function () {
    $this->video->streams()->create([
        'path' => "{$this->video->ulid}/subtitle/full.mp4",
        'type' => 'subtitle',
        'meta' => [],
        'name' => 'Español',
        'forced' => false,
    ]);

    $this->putJson("/api/streams/{$this->subtitle->ulid}", [
        'name' => 'español',
        'language' => 'es',
        'forced' => false,
    ])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('checks the collision against the forced flag being set, not the stored one', function () {
    // Flipping forced and renaming in one request must collide with the DESTINATION group.
    $this->video->streams()->create([
        'path' => "{$this->video->ulid}/subtitle/forced.mp4",
        'type' => 'subtitle',
        'meta' => [],
        'name' => 'Español',
        'forced' => true,
    ]);

    $this->putJson("/api/streams/{$this->subtitle->ulid}", [
        'name' => 'Español',
        'language' => 'es',
        'forced' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('rejects a name already used by another track of the same type', function () {
    $this->video->streams()->create([
        'path' => "{$this->video->ulid}/audio/other.mp4",
        'type' => 'audio',
        'meta' => [],
        'name' => 'English',
    ]);

    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => 'english',
        'language' => 'es-419',
        'forced' => false,
    ])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('allows an audio and a subtitle track to share a name', function () {
    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => 'English',
        'language' => 'en',
        'forced' => false,
    ])->assertOk();
});

it('marks a subtitle as SDH in the database and in both manifests', function () {
    $this->putJson("/api/streams/{$this->subtitle->ulid}", [
        'name' => 'English (SDH)',
        'language' => 'en',
        'forced' => false,
        'hearingImpaired' => true,
    ])->assertOk()->assertJsonPath('data.hearingImpaired', true);

    expect(Storage::disk('s3')->get($this->dashPath))
        ->toContain('<Role schemeIdUri="urn:mpeg:dash:role:2011" value="caption"/>')
        ->not->toContain('value="subtitle"');

    expect(Storage::disk('s3')->get($this->hlsPath))
        ->toContain('CHARACTERISTICS="public.accessibility.transcribes-spoken-dialog,public.accessibility.describes-music-and-sound"');
});

it('drops the SDH markers again when the flag is turned off', function () {
    $this->subtitle->update(['meta' => ['hearing_impaired' => true]]);
    Storage::disk('s3')->put($this->dashPath, str_replace(
        'value="subtitle"',
        'value="caption"',
        Storage::disk('s3')->get($this->dashPath),
    ));
    Storage::disk('s3')->put($this->hlsPath, str_replace(
        'NAME="English",DEFAULT=NO,AUTOSELECT=YES',
        'NAME="English",DEFAULT=NO,AUTOSELECT=YES,CHARACTERISTICS="public.accessibility.transcribes-spoken-dialog,public.accessibility.describes-music-and-sound"',
        Storage::disk('s3')->get($this->hlsPath),
    ));

    $this->putJson("/api/streams/{$this->subtitle->ulid}", [
        'name' => 'English',
        'language' => 'en',
        'forced' => false,
        'hearingImpaired' => false,
    ])->assertOk()->assertJsonPath('data.hearingImpaired', false);

    expect(Storage::disk('s3')->get($this->dashPath))->toContain('value="subtitle"');
    expect(Storage::disk('s3')->get($this->hlsPath))->not->toContain('CHARACTERISTICS');
});

it('keeps the forced role when an SDH flag lands on a forced subtitle', function () {
    // forced-subtitle outranks caption ({@see ManifestEditor::textRole}); the flag is stored
    // and resurfaces the moment the track is unforced.
    $this->subtitle->update(['forced' => true]);

    $this->putJson("/api/streams/{$this->subtitle->ulid}", [
        'name' => 'English',
        'language' => 'en',
        'forced' => true,
        'hearingImpaired' => true,
    ])->assertOk();

    expect(Storage::disk('s3')->get($this->dashPath))->not->toContain('value="caption"');
    expect(Storage::disk('s3')->get($this->hlsPath))->not->toContain('CHARACTERISTICS');
});

it('leaves the flag alone when the request does not carry it', function () {
    $this->subtitle->update(['meta' => ['hearing_impaired' => true]]);

    $this->putJson("/api/streams/{$this->subtitle->ulid}", [
        'name' => 'Renamed',
        'language' => 'en',
        'forced' => false,
    ])->assertOk()->assertJsonPath('data.hearingImpaired', true);
});

it('refuses to flip the flag on an audio track', function () {
    // Audio SDH was baked into the packaged DASH Accessibility descriptor; without that surgery
    // the API would contradict the manifests.
    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => 'Espanol',
        'language' => 'es-419',
        'forced' => false,
        'hearingImpaired' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('hearingImpaired');
});

it('refuses to edit a video rendition', function () {
    $this->putJson("/api/streams/{$this->videoStream->ulid}", [
        'name' => 'Something',
        'language' => null,
        'forced' => false,
    ])->assertStatus(400);
});

it('refuses to edit while the video is still processing', function () {
    $this->video->update(['status' => 'running']);

    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => 'Renamed',
        'language' => 'es-419',
        'forced' => false,
    ])->assertStatus(400);
});

it('does not touch a stream belonging to another project', function () {
    $other = Project::factory()->for($this->user)->create();
    $this->withHeader('X-Project-Ulid', $other->ulid);

    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => 'Hijacked',
        'language' => 'es-419',
        'forced' => false,
    ])->assertStatus(404);
});
