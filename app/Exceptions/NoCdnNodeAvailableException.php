<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** No active proxy node could serve a self-hosted playback URL. */
class NoCdnNodeAvailableException extends RuntimeException {}
