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
    /**
     * The one alphabet a tracking id may use, spelled out once: 1 to 64 characters of
     * `A-Z a-z 0-9 _ -`, the documented contract. The same pattern validates the mint
     * (playback and download links), filters the analytics and clamps the ingest, so a value
     * that passes validation is exactly a value the ingest keeps — the copies used to drift
     * (`*` against `+` against `{1,64}`), and a mint accepting what the ingest then dropped is
     * traffic silently billed to nobody. No dot: it was never documented and never accepted.
     *
     * `\z`, not `$`: the latter also matches before a trailing newline.
     */
    public const PATTERN = '/^[A-Za-z0-9_-]{1,64}\z/';

    /**
     * The validation rules for a tracking id, for a Data object's `rules()`. `nullable` because
     * every caller may omit it; an empty string is skipped by the regex rule anyway, and what it
     * then means (unattributed traffic, or "no filter") is the caller's decision.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['nullable', 'string', 'max:64', 'regex:'.self::PATTERN];
    }

    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }

    public static function resolve(?string $explicit, ?object $actor): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        return $actor instanceof User ? $actor->ulid : null;
    }
}
