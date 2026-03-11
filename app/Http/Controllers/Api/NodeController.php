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
            'ssh_key_id' => 'nullable|exists:ssh_keys,id',
        ]);

        $node = $this->nodeService->createNode($validated);

        $node->refresh();
        $node->load('sshKey');
        $this->nodeService->joinToSwarm($node);

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
            'ssh_key_id' => 'nullable|exists:ssh_keys,id',
        ]);

        $node->update($validated);

        return new NodeResource($node->fresh());
    }

    public function metrics(string $id)
    {
        $node = Node::with('sshKey')->findOrFail($id);

        $node = $this->nodeService->syncNodeMetrics($node);

        return new NodeResource($node);
    }

    public function provision(string $id)
    {
        if (!app()->isProduction()) {
            return response()->json(['message' => 'Local env', 400]);
        }
        $node = Node::with('sshKey')->findOrFail($id);

        $this->nodeService->joinToSwarm($node);

        return response()->json(['message' => 'Provisioning started']);
    }

    public function deploy(string $id)
    {
        if (!app()->isProduction()) {
            return response()->json(['message' => 'Local env', 400]);
        }
        $node = Node::with('sshKey')->findOrFail($id);

        if (!$node->swarm_node_id) {
            throw new \RuntimeException("Node {$node->name} has no swarm_node_id — provision it first");
        }

        if ($node) {
            $this->nodeService->deploy($node);
        }

        return response()->json(['message' => 'Deploy started']);
    }

    public function destroy(string $id)
    {
        $node = Node::findOrFail($id);

        $node->delete();

        return response()->json([
            'message' => 'Node deleted successfully'
        ]);
    }
}
