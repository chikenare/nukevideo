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

    public function __construct(
        public ?string $search = null,
        public ?string $externalUserId = null,
        public ?string $externalResourceId = null,
        /** One status, or several comma-separated (`completed,failed`). */
        public ?string $status = null,
        public string $sort = 'created_at',
        public string $direction = 'desc',
        public int $perPage = 15,
    ) {}

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
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            // Capped: the payload embeds each video's outputs and streams, so an unbounded page is
            // a heavy query and a heavy response.
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
