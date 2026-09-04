<?php

namespace App\Services;

use App\Data\VideoVodLinksData;
use App\Data\VodSourceData;
use App\Enums\VideoStatus;
use App\Exceptions\NoCdnNodeAvailableException;
use App\Models\Output;
use App\Models\Video;
use App\Services\Cdn\AssetUrlResolver;
use App\Services\Cdn\BunnyProvider;
use App\Services\Cdn\CdnProvider;
use App\Services\Cdn\ProxyRing;
use App\Services\Cdn\SelfHostedProvider;
use App\Services\Cdn\SignedLink;
use App\Services\Cdn\TrackingRegistry;

/**
 * Mints a whole video's playback links in one pass.
 *
 * The same argument as {@see DownloadLinkService::forVideo}: auth, the project resolution, the
 * video's status and the delivery node are per VIDEO, so a player that has to try three outputs
 * — or one output in two formats — paid all of it once per manifest, and Sanctum stamps
 * `last_used_at` on every one of those requests, all to the same row.
 *
 * Playback makes the case sharper than downloads did. A download token is scoped to its one
 * object, so N tracks genuinely need N signatures; a playback token is scoped to the manifest's
 * DIRECTORY — `token_path` on Bunny ({@see BunnyProvider::signed}), an ACL with
 * a trailing `*` on the edge ({@see SelfHostedProvider::aclFor}) — and every
 * output of a video packages into the same `{videoUlid}/play/` prefix
 * ({@see Output::packagePrefix}). So the signatures this loop produces all authorize exactly the
 * same bytes. Collapsing them to one is a change inside the providers (both are bound `scoped`,
 * so a per-request memo keyed on the directory would do it) and needs nothing from here or from
 * the {@see CdnProvider} contract — which is why this mints the obvious way and stays correct
 * either way.
 */
class VodLinkService
{
    public function __construct(
        private CdnProvider $cdn,
        private TrackingRegistry $tracking,
        private AssetUrlResolver $assets,
    ) {}

    /**
     * Every manifest of the video that can be played, one per output and format.
     *
     * An output that cannot be served is left out, not thrown. A video is `completed` once at
     * least one output succeeded, so a failed sibling is an ordinary state and must not cost the
     * survivors their links; what a caller does about it is the same either way — play one of the
     * outputs that came back. The 409 and the 503 stay whole-request: they are conditions of the
     * video and of the fleet, under which nothing could have been served.
     *
     * @param  string  $ip  the address that will fetch the manifests
     */
    public function forVideo(
        Video $video,
        ?int $resolution,
        string $ip,
        ?string $trackingId = null,
    ): VideoVodLinksData {
        if ($video->status !== VideoStatus::COMPLETED->value) {
            abort(409, 'The video is still processing.');
        }

        $sources = [];
        $signed = [];

        foreach ($video->outputs as $output) {
            $formats = $this->playableFormats($output);

            if ($formats === []) {
                continue;
            }

            // Resolved once per output, not once per format: the cap depends on the output's own
            // renditions and the caller's ceiling, and both manifests of one output are the same
            // ladder. The same holds for the codecs, which cost a pass over the streams each.
            $cap = $output->resolveCap($resolution);
            $videoCodec = $output->videoCodec();
            $audioCodec = $output->audioCodec();

            foreach ($formats as $format) {
                $link = $this->sign($video, $output->manifestPath($format, $cap), $ip);
                $signed[] = $link;

                $sources[] = new VodSourceData(
                    url: $link->url,
                    format: $format,
                    outputUlid: $output->ulid,
                    videoCodec: $videoCodec,
                    audioCodec: $audioCodec,
                );
            }
        }

        // One cache round trip for the whole answer rather than one per manifest.
        $this->tracking->recordMany($signed, $trackingId);

        return new VideoVodLinksData(
            // Resolved once, and outside the loop: both are per-video and unsigned, the same two
            // URLs whatever the caller ends up playing.
            thumbnailUrl: $this->assets->for($video->ulid, Video::THUMBNAIL_FILENAME),
            storyboardUrl: $this->assets->for($video->ulid, Video::STORYBOARD_VTT_FILENAME),
            expiresAt: now()->addSeconds($this->tracking->tokenWindow())->toIso8601String(),
            sources: $sources,
        );
    }

    /**
     * The formats to mint for one output — empty when there is nothing to serve, which is what
     * keeps the output out of the answer entirely.
     *
     * The status check is not redundant with the video's. A video is `completed` once at least one
     * output succeeded, so a failed output sits under a completed video, and `formats()` falls
     * back to a live computation from the attached streams while `packaged_formats` is null
     * ({@see Output::formats}) — exactly the state a failed output is in. Minting from that names
     * a manifest that was never written, and the link 404s at the CDN, where the caller cannot
     * tell our fault from theirs. Same reasoning as {@see DownloadLinkService::forStream}'s
     * `file_size` check.
     *
     * @return list<string>
     */
    private function playableFormats(Output $output): array
    {
        return $output->status === VideoStatus::COMPLETED ? $output->formats() : [];
    }

    /**
     * The node behind this is resolved once per request, not once per call ({@see ProxyRing}),
     * which is what makes signing in a loop cheap.
     */
    private function sign(Video $video, string $path, string $ip): SignedLink
    {
        try {
            return $this->cdn->manifestUrl($video, $path, $ip, app()->isLocal());
        } catch (NoCdnNodeAvailableException) {
            // The same answer the per-output mint gives: the video exists and the caller is
            // entitled to it, there is just nothing to serve it right now.
            abort(503, 'No node available');
        }
    }
}
