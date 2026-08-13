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

    /**
     * Build the fully-qualified, signed URL of ONE downloadable track.
     *
     * Signed per object, not per directory: the renditions of a video sit side by side in
     * `download/video/`, so a directory-scoped token handed out for 360p would authorize the 1080p
     * master just as well. Implementations must scope the signature to this exact key.
     *
     * Not IP-bound. Downloads are resumed, retried and handed to download managers, which roam
     * between addresses; playback tokens can afford the binding because a session is short.
     *
     * @param  string  $key  host-relative object key ({videoUlid}/download/{type}/{file})
     * @param  string|null  $trackingId  echoed into the URL so the CDN log can attribute the
     *                                   transfer; only providers whose logs carry the query string
     *                                   can honour it, the rest ignore it
     */
    public function downloadUrl(string $videoUlid, string $key, bool $local, ?string $trackingId = null): string;
}
