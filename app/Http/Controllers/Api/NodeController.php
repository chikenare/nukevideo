<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Node\StoreNodeRequest;
use App\Http\Requests\Node\UpdateNodeRequest;
use App\Http\Resources\Node\NodeResource;
use App\Models\Node;
use App\Services\DockerService;
use App\Services\NodeService;
use Illuminate\Http\Request;

class NodeController extends Controller
{
    public function __construct(
        private NodeService $nodeService,
    ) {}

    public function index()
    {
        return $this->nodeService->index();
    }

    public function store(StoreNodeRequest $request)
    {
        $validated = $request->validated();

        $node = $this->nodeService->createNode($validated);

        return new NodeResource($node->fresh());
    }

    public function show(string $id)
    {
        $node = Node::findOrFail($id);

        return new NodeResource($node);
    }

    public function update(UpdateNodeRequest $request, Node $node)
    {
        $validated = $request->validated();

        $node->update($validated);

        return response()->json([
            'data' => new NodeResource($node->fresh()),
            'message' => 'Node updated successfully'
        ]);
    }

    public function pendingJobs(Node $node)
    {
        return response()->json($this->nodeService->getPendingJobs($node));
    }

    public function deploySteps(Node $node)
    {
        return response()->json([
            'steps' => $this->nodeService->getDeploySteps($node),
        ]);
    }

    public function containers(Node $node)
    {
        $node->load('sshKey');

        $docker = app(DockerService::class);
        $containers = $docker->listContainers($node);

        return response()->json(['containers' => $containers]);
    }

    public function deploy(Node $node, Request $request)
    {
        $step = $request->query('step');

        if (! $step) {
            return response()->json(['error' => 'Step is required'], 422);
        }

        $node->load('sshKey');

        $this->nodeService->deployStep($node, $step);

        return response()->json(['message' => "Step '{$step}' completed"]);
    }

    public function destroy(string $id)
    {
        $node = Node::with('sshKey')->findOrFail($id);

        $docker = app(DockerService::class);
        $containers = $docker->listContainers($node);
        $prefix = "nukevideo_{$node->type}_{$node->id}_";

        foreach ($containers as $container) {
            $name = $container['Names'] ?? '';
            if (str_starts_with($name, $prefix)) {
                $docker->removeContainer($node, $name);
            }
        }

        $node->delete();

        return response()->json([
            'message' => 'Node deleted successfully',
        ]);
    }

    public function metrics()
    {
        return response()->json([
            'data' => [
                'metrics' => $this->nodeService->metrics(),
            ],
        ]);
    }
}
