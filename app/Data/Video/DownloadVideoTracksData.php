<?php

namespace App\Data\Video;

use App\Data\RequestData;
use App\Data\Stream\DownloadStreamData;
use App\Services\Cdn\TrackingRegistry;
use App\Support\TrackingId;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

class DownloadVideoTracksData extends RequestData
{
    /**
     * Ceiling on an explicitly listed batch. It bounds the PAYLOAD, not the answer: the list can
     * only ever name tracks of one video, and omitting it asks for all of them, so what a caller
     * gets back is bounded by the template the operator wrote rather than by anything they send.
     * Generous on purpose — a video with many audio and subtitle languages is ordinary.
     */
    public const MAX_TRACKS = 200;

    public function __construct(
        /**
         * Which tracks to mint for. Omit it to ask for every downloadable track of the video,
         * which is the case this endpoint exists for; an explicit list is for fetching a subset
         * without a second round trip.
         *
         * `null` and `[]` are NOT the same: the first is "all of them", the second is a caller
         * literally naming no track, and answering that with everything would be inventing intent.
         *
         * @var list<string>|null
         */
        #[MapInputName(CamelCaseMapper::class)]
        public ?array $streamUlids = null,
        /**
         * Caller-supplied tracking id, exactly as the per-track mint takes it: it never enters a
         * URL, and the batch records it against every token it hands out
         * ({@see TrackingRegistry::recordMany}). One id for the whole batch, because a batch is
         * one viewer fetching one video's pieces.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $trackingId = null,
    ) {}

    /**
     * One key per field, because there is one spelling per field.
     *
     * The properties map camelCase explicitly, so the mapped name and the bare property name that
     * Spatie also binds as a fallback are the SAME string. That is what collapses the pair of
     * rules the snake_case default would otherwise need: under it the two differ, and a payload
     * using the property name would reach the property without passing a rule keyed only on the
     * mapped one. {@see DownloadStreamData} still carries that pair, because the spelling it
     * accepts is documented.
     *
     * An explicit `rules()` entry REPLACES the inferred and attribute-derived rules for its key,
     * so these are the whole story.
     */
    public static function rules(): array
    {
        return [
            'trackingId' => TrackingId::rules(),
            'streamUlids' => ['nullable', 'array', 'max:'.self::MAX_TRACKS],
            'streamUlids.*' => ['string', 'size:26'],
        ];
    }
}
