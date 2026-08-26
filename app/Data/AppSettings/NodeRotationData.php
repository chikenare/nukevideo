<?php

namespace App\Data\AppSettings;

use Spatie\LaravelData\Data;

class NodeRotationData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        /** The new public key is installed and the new private key was verified to log in. */
        public bool $ok,
        /** Why not, when `ok` is false. */
        public ?string $error,
    ) {}
}
