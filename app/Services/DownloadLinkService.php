<?php

namespace App\Services;

use App\Data\BunnyConfigData;
use App\Data\DownloadLinkData;
use App\Data\SelfHostedConfigData;
use App\Enums\CdnDriver;
use App\Enums\VideoStatus;
use App\Exceptions\NoCdnNodeAvailableException;
use App\Models\Project;
use App\Models\Stream;
use App\Services\Cdn\CdnProvider;
use App\Settings\CdnSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Mints a download link for ONE track.
 *
 * Downloads are deliberately per-track and API-only: the packaged renditions carry no audio
 * ({@see ChunkTranscodeService} encodes video with `-an`), so anything playable has
 * to be muxed from the pieces, and that is the caller's job. One request per track keeps each
 * signature scoped to the object it hands out.
 */
class DownloadLinkService
{
    /**
     * Types that have an object in the download zone. `original` is absent on purpose: it is the
     * untouched upload, lives in its own zone, and handing it out is a different product decision
     * from offering the encoded tracks.
     */
    private const DOWNLOADABLE = ['video', 'audio', 'subtitle'];

    public function __construct(
        private CdnProvider $cdn,
        private CdnSettings $settings,
    ) {}

    /**
     * Resolve the track inside the caller's project and mint its link. The scoping lives here, not
     * in the controller: a stream looked up by ULID alone would hand one project's masters to
     * another.
     */
    public function forStreamUlid(string $ulid, Project $project, ?string $trackingId = null): DownloadLinkData
    {
        $stream = Stream::with('video')
            ->whereHas('video', fn ($query) => $query->where('project_id', $project->id))
            ->where('ulid', $ulid)
            ->firstOrFail();

        return $this->forStream($stream, $trackingId);
    }

    public function forStream(Stream $stream, ?string $trackingId = null): DownloadLinkData
    {
        if (! in_array($stream->type, self::DOWNLOADABLE, true)) {
            abort(422, "A {$stream->type} track cannot be downloaded.");
        }

        $video = $stream->video;

        if ($video->status !== VideoStatus::COMPLETED->value) {
            abort(409, 'The video is still processing.');
        }

        $key = $stream->storedPath($video);

        // A template with `keep_processed_files` off drops the renditions before they ever reach
        // S3, so the row exists and the object does not. Fail here rather than hand out a link
        // that 404s at the CDN, where the caller cannot tell our fault from theirs.
        if (! Storage::disk('s3')->exists($key)) {
            abort(404, 'This track was not retained for download.');
        }

        try {
            $url = $this->cdn->downloadUrl($video->ulid, $key, app()->isLocal(), $trackingId);
        } catch (NoCdnNodeAvailableException) {
            // Same answer playback gives ({@see \App\Http\Controllers\VodController}): the track
            // exists and the caller is entitled to it, there is just nothing to serve it right now.
            abort(503, 'No node available');
        }

        return new DownloadLinkData(
            url: $url,
            expiresAt: now()->addSeconds($this->tokenWindow())->toIso8601String(),
            // The stored name as-is: a ULID plus its extension. Unique by construction, so a caller
            // fetching several tracks never has two land on the same name, and there is nothing to
            // sanitise before it touches a filesystem or a Content-Disposition header.
            filename: basename($key),
            type: $stream->type,
            size: $stream->file_size,
        );
    }

    /**
     * How long the link the active provider just signed stays valid. Each provider keeps its own
     * window, so reading one of them unconditionally would have reported the self-hosted lifetime
     * for a link Bunny signed with a different one.
     */
    private function tokenWindow(): int
    {
        $config = $this->settings->providers[$this->settings->provider] ?? [];

        return match (CdnDriver::from($this->settings->provider)) {
            CdnDriver::Bunny => BunnyConfigData::from($config)->tokenWindow,
            CdnDriver::SelfHosted => SelfHostedConfigData::from($config)->tokenWindow,
        };
    }
}
