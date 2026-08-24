<?php

namespace App\Data\Stream;

use App\Data\RequestData;
use App\Services\Cdn\BunnyProvider;
use Spatie\LaravelData\Attributes\Validation\Max;

class DownloadStreamData extends RequestData
{
    public function __construct(
        /**
         * Caller-supplied tracking id, echoed into the signed URL so the CDN's request log can
         * attribute the transfer back to whatever the integrator is counting — an end user, a
         * session, an invoice.
         *
         * The charset is deliberately narrow. Bunny folds every query parameter into the token
         * signature as `key=value` pairs joined by `&`, so an `&` or an `=` in this value would
         * split it into parameters of its own and let a caller reshape the signed parameter set.
         * Anything outside the URL-safe alphabet is rejected rather than escaped, because the value
         * also has to survive a log line intact to be worth anything.
         *
         * `tracking_id` on the wire, like `external_user_id`. Inside a Bunny URL it still rides
         * as `tid` ({@see BunnyProvider::downloadUrl}) — that name is not part of the API surface.
         */
        public ?string $trackingId = null,
    ) {}

    /**
     * Written out here rather than as property attributes: an explicit `rules()` entry REPLACES the
     * inferred and attribute-derived rules for that key, so a `#[Max]` alongside this would silently
     * never run. Only Bunny carries the value ({@see BunnyProvider::downloadUrl}).
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
