<?php

/**
 * The chain behind `meta.source_bit_rate`, exercised on real muxed files rather than hand-written
 * probe data — the bug it covers was entirely about what a given container reports. It is the only
 * input to a rendition's ceiling ({@see App\Services\Concerns\ResolvesRateControl}), and a zero
 * there is not a conservative default: it removes the ceiling altogether.
 */

use App\Models\Video;
use App\Services\ChunkTranscodeService;
use App\Services\CreateVideoStreamsService;
use Illuminate\Support\Facades\Process;

const FIXTURE_DIR = '/tmp/nukevideo-source-bitrate';

/** A real 6s file with one video and one audio track, muxed by `$codecArgs` into `$name`. */
function sourceFixture(string $name, string $codecArgs): string
{
    $path = FIXTURE_DIR."/$name";

    if (file_exists($path)) {
        return $path;
    }

    @mkdir(FIXTURE_DIR, 0o777, true);

    Process::timeout(60)->run(sprintf(
        'ffmpeg -hide_banner -v error -y -f lavfi -i testsrc=size=640x360:rate=24:duration=6 '
        .'-f lavfi -i sine=frequency=440:duration=6 -map 0:v -map 1:a %s %s',
        $codecArgs,
        escapeshellarg($path),
    ))->throw();

    return $path;
}

/** First value ffprobe reports for `-show_entries $entries`; null when the field is absent. */
function ffprobeValue(string $path, string $entries, string $select = ''): ?string
{
    $output = Process::timeout(60)->run(sprintf(
        'ffprobe -v error %s -show_entries %s -of default=nw=1:nk=1 %s',
        $select === '' ? '' : "-select_streams $select",
        $entries,
        escapeshellarg($path),
    ))->throw()->output();

    $value = trim(strtok($output, "\n") ?: '');

    return $value === '' || $value === 'N/A' ? null : $value;
}

/** `source_bit_rate` as the real probe would store it, container wiring included. */
function probedBitRate(string $path): int
{
    $video = (new Video)->forceFill(['ulid' => 'test']);

    return (function () use ($video, $path) {
        $media = $this->probeMedia($video, $path);

        return $this->sourceBitRate($media['streamCollection']->videos()->first());
    })->call(new CreateVideoStreamsService);
}

afterAll(function () {
    array_map('unlink', glob(FIXTURE_DIR.'/*') ?: []);
    @rmdir(FIXTURE_DIR);
});

describe('source_bit_rate, per container', function () {
    it('takes the video track its own stated rate when there is one', function () {
        $path = sourceFixture('stated.mp4', '-c:v libx264 -c:a aac -b:a 128k');

        expect(probedBitRate($path))
            ->toBe((int) ffprobeValue($path, 'stream=bit_rate', 'v:0'))
            // Well under the container total, so the 128k of audio demonstrably stayed out of it.
            ->toBeLessThan((int) ffprobeValue($path, 'format=bit_rate') - 100_000);
    });

    it('prefers an mkvmerge BPS tag, which measures the video track alone', function () {
        $source = sourceFixture('stated.mp4', '-c:v libx264 -c:a aac -b:a 128k');
        $path = FIXTURE_DIR.'/tagged.mkv';

        if (! file_exists($path)) {
            Process::timeout(60)->run(sprintf('mkvmerge -q -o %s %s', escapeshellarg($path), escapeshellarg($source)))->throw();
        }

        expect(ffprobeValue($path, 'stream=bit_rate', 'v:0'))->toBeNull()
            ->and(probedBitRate($path))->toBe((int) ffprobeValue($path, 'stream_tags=BPS', 'v:0'));
    });

    it('falls back to the container for a Matroska that states nothing anywhere', function () {
        // The prod source was exactly this: a WEB-DL remux with no per-track rate and no BPS tags,
        // which used to read as 0 — and 0 is not "be careful", it is "no ceiling".
        $path = sourceFixture('plain.mkv', '-c:v libx264 -c:a aac -b:a 128k');

        expect(ffprobeValue($path, 'stream=bit_rate', 'v:0'))->toBeNull()
            ->and(ffprobeValue($path, 'stream_tags=BPS', 'v:0'))->toBeNull()
            ->and(probedBitRate($path))->toBe((int) ffprobeValue($path, 'format=bit_rate'));
    });

    it('discounts the tracks that do state a rate from the container total', function () {
        // MPEG-TS states the audio rate but not the video's; charging the video for its audio
        // would inflate the very number the ceiling is scaled from.
        $path = sourceFixture('partial.ts', '-c:v libx264 -c:a aac -b:a 96k');
        $audio = (int) ffprobeValue($path, 'stream=bit_rate', 'a:0');

        expect(ffprobeValue($path, 'stream=bit_rate', 'v:0'))->toBeNull()
            ->and($audio)->toBeGreaterThan(0)
            ->and(probedBitRate($path))->toBe((int) ffprobeValue($path, 'format=bit_rate') - $audio);
    });
});

describe('what the rendition does with it', function () {
    it('gives a Matroska rendition a real ceiling where it used to have none', function () {
        $rate = probedBitRate(sourceFixture('plain.mkv', '-c:v libx264 -c:a aac -b:a 128k'));

        $service = new ChunkTranscodeService(matrixStream(
            qualityTemplate('av1_qsv'),
            meta: [...LIGHT_SOURCE, 'source_bit_rate' => $rate],
            width: 1920,
            height: 1080,
        ));

        expect($rate)->toBeGreaterThan(0)
            ->and($service->sourceBitrateCap())->not->toBeNull()
            ->and($service->encodesUncapped())->toBeFalse();
    });
});
