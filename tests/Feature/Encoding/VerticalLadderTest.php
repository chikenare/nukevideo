<?php

/**
 * A rung's box is a size class, not an orientation. Fitted as written, a 1920x1080 rung gave a
 * vertical 1080x1920 video a 608x1080 "1080p" — and a 2160x3840 master could never keep a 4K rung,
 * since templates stop at 2160 tall. The box now turns to face the source; playback caps compare on
 * the short edge to match, so `resolution=1080` still means 1080p for a vertical ladder.
 */

use App\Models\Output;
use App\Models\Stream;
use App\Services\CreateVideoStreamsService;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;

function verticalDimensions(int $width, int $height, array $variant): array
{
    $source = new FFStream(['codec_type' => 'video', 'width' => $width, 'height' => $height]);

    return (fn () => $this->resolveStreamDimensions($source, 'video', $variant))->call(new CreateVideoStreamsService);
}

function ladderOutput(array $sizes): Output
{
    $output = new Output;
    $output->setRelation('streams', collect($sizes)->map(fn (array $size) => (new Stream)->forceFill([
        'type' => 'video',
        'width' => $size[0],
        'height' => $size[1],
    ])));

    return $output;
}

it('keeps a vertical 4K master at 4K', function () {
    expect(verticalDimensions(2160, 3840, ['width' => 3840, 'height' => 2160]))->toBe([2160, 3840])
        ->and(verticalDimensions(2160, 3840, ['width' => 1920, 'height' => 1080]))->toBe([1080, 1920]);
});

it('turns a portrait box for a landscape source just the same', function () {
    expect(verticalDimensions(1920, 1080, ['width' => 1080, 'height' => 1920]))->toBe([1920, 1080]);
});

it('leaves square boxes, square sources and lone edges as they were', function () {
    expect(verticalDimensions(1080, 1080, ['width' => 1920, 'height' => 1080]))->toBe([1080, 1080])
        ->and(verticalDimensions(1080, 1920, ['width' => 720, 'height' => 720]))->toBe([404, 720])
        ->and(verticalDimensions(1080, 1920, ['height' => 720]))->toBe([404, 720])
        ->and(verticalDimensions(1080, 1920, ['width' => 720]))->toBe([720, 1280]);
});

it('caps a vertical ladder on the short edge, naming the manifest after the height', function () {
    $output = ladderOutput([[1080, 1920], [720, 1280], [480, 854]]);

    expect($output->resolveCap(1080))->toBeNull()
        ->and($output->resolveCap(720))->toBe(1280)
        ->and($output->resolveCap(600))->toBe(854)
        ->and($output->resolveCap(240))->toBe(854);
});

it('caps a landscape ladder exactly as before', function () {
    $output = ladderOutput([[1920, 1080], [1280, 720], [854, 480]]);

    expect($output->resolveCap(720))->toBe(720)
        ->and($output->resolveCap(1080))->toBeNull()
        ->and($output->resolveCap(900))->toBe(720);
});

it('still serves the capped manifests of a portrait video packaged in landscape boxes', function () {
    // The old ladder for a 1080x1920 source: 404x720, 608x1080, and the source-sized top rung.
    $output = ladderOutput([[404, 720], [608, 1080], [1080, 1920]]);

    expect($output->resolveCap(720))->toBe(1080)
        ->and($output->resolveCap(480))->toBe(720);
});
