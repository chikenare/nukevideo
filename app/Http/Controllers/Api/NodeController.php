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
        return $this->nodeService->index();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'hostname' => 'nullable|max:255',
            'name' => 'required|string|max:255|unique:nodes,name',
            'ip_address' => 'required|ip',
            'type' => 'required|string|in:worker,proxy',
        ]);

        $node = $this->nodeService->createNode($validated);

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
            'ip_address' => 'sometimes|ip',
            'hostname' => 'nullable|max:255',
            'max_workers' => 'sometimes|integer|min:1|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        $node->update($validated);

        return new NodeResource($node->fresh());
    }

    public function metrics(string $id)
    {
        $node = Node::findOrFail($id);

        $node = $this->nodeService->syncNodeMetrics($node);

        return new NodeResource($node);
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
