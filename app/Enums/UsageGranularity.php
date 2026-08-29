<?php

namespace App\Enums;

/**
 * How finely a batch usage read is broken down over its date range.
 *
 * `TOTAL` is one row per dimension for the whole range — what an invoice line needs. `DAILY` adds
 * the date, which is what a month that a subscriber joined or left halfway through needs, and what
 * settles a dispute about which day the traffic happened on. It is opt-in because it multiplies the
 * response by the length of the range.
 */
enum UsageGranularity: string
{
    case TOTAL = 'total';
    case DAILY = 'daily';
}
