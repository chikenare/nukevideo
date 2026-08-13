<?php

declare(strict_types=1);

namespace App\Services\Cdn;

use App\Models\Video;

interface CdnProvider
{
    /**
     * Build the fully-qualified, signed manifest URL.
     *
     * @param  string  $path  host-relative manifest path ({videoUlid}/{file}), provider-agnostic
     */
    public function manifestUrl(Video $video, string $path, string $ip, bool $local): string;

    /**
     * Build the fully-qualified, UNSIGNED URL of a thumbnail or storyboard.
     *
     * Deliberately unsigned: these are shown in listings, so a URL that changes on every render
     * would defeat the browser cache. The edge serves the `assets/` zone without token validation
     * ({@see vod/nginx/nginx.conf.template}), and nothing else in the bucket is reachable that way.
     *
     * Takes the ULID rather than the model because that is all the routing needs, and the callers
     * that build listing payloads do not always have the video loaded.
     *
     * @param  string  $key  host-relative asset key ({videoUlid}/assets/{file})
     */
    public function assetUrl(string $videoUlid, string $key, bool $local): string;
}
