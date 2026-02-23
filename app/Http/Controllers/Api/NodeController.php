<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Node\NodeResource;
use App\Models\Node;
use App\Services\NodeService;
use Illuminate\Http\Request;

class NodeController extends Controller
{
    public function __construct(
        private NodeService $nodeService,
    ) {
    }

    public function index()
    {
        return $this->nodeService->getNodesStats();
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
            'base_url' => 'nullable|url|max:255',
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
}
