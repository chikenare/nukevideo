<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * One playable thing: a signed manifest, and enough about it to decide whether this device can
 * play it before loading it.
 *
 * Flat on purpose. An output that serves both protocols produces two of these rather than one row
 * holding a map of formats, so every entry is self-sufficient — pick one, hand it to the player,
 * done — instead of making the caller walk two levels to reach a URL. The price is that the two
 * rows of one output repeat its codecs, which nothing playing a video cares about.
 */
class VodSourceData extends Data
{
    public function __construct(
        public string $url,
        /** `hls` or `dash`. Explicit rather than sniffed from the URL's extension. */
        public string $format,
        /**
         * The output this manifest belongs to, so the answer can be correlated with the
         * `outputs[]` of `GET /api/videos/{ulid}` — and so a caller that already chose an output
         * (the admin panel plays the one you picked) can find its rows.
         */
        public string $outputUlid,
        /**
         * The decodable video format (`h264`, `hevc`, `av1`), which is what lets a client rule
         * this source out before loading it — the manifest declares the exact codec string, but
         * only once fetched. Null when the output records no codec.
         */
        public ?string $videoCodec,
        /**
         * The decodable audio format (`aac`, `opus`). One, like the video codec: a template names
         * a single `audio_codec` per output and applies it to every language it carries. Null when
         * the output has no audio at all.
         */
        public ?string $audioCodec,
    ) {}
}
