<?php

/**
 * When the last audio track of an output is deleted, the HLS master has to stop advertising audio
 * in TWO places: the `AUDIO="…"` group reference on each variant, and the audio entry in `CODECS`.
 * RFC 8216 §4.3.4.2 requires CODECS to list what the variant actually contains, and AVFoundation
 * (Safari, iOS, tvOS) stalls or refuses a variant that declares a codec with nothing behind it.
 *
 * The packager writes `CODECS="avc1.64001f,mp4a.40.2"` whenever the output had audio, with the
 * video codec always first — so the fix keeps the first entry and drops the rest.
 */

use App\Services\ManifestEditor;

function dropAudio(string $line): string
{
    return (fn () => $this->hlsDropAudioFromVariant($line))->call(app(ManifestEditor::class));
}

it('drops the audio codec along with the audio group', function () {
    $line = '#EXT-X-STREAM-INF:BANDWIDTH=3050279,CODECS="avc1.64001f,mp4a.40.2",RESOLUTION=1280x720,AUDIO="audio"';

    expect(dropAudio($line))
        ->toBe('#EXT-X-STREAM-INF:BANDWIDTH=3050279,CODECS="avc1.64001f",RESOLUTION=1280x720');
});

it('leaves a video-only variant untouched', function () {
    $line = '#EXT-X-STREAM-INF:BANDWIDTH=3050279,CODECS="av01.0.08M.08",RESOLUTION=1920x800';

    expect(dropAudio($line))->toBe($line);
});

it('keeps only the video codec when the packager listed more than one audio format', function () {
    $line = '#EXT-X-STREAM-INF:BANDWIDTH=100,CODECS="avc1.64001f,mp4a.40.2,ec-3",RESOLUTION=1280x720';

    expect(dropAudio($line))->toBe('#EXT-X-STREAM-INF:BANDWIDTH=100,CODECS="avc1.64001f",RESOLUTION=1280x720');
});

it('handles CODECS as the last attribute on the line', function () {
    $line = '#EXT-X-STREAM-INF:BANDWIDTH=100,RESOLUTION=1280x720,CODECS="avc1.64001f,mp4a.40.2"';

    expect(dropAudio($line))->toBe('#EXT-X-STREAM-INF:BANDWIDTH=100,RESOLUTION=1280x720,CODECS="avc1.64001f"');
});

it('leaves a line carrying no CODECS alone', function () {
    $line = '#EXT-X-STREAM-INF:BANDWIDTH=100,RESOLUTION=1280x720';

    expect(dropAudio($line))->toBe($line);
});
