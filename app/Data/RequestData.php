<?php

namespace App\Data;

use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

abstract class RequestData extends Data
{
    /** Validated payload as snake_case array, absent (Optional) fields omitted. */
    public function toDatabase(): array
    {
        return collect($this->toArray())
            ->mapWithKeys(fn ($value, $key) => [Str::snake($key) => $value])
            ->all();
    }
}
