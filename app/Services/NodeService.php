<?php

namespace App\Services;

use App\Http\Resources\Node\NodeResource;
use App\Models\Node;

class NodeService
{
    /**
     * Deactivate a node
     */
    public function deactivateNode(Node $node): void
    {
        $node->update(['is_active' => false]);
    }

    /**
     * Get statistics for all nodes
     */
    public function getNodesStats(): array
    {
        $nodes = Node::all();

        return [
            'data' => [
                'nodes' => NodeResource::collection($nodes),
            ]
        ];
    }
}
