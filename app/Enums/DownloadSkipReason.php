<?php

namespace App\Enums;

/**
 * Why a track a caller asked for came back without a link.
 *
 * A batch answers per track rather than failing whole: one stale ULID in a list of forty must not
 * cost the other thirty-nine their links. Conditions of the VIDEO — still processing, no delivery
 * node — stay whole-request errors, because none of the tracks could be served either.
 */
enum DownloadSkipReason: string
{
    /** The template did not keep processed files, so the row exists and the object never synced. */
    case NOT_RETAINED = 'not_retained';

    /** An `original` track: it lives in its own zone and is never handed out. */
    case NOT_DOWNLOADABLE = 'not_downloadable';

    /** No such track on this video — a stale id, or one belonging to another video entirely. */
    case NOT_FOUND = 'not_found';
}
