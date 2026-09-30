<?php

namespace App\Data\Node;

use App\Data\RequestData;

class UpdateNodeEnvironmentData extends RequestData
{
    public function __construct(
        /** KEY=VALUE lines layered under every node's own `env`; empty clears it. */
        public ?string $environment,
    ) {}

    public static function rules(): array
    {
        return [
            // Present but nullable: an empty field arrives as null, and it is how the last
            // variable is removed. Bounded like a node's own `env` (UpdateNodeData).
            'environment' => ['present', 'nullable', 'string', 'max:10000'],
        ];
    }
}
