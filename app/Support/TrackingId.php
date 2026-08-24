<?php

namespace App\Support;

use App\Models\User;

/**
 * Decides the tracking id a minted playback or download link carries.
 *
 * An explicit one always wins — it is the integrator naming its own viewer. Absent that, a
 * session or personal-token request is the account user acting as their own viewer (the panel
 * never sends one), so their ULID keeps that traffic attributed instead of falling into the
 * unattributed bucket. A project key stays null: it has no user behind it — the actor is the
 * Project, which also has a ulid, hence the instanceof rather than a bare `?->ulid` — and
 * inventing an id would pollute the integrator's own id space.
 */
class TrackingId
{
    public static function resolve(?string $explicit, ?object $actor): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        return $actor instanceof User ? $actor->ulid : null;
    }
}
