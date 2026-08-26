<?php

namespace App\Data\Video;

use App\Enums\VideoStatus;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * The listing's query string. Snake-cased on the wire like the rest of the read endpoints
 * (`per_page`, `external_user_id`), which is the global input mapping, so no per-property
 * attribute is needed here.
 */
class IndexVideosData extends Data
{
    /** What the list can be ordered by. `size` is summed over the streams, the rest are columns. */
    public const SORTS = ['created_at', 'name', 'size', 'duration', 'status'];

    /** The page size when none is asked for, and the cap: the payload embeds each video's outputs and streams. */
    public const PER_PAGE_DEFAULT = 15;

    public const PER_PAGE_MAX = 100;

    public function __construct(
        public ?string $search = null,
        public ?string $externalUserId = null,
        public ?string $externalResourceId = null,
        /** One status, or several comma-separated (`completed,failed`). */
        public ?string $status = null,
        public string $sort = 'created_at',
        public string $direction = 'desc',
        public int $perPage = self::PER_PAGE_DEFAULT,
    ) {}

    /**
     * Runs before validation and mapping, on the raw query string.
     *
     * `?sort=&direction=&per_page=` is how an integrator's query builder spells "the default"
     * — an empty value, not an absent key — and it must read as such: an empty string would
     * otherwise land in the typed property (or in `orderBy`) as is. The page size is clamped to
     * the cap rather than refused, which is what the listing has always done and what a client
     * asking for "everything" expects; only a non-numeric value is left for validation to reject.
     */
    public static function prepareForPipeline(array $properties): array
    {
        foreach (['sort', 'direction', 'per_page'] as $key) {
            if (array_key_exists($key, $properties) && ($properties[$key] === null || $properties[$key] === '')) {
                unset($properties[$key]);
            }
        }

        if (isset($properties['per_page']) && is_numeric($properties['per_page'])) {
            $properties['per_page'] = min(max((int) $properties['per_page'], 1), self::PER_PAGE_MAX);
        }

        return $properties;
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return $this->status === null || $this->status === ''
            ? []
            : array_values(array_unique(array_filter(array_map('trim', explode(',', $this->status)))));
    }

    public static function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'external_user_id' => 'nullable|string|max:255',
            'external_resource_id' => 'nullable|string|max:255',
            'status' => [
                'nullable', 'string',
                function ($attribute, $value, $fail) {
                    $allowed = array_column(VideoStatus::cases(), 'value');

                    foreach (explode(',', $value) as $status) {
                        if (! in_array(trim($status), $allowed, true)) {
                            $fail("Unknown status [{$status}]. Allowed: ".implode(', ', $allowed).'.');
                        }
                    }
                },
            ],
            // Not `nullable`: the properties are typed non-null with defaults, and an empty
            // value never reaches this ({@see prepareForPipeline()}).
            'sort' => [Rule::in(self::SORTS)],
            'direction' => [Rule::in(['asc', 'desc'])],
            // Already clamped to [1, PER_PAGE_MAX] upstream; this only refuses non-integers.
            'per_page' => 'integer',
        ];
    }
}
