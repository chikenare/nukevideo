<?php

/**
 * A track title comes out of the uploaded container, so it is attacker-controlled text — and it
 * ends up interpolated into a shaka stream descriptor and from there into the HLS master playlist,
 * which is assembled and edited as plain text. A comma ends a descriptor field, a quote closes the
 * `NAME="…"` attribute, and a newline ends the `#EXT-X-MEDIA` line — so everything after it becomes
 * a playlist tag of its own, for every viewer of that video.
 *
 * The edit endpoint has always refused all three ({@see UpdateStreamData}); these
 * cover the ingest side, which is reachable with nothing but an upload.
 */

use App\Data\Stream\UpdateStreamData;
use App\Services\CreateVideoStreamsService;
use App\Services\PackagerCommandBuilder;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;

function ingestedName(string $title): string
{
    $stream = new FFStream(['index' => 0, 'codec_type' => 'audio', 'tags' => ['title' => $title]]);

    return (fn () => $this->baseName($stream, 'audio'))->call(new CreateVideoStreamsService);
}

it('never lets a track title carry a character that breaks the master playlist', function (string $title) {
    expect(ingestedName($title))
        ->not->toContain('"')
        ->not->toContain(',')
        ->not->toContain("\n")
        ->not->toContain("\r");
})->with([
    'a quote' => 'Comment "Director"',
    'an injected tag' => "Bad\n#EXT-X-KEY:METHOD=NONE",
    'a carriage return' => "Bad\r#EXT-X-KEY:METHOD=NONE",
    'a descriptor separator' => 'Spanish,forced',
    'all of them at once' => "a\",b\r\nc",
]);

it('keeps an honest title intact', function () {
    expect(ingestedName('Director’s commentary (Español)'))->toBe('Director’s commentary (Español)');
});

it('falls back to the media type when stripping empties the title', function () {
    expect(ingestedName('",,"'))->toBe('Audio');
});

it('strips the same characters again on the way into the packager', function () {
    // Belt and braces: the single point every packaging path funnels through, so a future writer
    // of `streams.name` cannot reopen the hole quietly.
    $sanitize = fn (string $name) => (fn () => self::descriptorLabel($name))
        ->call(new PackagerCommandBuilder('packager', 10));

    expect($sanitize("Bad\n#EXT-X-KEY:METHOD=NONE"))->toBe('Bad #EXT-X-KEY:METHOD=NONE')
        ->and($sanitize('Comment "Director"'))->toBe('Comment  Director');
});
