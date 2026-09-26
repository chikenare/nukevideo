<?php

use App\Jobs\PackageVideoJob;
use App\Models\Video;
use Illuminate\Support\Facades\Log;

/**
 * The last look at a rendition's rate, after it's packaged. It can't undo an overshoot, but every
 * earlier ceiling is estimated from samples, and a miss used to go unnoticed for days (video 9059
 * at 1.36x its source).
 */
function warnIfOutweighsSource(int $packageBytes, array $meta = LIGHT_SOURCE, float $duration = 100.0): void
{
    $video = (new Video)->forceFill(['id' => 1, 'duration' => $duration]);
    $stream = matrixStream(qualityTemplate('libsvtav1'), meta: $meta, width: 1920, height: 1080);

    (fn () => $this->warnIfOutweighsSource($video, $stream, $packageBytes))->call(new PackageVideoJob(1));
}

beforeEach(fn () => Log::spy());

it('warns when a packaged rendition runs above its source rate', function () {
    // 2 Mbps over 100s against a 1.26 Mbps source, past the 1.2 tolerance.
    warnIfOutweighsSource(25_000_000);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Rendition outweighs its source'
        && $context['bitrate'] === 2_000_000
        && $context['ceiling'] === (int) round(1_263_599 * 1.2));
});

it('stays quiet within the tolerance', function () {
    // 1.4 Mbps: above the source's mean, under its 1.2x tolerance.
    warnIfOutweighsSource(17_500_000);

    Log::shouldNotHaveReceived('warning');
});

it('stays quiet when there is no source rate to compare against', function () {
    warnIfOutweighsSource(25_000_000, ['source_bit_rate' => 0] + LIGHT_SOURCE);

    Log::shouldNotHaveReceived('warning');
});
