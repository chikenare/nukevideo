<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Node\NodeResource;
use App\Models\Node;
use App\Services\NodeDeployService;
use App\Services\NodeService;
use Illuminate\Http\Request;

class NodeController extends Controller
{
    public function __construct(
        private NodeService $nodeService,
        private NodeDeployService $deployService
    ) {}

    public function index()
    {
        return $this->nodeService->getNodesStats();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:nodes,name',
            'type' => 'required|string|in:worker,proxy',
            'host' => 'required|string|max:255',
            'max_workers' => 'nullable|integer|min:1|max:100',
            'location' => 'nullable|string|max:255',
        ]);

        $node = $this->nodeService->registerNode(
            $validated['name'],
            [
                'type' => $validated['type'],
                'host' => $validated['host'],
                'max_workers' => $validated['max_workers'] ?? 3,
            ]
        );

        return new NodeResource($node);
    }

    public function show(string $id)
    {
        $node = Node::findOrFail($id);
        return new NodeResource($node);
    }

    public function update(Request $request, string $id)
    {
        $node = Node::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:nodes,name,' . $node->id,
            'type' => 'sometimes|string|in:worker,proxy',
            'host' => 'nullable|string|max:255',
            'max_workers' => 'sometimes|integer|min:1|max:100',
            'is_active' => 'sometimes|boolean',
            'location' => 'nullable|string|max:255',
        ]);

        $node->update($validated);

        return new NodeResource($node->fresh());
    }

    public function destroy(string $id)
    {
        $node = Node::findOrFail($id);

        $this->nodeService->deactivateNode($node);

        return response()->json([
            'message' => 'Node deactivated successfully'
        ]);
    }

    public function heartbeat(string $id)
    {
        $node = Node::findOrFail($id);

        $this->nodeService->updateNodeHealth($node);

        return response()->json([
            'message' => 'Node health updated',
            'last_seen_at' => $node->fresh()->last_seen_at,
        ]);
    }

    public function deploy(string $id)
    {
        $node = Node::findOrFail($id);

        if (! $node->host) {
            return response()->json([
                'message' => 'Node host is required for deployment',
            ], 422);
        }

        $result = $this->deployService->deploy($node);

        return response()->json($result, $result['success'] ? 200 : 500);
    }
}
