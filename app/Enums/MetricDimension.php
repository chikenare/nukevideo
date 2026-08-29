<?php

namespace App\Enums;

use App\Http\Controllers\Api\MetricsController;

/**
 * A column of `usage` a caller may break its numbers down by, and what it takes to be allowed to.
 *
 * The authorization lives here rather than in the shape of the URL tree, and that is the whole
 * design. Named endpoints put each question behind its own route and its own middleware group,
 * which works until the sixth question — and every one of them costs a controller, a pair of Data
 * objects, tests and a docs section. A single query endpoint costs one entry in this enum. What
 * makes that safe is that the dimensions are not equally shareable, so each one says for itself
 * what it needs ({@see MetricsController::query()}).
 *
 * The asymmetry, spelled out, because it is not obvious:
 *
 * - `date` and `metric` name nobody. Grouping by them tells a caller nothing about who else is on
 *   the installation, so they need nothing.
 * - Everything else names something, and is answered only when the query is narrowed to a project —
 *   where what comes back is the caller's own — or when the caller names the values it is asking
 *   about, which bounds the question to things it already had. Both routes for `video`, `ip` and
 *   `tracking_id`; only the first for `node_id` and `cache`, since a caller cannot name an edge it
 *   owns because it owns none. Inside a project those two say which edge served THAT project's
 *   traffic and how well it cached; the fleet-wide view stays on the admin-only per-node report.
 * - `ip` is also personal data, which is why it takes the same rule as the rest rather than a
 *   looser one for convenience.
 * - `external_user_id` narrows on a second axis as well: the row carries the `user_id` of the owning
 *   account, so a query touching it is pinned to the caller's account the way `/api/usage` is.
 */
enum MetricDimension: string
{
    case DATE = 'date';
    case METRIC = 'metric';
    case TRACKING_ID = 'tracking_id';
    case VIDEO = 'video';
    case EXTERNAL_USER_ID = 'external_user_id';
    case IP = 'ip';
    case NODE_ID = 'node_id';
    case CACHE = 'cache';

    /**
     * The SQL that produces this dimension. A class-controlled expression, never caller input — the
     * caller picks a case of this enum, not a column name.
     */
    public function selection(): string
    {
        return match ($this) {
            // Stored as IPv6 (v4 arrives mapped), which is meaningless to a consumer as a number.
            self::IP => 'IPv6NumToString(ip) AS ip',
            // Aliased to the name every other video breakdown already answers with.
            self::VIDEO => 'video_ulid AS video',
            default => $this->value,
        };
    }

    /** The key the row comes back under, and what GROUP BY refers to. */
    public function alias(): string
    {
        return $this->value;
    }

    /**
     * Whether this dimension can be answered at all without narrowing the query.
     *
     * The ones that NAME something — a video, a viewer, a viewer's address, an edge — enumerate
     * whoever else is on the installation when the query is unnarrowed. A date does not, and a
     * metric does not.
     */
    public function requiresScope(): bool
    {
        return in_array($this, [self::VIDEO, self::IP, self::TRACKING_ID, self::NODE_ID, self::CACHE], true);
    }

    /**
     * The request field whose values can stand in for project context, or null when nothing can.
     *
     * Naming what you are asking about bounds the question to values you already had, which is as
     * good a boundary as scoping. `node_id` and `cache` have no such list — a caller cannot name an
     * edge it owns, because it owns none — so for those two the project is the only way in.
     */
    public function namedBy(): ?string
    {
        return match ($this) {
            self::VIDEO, self::IP => 'videos',
            self::TRACKING_ID => 'trackingIds',
            default => null,
        };
    }

    /** Forces the query to the caller's own account: the row carries the `user_id` to do it with. */
    public function scopesToAccount(): bool
    {
        return $this === self::EXTERNAL_USER_ID;
    }
}
