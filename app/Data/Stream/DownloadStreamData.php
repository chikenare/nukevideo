<?php

namespace App\Data\Stream;

use App\Data\RequestData;
use App\Services\Cdn\TrackingRegistry;

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
         *
         * `tracking_id` on the wire, like `external_user_id`.
         */
        public ?string $trackingId = null,
    ) {}

    /**
     * Written out here rather than as property attributes: an explicit `rules()` entry REPLACES the
     * inferred and attribute-derived rules for that key, so a `#[Max]` alongside this would silently
     * never run.
     *
     * Both spellings, deliberately: Spatie also binds the bare property name (`trackingId`) as a
     * fallback, so a payload using it would reach the property WITHOUT passing through a rule
     * keyed only on the mapped name — and this value's whole validation story is the charset.
     */
    public static function rules(): array
    {
        $trackingId = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+\z/'];

        return [
            'tracking_id' => $trackingId,
            'trackingId' => $trackingId,
        ];
    }
}
