<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * ffmpeg was killed by a signal (worker shutdown during a deploy, container
 * stop) rather than failing on the media itself. This is transient: the stream
 * must NOT be marked FAILED and the video must NOT be failed by the chain's
 * catch — heartbeats stop with the dead worker and the reaper requeues the
 * video on a healthy worker within minutes.
 */
class EncodeInterruptedException extends RuntimeException
{
    public static function fromErrorOutput(string $errorOutput): self
    {
        return new self('Encode interrupted by signal (worker shutdown): '.substr($errorOutput, -300));
    }

    /**
     * ffmpeg logs this exact phrase when it exits on SIGTERM/SIGINT.
     */
    public static function causedTermination(string $errorOutput): bool
    {
        return (bool) preg_match('/received signal (15|2)/', $errorOutput);
    }
}
