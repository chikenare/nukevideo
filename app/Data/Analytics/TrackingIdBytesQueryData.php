<?php

namespace App\Data\Analytics;

use App\Enums\UsageGranularity;
use App\Enums\UsageMetric;
use App\Http\Controllers\Api\AnalyticsController;
use App\Support\TrackingId;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * The batch bandwidth read ({@see AnalyticsController::trackingIds()}): a date range and the
 * tracking ids to report on.
 *
 * Snake-cased on the wire (`tracking_ids`, `include_unattributed`) like the rest of the read
 * endpoints, which is the global input mapping, so no per-property attribute is needed here.
 */
class TrackingIdBytesQueryData extends BatchQueryData
{
    public function __construct(
        public string $from,
        public string $to,
        /** @var list<string> */
        #[MapInputName(CamelCaseMapper::class)]
        public array $trackingIds,
        public ?string $metric = null,
        public UsageGranularity $granularity = UsageGranularity::TOTAL,
        /**
         * Add the traffic whose tracking id did not survive the round trip through the CDN log,
         * reported under an empty id. A flag rather than an entry in the list because an empty
         * string cannot be validated as an id ({@see rules()}), and because a caller reconciling
         * its own ids against the total wants to ask for it explicitly.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public bool $includeUnattributed = false,
    ) {}

    /**
     * The ids to hand the query. The unattributed bucket is the column's own default value, so
     * asking for it is asking for the empty string — which is exactly the value `rules()` refuses
     * as a caller-named id, hence the flag.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return $this->includeUnattributed ? [...$this->trackingIds, ''] : $this->trackingIds;
    }

    public static function rules(): array
    {
        return static::rangeRules() + [
            'trackingIds' => 'required|array|min:1|max:'.self::MAX_BATCH,

            // `required` in front of the shared rules, which are written for a single optional
            // filter: there, null means "no filter" and '' means "the traffic that carried no id".
            // Neither reading survives inside a batch — a null element would reach the
            // `Array(String)` binding as `\N`, and an empty one is what `include_unattributed`
            // exists to ask for — so an element has to be a real id. The alphabet itself still
            // comes from the one place that owns it, so the ids this accepts stay exactly the ids
            // a mint accepts and the ingest keeps.
            'trackingIds.*' => array_merge(['required'], TrackingId::rules()),

            // Which kind of delivery to report, if the caller wants only one. Validated against the
            // same set the query is constrained to, so a metric that would report upload volume or
            // encoding seconds as bytes cannot get in.
            'metric' => ['nullable', 'string', Rule::in(UsageMetric::delivery())],

            'granularity' => ['nullable', Rule::enum(UsageGranularity::class)],
            'includeUnattributed' => 'nullable|boolean',
        ];
    }
}
