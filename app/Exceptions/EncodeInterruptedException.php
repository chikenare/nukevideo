<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * ffmpeg was killed by a signal (worker shutdown), not a media failure. Transient: the stream
 * must NOT be marked FAILED — the reaper requeues the video on a healthy worker.
 */
class EncodeInterruptedException extends RuntimeException
{
    public static function fromErrorOutput(string $errorOutput): self
    {
        return new self('Encode interrupted by signal (worker shutdown): '.substr($errorOutput, -300));
    }

    /** ffmpeg logs this phrase when it exits on SIGTERM/SIGINT. */
    public static function causedTermination(string $errorOutput): bool
    {
        return (bool) preg_match('/received signal (15|2)/', $errorOutput);
    }
}
