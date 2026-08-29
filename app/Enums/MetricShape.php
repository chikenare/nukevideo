<?php

namespace App\Enums;

/**
 * How a metrics result is laid out.
 *
 * `LONG` is one row per (dimensions, metric) — the honest shape of the query, and what a caller
 * summing for an invoice wants.
 *
 * `WIDE` pivots the metric out into one field per metric and fills every day of the range that
 * produced no traffic with zero. That is not presentation — no labels, no formats, no colours — it
 * is the shape a charting library asks for: one accessor per series, and a dense x axis, because a
 * line drawn over dates that ClickHouse simply omitted has holes where it should have zeros. Every
 * consumer would otherwise write that pivot again in its own language.
 */
enum MetricShape: string
{
    case LONG = 'long';
    case WIDE = 'wide';
}
