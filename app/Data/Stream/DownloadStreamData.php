<?php

namespace App\Data\Stream;

use App\Data\RequestData;
use App\Services\Cdn\TrackingRegistry;
use App\Support\TrackingId;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

class DownloadStreamData extends RequestData
{
    public function __construct(
        /**
         * Caller-supplied tracking id: whatever the integrator is counting — an end user, a
         * session, an invoice. It never enters the URL; the mint records it against the link's
         * token ({@see TrackingRegistry}) and the log ingest attributes the
         * transfer through that.
         *
         * The charset is deliberately narrow: the value is a label in a cache and a column in the
         * analytics, and it used to be a URL component — keeping the alphabet stable means an
         * integrator's ids never have to change shape.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $trackingId = null,
    ) {}

    /**
     * Written out here rather than as property attributes: an explicit `rules()` entry REPLACES the
     * inferred and attribute-derived rules for that key, so a `#[Max]` alongside this would silently
     * never run.
     *
     * Keyed on the property's own name rather than the mapped `tracking_id`. Spatie normalises
     * the payload's keys to property names before validating, so one entry covers both spellings —
     * which is the point here, since this value's whole validation story is the charset. Keying it
     * on the mapped name instead let `trackingId` through unchecked, and it used to be written
     * twice to paper over exactly that.
     */
    public static function rules(): array
    {
        return [
            'trackingId' => TrackingId::rules(),
        ];
    }
}
