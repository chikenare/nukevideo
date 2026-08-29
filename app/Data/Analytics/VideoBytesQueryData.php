<?php

namespace App\Data\Analytics;

use App\Enums\UsageGranularity;
use App\Enums\UsageMetric;
use App\Http\Controllers\Api\AnalyticsController;
use Illuminate\Validation\Rule;

/**
 * The per-title batch read ({@see AnalyticsController::videos()}): a date range and the video ULIDs
 * to report on.
 *
 * The list is validated for shape only. Ownership cannot be checked here — a rule has no access to
 * the resolved project — and is enforced in the controller, which is what makes this the one batch
 * read scoped to a tenant.
 */
class VideoBytesQueryData extends BatchQueryData
{
    public function __construct(
        public string $from,
        public string $to,
        /** @var list<string> */
        public array $videos,
        public ?string $metric = null,
        public UsageGranularity $granularity = UsageGranularity::TOTAL,
    ) {}

    public static function rules(): array
    {
        return static::rangeRules() + [
            'videos' => 'required|array|min:1|max:'.self::MAX_BATCH,

            // `ulid` rather than a hand-rolled regex, and `distinct` is deliberately absent: a
            // duplicate is folded by the query, not worth a 422. The column is written from a
            // public request path, so this is also the shape a stored value can have.
            'videos.*' => 'required|ulid',

            'metric' => ['nullable', 'string', Rule::in(UsageMetric::delivery())],
            'granularity' => ['nullable', Rule::enum(UsageGranularity::class)],
        ];
    }
}
