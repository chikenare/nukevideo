<?php

namespace App\Data\AppSettings;

use Spatie\LaravelData\Data;

class SshKeyRotationData extends Data
{
    public function __construct(
        /** Whether the panel switched to the new key. False leaves everything as it was. */
        public bool $rotated,
        /** @var list<NodeRotationData> */
        public array $nodes,
    ) {}
}
