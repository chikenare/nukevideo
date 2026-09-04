<?php

namespace App\Data\Video;

use App\Data\RequestData;
use App\Models\Output;
use App\Services\Cdn\SelfHostedProvider;
use App\Services\Cdn\TrackingRegistry;
use App\Support\TrackingId;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\IP;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * The body of the playback mint. Every field is optional, and none of them selects anything: the
 * answer carries every manifest the video can serve, and the client picks from it by codec and
 * format. What is left is the two things the SERVER cannot know — how tall the viewer's player
 * wants the ladder, and who the viewer is.
 *
 * There is no list of output ULIDs either. What this endpoint hoists — auth, the project, the
 * status check, the delivery node — is per video, so the honest key is the video and nothing
 * narrower.
 */
class PlayVideoData extends RequestData
{
    public function __construct(
        /** Caps every output's ladder at this height; each one resolves it against its own
         *  renditions ({@see Output::resolveCap}). */
        #[Min(144), Max(4320)]
        public ?int $resolution = null,
        /**
         * The address that will fetch the manifests, when minting from your own backend. Bunny
         * binds the token to it and refuses playback from any other address; the self-hosted edge
         * signs it into the token but never checks it ({@see SelfHostedProvider::sign}), so there
         * a wrong address costs nothing — and buys nothing either.
         */
        #[IP]
        public ?string $ip = null,
        /**
         * Your own label for this viewer. Never part of a URL: it is recorded against every token
         * the mint hands out ({@see TrackingRegistry::recordMany}). One id for the whole answer,
         * because one answer is one viewer opening one video.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $trackingId = null,
    ) {}

    /**
     * One key per field: the properties that map camelCase do so explicitly, so the mapped name
     * and the bare property name Spatie also binds are the same string
     * ({@see DownloadVideoTracksData::rules()} for why that matters).
     */
    public static function rules(): array
    {
        return [
            'trackingId' => TrackingId::rules(),
        ];
    }
}
