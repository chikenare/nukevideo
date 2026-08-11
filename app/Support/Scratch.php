<?php

namespace App\Support;

use RuntimeException;

/** Local scratch directories, which several jobs on the same node create concurrently. */
class Scratch
{
    /**
     * Creates a directory if it is not there yet, tolerating a concurrent creator.
     *
     * `is_dir()` followed by a bare `mkdir()` is a race whenever the directory is shared, and it
     * always is here: every chunk job of one rendition writes into the same stream directory, and
     * the thumbnail and storyboard jobs are dispatched back to back into the same scratch root.
     * The loser of the race gets "File exists" as an E_WARNING, which Laravel's error handler
     * rethrows as an ErrorException — that counts against the job's `maxExceptions` and, twice
     * over, fails the whole video for nothing.
     */
    public static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        // The second check is the point: `@mkdir` returning false because someone else just
        // created it is success, not failure.
        if (! @mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Could not create scratch directory: {$path}");
        }
    }
}
