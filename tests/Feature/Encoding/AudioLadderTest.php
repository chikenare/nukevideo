<?php

use App\Models\Project;
use App\Models\Stream;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use App\Services\CreateVideoStreamsService;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** The template audio config from prod: a stereo rung and a 5.1 rung sharing the codec. */
const AUDIO_CONFIG = [
    'audio_codec' => 'libopus',
    'opus_application' => 'audio',
    'channels' => [
        ['channels' => '2', 'audio_bitrate' => '128k'],
        ['channels' => '6', 'audio_bitrate' => '256k'],
    ],
];

function audioStreamsFor(array $sourceTracks, array $audioConfig = AUDIO_CONFIG): array
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
        'status' => 'running',
    ]);

    $collection = new StreamCollection(array_map(
        fn (array $track) => new FFStream(['codec_type' => 'audio', 'codec_name' => 'eac3', ...$track]),
        $sourceTracks,
    ));

    $service = new CreateVideoStreamsService;
    $ids = (fn () => $this->getOrCreateAudioStreams($video, $collection, $audioConfig))->call($service);

    return $video->streams()->whereIn('id', $ids)->orderBy('id')->get()
        ->map(fn ($s) => [
            'name' => $s->name,
            'channels' => $s->channels,
            'bitrate' => $s->input_params['audio_bitrate'] ?? null,
        ])->all();
}

describe('audio ladder', function () {
    it('gives a 5.1 source both the stereo downmix and the 5.1 rung', function () {
        // The prod case: a 4K upload with 5.1-only sources and a [stereo@128k, 5.1@256k] template
        // shipped without any stereo — the old code matched rungs to tracks by exact channel count.
        $streams = audioStreamsFor([
            ['index' => 1, 'channels' => 6, 'tags' => ['language' => 'spa', 'title' => 'Español']],
        ]);

        expect($streams)->toHaveCount(2)
            ->and($streams[0])->toBe(['name' => 'Español (Stereo)', 'channels' => 2, 'bitrate' => '128k'])
            ->and($streams[1])->toBe(['name' => 'Español (5.1)', 'channels' => 6, 'bitrate' => '256k']);
    });

    it('never upmixes: a stereo source collapses the ladder to its own stereo', function () {
        $streams = audioStreamsFor([
            ['index' => 1, 'channels' => 2, 'tags' => ['language' => 'eng', 'title' => 'English']],
        ]);

        // Both rungs resolve to 2ch; the stereo entry's bitrate wins, and no suffix is needed.
        expect($streams)->toHaveCount(1)
            ->and($streams[0])->toBe(['name' => 'English', 'channels' => 2, 'bitrate' => '128k']);
    });

    it('ladders every source track independently', function () {
        $streams = audioStreamsFor([
            ['index' => 1, 'channels' => 6, 'tags' => ['language' => 'spa', 'title' => 'Español']],
            ['index' => 2, 'channels' => 2, 'tags' => ['language' => 'eng', 'title' => 'English']],
        ]);

        expect(collect($streams)->pluck('name')->all())
            ->toBe(['Español (Stereo)', 'Español (5.1)', 'English']);
    });

    it('clamps a mono source below the whole ladder to one mono rung', function () {
        $streams = audioStreamsFor([
            ['index' => 1, 'channels' => 1, 'tags' => ['title' => 'Commentary']],
        ]);

        expect($streams)->toHaveCount(1)
            ->and($streams[0]['channels'])->toBe(1)
            ->and($streams[0]['bitrate'])->toBe('128k');
    });

    it('creates nothing when the template carries no audio rungs', function () {
        expect(audioStreamsFor(
            [['index' => 1, 'channels' => 6, 'tags' => []]],
            ['audio_codec' => 'libopus'],
        ))->toHaveCount(0);
    });
});

describe('audio never outweighs its source track', function () {
    it('lowers a rung to a source track that spent less', function (array $track, string $expected) {
        $streams = audioStreamsFor([['index' => 1, 'tags' => ['language' => 'jpn'], ...$track]]);

        expect(array_column($streams, 'bitrate'))->each->toBe($expected);
    })->with([
        // The Dragon Ball case: a 96k mono AC3 track under the template's 128k stereo rung.
        'mono AC3 stating bit_rate' => [['channels' => 1, 'codec_name' => 'ac3', 'bit_rate' => '96000'], '96k'],
        'stereo AAC at 96k' => [['channels' => 2, 'codec_name' => 'aac', 'bit_rate' => '96000'], '96k'],
        // mkvmerge'd Matroska states it only in a tag; whole kbps, rounded down, never above it.
        'stereo AAC, BPS tag only' => [['channels' => 2, 'codec_name' => 'aac', 'tags' => ['language' => 'jpn', 'BPS' => '117744']], '117k'],
    ]);

    it('keeps the template rate when the source spent as much or more', function () {
        $streams = audioStreamsFor([
            ['index' => 1, 'channels' => 6, 'bit_rate' => '640000', 'tags' => ['language' => 'eng']],
        ]);

        expect(array_column($streams, 'bitrate'))->toBe(['128k', '256k']);
    });

    it('keeps the template rate when the source states none', function () {
        expect(array_column(audioStreamsFor([['index' => 1, 'channels' => 2, 'tags' => ['language' => 'eng']]]), 'bitrate'))
            ->toBe(['128k']);
    });

    it('caps each rung on its own, so a 5.1 rung over a lean 5.1 track comes down too', function () {
        // A 192k 5.1 track: the 5.1 rung's 256k comes down to 192k, the 128k stereo downmix stands.
        $streams = audioStreamsFor([
            ['index' => 1, 'channels' => 6, 'bit_rate' => '192000', 'tags' => ['language' => 'eng']],
        ]);

        expect(array_column($streams, 'bitrate'))->toBe(['128k', '192k']);
    });

    it('leaves a quality-VBR encode alone, which ignores -b:a anyway', function () {
        $streams = audioStreamsFor(
            [['index' => 1, 'channels' => 2, 'bit_rate' => '96000', 'tags' => ['language' => 'eng']]],
            ['audio_codec' => 'aac', 'audio_vbr' => '3', 'channels' => [['channels' => '2', 'audio_bitrate' => '128k']]],
        );

        expect(array_column($streams, 'bitrate'))->toBe(['128k']);
    });

    it('records the source track rate it was capped against', function () {
        audioStreamsFor([['index' => 1, 'channels' => 1, 'codec_name' => 'ac3', 'bit_rate' => '96000', 'tags' => ['language' => 'jpn']]]);

        expect(Stream::where('type', 'audio')->first()->meta['source_bit_rate'])->toBe(96000);
    });
});
