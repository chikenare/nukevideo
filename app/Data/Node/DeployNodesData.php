<?php

namespace App\Data\Node;

use App\Data\RequestData;
use App\Rules\ChunkStoreConfiguredRule;

class DeployNodesData extends RequestData
{
    public function __construct(
        /** @var array<int, int> */
        public array $nodes,
        public bool $force = false,
    ) {}

    public static function rules(): array
    {
        return [
            'nodes' => ['required', 'array', 'min:1', new ChunkStoreConfiguredRule],
            'nodes.*' => ['integer', 'exists:nodes,id'],
        ];
    }
}
