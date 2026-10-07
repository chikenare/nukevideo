<?php

/**
 * A phone shoots portrait but codes the frame landscape and hangs a 90° display matrix off it.
 * ffmpeg autorotates on decode with no flag asked for, so the frame that reaches `-vf scale` is
 * already portrait — sizing the ladder off the coded dimensions pins every rendition to a
 * landscape box and ships the whole title squashed.
 */

use App\Services\CreateVideoStreamsService;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;

/** A 1080x1920 picture as a phone actually stores it: coded 1920x1080, turned by side data. */
function portraitSource(array $extra = []): FFStream
{
    return new FFStream([
        'codec_type' => 'video',
        'width' => 1920,
        'height' => 1080,
        'side_data_list' => [['rotation' => -90]],
        ...$extra,
    ]);
}

function resolvedDimensions(FFStream $source, array $variant): array
{
    $service = new CreateVideoStreamsService;

    return (fn () => $this->resolveStreamDimensions($source, 'video', $variant))->call($service);
}

it('sizes a rendition against the picture as displayed, not as coded', function () {
    // The 1080p rung of a portrait source is 1080x1920: its box turns with the picture instead of
    // squashing it across a landscape frame or shrinking it to 608x1080 inside one.
    expect(resolvedDimensions(portraitSource(), ['width' => 1920, 'height' => 1080]))
        ->toBe([1080, 1920]);
});

it('leaves an unrotated source exactly as it was', function () {
    $landscape = new FFStream(['codec_type' => 'video', 'width' => 1920, 'height' => 1080]);

    expect(resolvedDimensions($landscape, ['width' => 1920, 'height' => 1080]))
        ->toBe([1920, 1080]);
});

it('reads the rotation a pre-5.0 ffmpeg wrote as a container tag', function () {
    $tagged = new FFStream([
        'codec_type' => 'video',
        'width' => 1920,
        'height' => 1080,
        'tags' => ['rotate' => '270'],
    ]);

    expect(resolvedDimensions($tagged, ['width' => 1920, 'height' => 1080]))->toBe([1080, 1920]);
});

it('treats a half turn as landscape, since the picture is not turned on its side', function () {
    $upsideDown = portraitSource(['side_data_list' => [['rotation' => 180]]]);

    expect(resolvedDimensions($upsideDown, ['width' => 1920, 'height' => 1080]))
        ->toBe([1920, 1080]);
});

it('builds a portrait ladder, every rung fitting inside its turned box', function () {
    $source = portraitSource();

    $ladder = [
        ['width' => 1920, 'height' => 1080],
        ['width' => 1280, 'height' => 720],
        ['width' => 3840, 'height' => 2160],
    ];

    $kept = (fn () => $this->filterVariants($source, $ladder))->call(new CreateVideoStreamsService);

    $resolved = array_map(fn (array $variant) => resolvedDimensions($source, $variant), $kept);

    // All taller than wide, none upscaled past the 1080x1920 source. The 4K rung lands on the
    // source like the 1080p one does, so it is dropped: the 1080p rung's knobs are the ones tuned
    // for those pixels.
    expect($resolved)->toBe([[1080, 1920], [720, 1280]]);

    foreach ($resolved as [$width, $height]) {
        expect($height)->toBeGreaterThan($width)
            ->and($width)->toBeLessThanOrEqual(1080)
            ->and($height)->toBeLessThanOrEqual(1920);
    }
});

it('advertises the turned aspect ratio rather than the coded one', function () {
    $service = new CreateVideoStreamsService;

    // ffprobe computes display_aspect_ratio off the coded frame, so it reports 16:9 here for a
    // picture that is plainly 9:16.
    $source = portraitSource(['display_aspect_ratio' => '16:9']);

    expect((fn () => $this->sourceAspectRatio($source))->call($service))->toBe('9:16');
});
