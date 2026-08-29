<?php

namespace App\Enums;

/**
 * What a number means, so a client can format it without being told how to.
 *
 * The API emits the unit, never the formatting: `bytes` says "this is a size", not "render it as
 * GB". A client already knows how to turn a size into text in its own locale, and the one thing it
 * cannot guess is which of the three a bare number is — `usage` keeps every metric in one `value`
 * column and only the metric name says whether it is seconds or bytes.
 */
enum MetricUnit: string
{
    case BYTES = 'bytes';
    case SECONDS = 'seconds';
    case COUNT = 'count';
}
