<?php

use App\Models\Project;
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
