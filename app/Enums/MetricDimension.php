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
 * - `external_user_id` is the integrator's own customer label, and the row carries the `user_id` of
 *   the owning account, so it can be constrained to the caller's account exactly the way
 *   `/api/usage` does.
 * - `video` belongs to a project, so it can be constrained to the caller's project.
 * - `ip` belongs to nobody in `usage` — it is a viewer of some video, and the table is instance-wide.
 *   It can only be constrained THROUGH a dimension that can be, which is why it demands both project
 *   context and an explicit list of the caller's own videos. It is also personal data, which is the
 *   reason it gets the strictest rule rather than the most convenient one.
 * - `tracking_id` cannot be constrained at all: `usage` has no column that says whose it is. The
 *   only boundary is that a caller has to know the id to name it, which is the same boundary the
 *   dedicated batch endpoint already accepts.
 * - `node_id` and `cache` are not a tenant's data in any sense — they describe the operator's fleet.
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

    /** Needs a resolved project, because it can only be answered within one. */
    public function requiresProject(): bool
    {
        return in_array($this, [self::VIDEO, self::IP], true);
    }

    /**
     * Needs an explicit list of the caller's own videos to hang its scoping off.
     *
     * Both project dimensions, for the same reason from opposite directions: `video` would
     * otherwise group over every video on the instance and hand back other tenants' ULIDs, and `ip`
     * has no owner at all, so without a bounded list of titles the caller owns it is a scan of
     * viewer addresses across the whole instance. A caller asking either question can say which
     * titles it means.
     */
    public function requiresVideoList(): bool
    {
        return $this->requiresProject();
    }

    /**
     * Needs the caller to name the values it is asking about.
     *
     * `tracking_id` has no column in `usage` saying whose it is, so "the ones you can name" is the
     * only boundary that exists. Grouping without a list would enumerate every viewer label on the
     * instance — one tenant's viewer identifiers handed to another — which is precisely what
     * cannot be allowed to be the convenient default.
     */
    public function requiresOwnList(): bool
    {
        return $this === self::TRACKING_ID;
    }

    /** Describes the operator's fleet rather than any tenant's traffic. */
    public function requiresAdmin(): bool
    {
        return in_array($this, [self::NODE_ID, self::CACHE], true);
    }

    /**
     * The dimensions a caller may name, given what it is. Returned rather than checked so the error
     * can list them.
     *
     * @return list<string>
     */
    public static function allowedFor(bool $isAdmin): array
    {
        return array_values(array_map(
            fn (self $d) => $d->value,
            array_filter(self::cases(), fn (self $d) => $isAdmin || ! $d->requiresAdmin()),
        ));
    }
}
