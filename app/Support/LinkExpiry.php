<?php

namespace App\Support;

use App\Services\Cdn\TrackingRegistry;

/**
 * How long a playback or download link a mint hands out stays valid, in seconds.
 *
 * The provider's token window is the default, not the ceiling: a stream wants an hour, and an app
 * saving a season to watch on a flight wants a day, from the same API. A ceiling tied to the
 * window would make the long one raise the window for every stream too. The ceiling is a constant
 * instead — what a link that leaks can be used for, which is not something one request should be
 * able to stretch to a year.
 */
class LinkExpiry
{
    /** Under a minute a player has not finished its first manifest before the link is gone. */
    public const MIN_SECONDS = 60;

    /** A week: enough for any download to finish or resume, short enough for a leaked link to die. */
    public const MAX_SECONDS = 604_800;

    /** @return list<string> */
    public static function rules(): array
    {
        return ['nullable', 'integer', 'min:'.self::MIN_SECONDS, 'max:'.self::MAX_SECONDS];
    }

    /** The lifetime of a mint: what the caller asked for, else the provider's token window. */
    public static function seconds(?int $requested, TrackingRegistry $tracking): int
    {
        return $requested ?? $tracking->tokenWindow();
    }
}
