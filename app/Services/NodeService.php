<?php

namespace App\Services;

use App\Http\Resources\Node\NodeResource;
use App\Models\Node;
use Illuminate\Support\Collection;

class NodeService
{
    /**
     * Select the best node for processing a stream
     * Uses load balancing to distribute work evenly
     */
    public function selectNode(): ?Node
    {
        $activeNodes = $this->getActiveNodes();

        if ($activeNodes->isEmpty()) {
            return null;
        }

        // Find node with lowest current load
        $selectedNode = $activeNodes->sortBy(function ($node) {
            return $node->getCurrentLoad();
        })->first();

        return $selectedNode;
    }

    /**
     * Get all active nodes
     */
    public function getActiveNodes(): Collection
    {
        return Node::active()->get();
    }

    /**
     * Get the current load for a specific node
     */
    public function getNodeLoad(Node $node): int
    {
        return $node->getCurrentLoad();
    }

    /**
     * Update node health status
     */
    public function updateNodeHealth(Node $node): void
    {
        $node->markAsSeen();
    }

    /**
     * Register or update a node
     */
    public function registerNode(string $name, array $attributes = []): Node
    {
        $node = Node::firstOrNew(['name' => $name]);

        $node->fill(array_merge([
            'host' => gethostname(),
            'max_workers' => 3,
            'is_active' => true,
        ], $attributes));

        $node->save();

        return $node;
    }

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
                'summary' => [
                    'totalCapacity' => $nodes->sum('max_workers'),
                    'currentLoad' => $nodes->sum('current_load'),
                    'availableSlots' => $nodes->sum('available_capacity'),
                ]
            ]
        ];
    }
}
