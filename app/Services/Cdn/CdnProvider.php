<?php

declare(strict_types=1);

namespace App\Services\Cdn;

use App\Models\Video;

interface CdnProvider
{
    /**
     * Build the fully-qualified, signed manifest URL.
     *
     * @param  string  $path  host-relative manifest path ({videoUlid}/{file}), provider-agnostic
     */
    public function manifestUrl(Video $video, string $path, string $ip, bool $local): string;
}
