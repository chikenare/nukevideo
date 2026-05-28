<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Node\StoreNodeRequest;
use App\Http\Requests\Node\UpdateNodeRequest;
use App\Http\Resources\Node\NodeResource;
use App\Models\Node;
use App\Services\DockerService;
use App\Services\NodeService;
use Illuminate\Support\Facades\Log;

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
            'message' => 'Node updated successfully',
        ]);
    }

    public function pendingJobs(Node $node)
    {
        return response()->json($this->nodeService->getPendingJobs($node));
    }

    public function containers(Node $node)
    {
        $node->load('sshKey');

        $docker = app(DockerService::class);
        $containers = $docker->listContainers($node);

        return response()->json(['containers' => $containers]);
    }

    public function deploy(Node $node)
    {
        $node->load('sshKey');

        return response()->stream(function () use ($node) {
            $send = function (string $type, string $data = '') {
                echo 'data: '.json_encode(['type' => $type, 'data' => $data])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            try {
                if (! $node->is_active) {
                    throw new \RuntimeException('Node is not active');
                }

                $this->nodeService->runDeploy($node, function ($output) use ($send) {
                    $send('output', $output);
                });
                $send('done');
            } catch (\Throwable $e) {
                $send('error', $e->getMessage());
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function setup(Node $node)
    {
        $node->load('sshKey');

        return response()->stream(function () use ($node) {
            $send = function (string $type, string $data = '') {
                echo 'data: '.json_encode(['type' => $type, 'data' => $data])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            try {
                if (! $node->is_active) {
                    throw new \RuntimeException('Node is not active');
                }

                $this->nodeService->runSetup($node, function ($output) use ($send) {
                    $send('output', $output);
                });
                $send('done');
            } catch (\Throwable $e) {
                $send('error', $e->getMessage());
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function validateNode(Node $node)
    {
        $node->load('sshKey');

        $checks = $this->nodeService->runValidation($node);

        return response()->json(['checks' => $checks]);
    }

    public function destroy(string $id)
    {
        $node = Node::with('sshKey')->findOrFail($id);

        try {
            $docker = app(DockerService::class);
            $containers = $docker->listContainers($node);
            // Container names: nukevideo_proxy_{id}, nukevideo_worker_{id}_{index}
            // Docker returns names with a leading "/" which must be stripped
            $prefix = "nukevideo_{$node->type->value}_{$node->id}";

            foreach ($containers as $container) {
                $name = ltrim($container['Names'] ?? '', '/');
                if (str_starts_with($name, $prefix)) {
                    $docker->removeContainer($node, $name);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to remove containers for node', [
                'node_id' => $node->id,
                'error' => $e->getMessage(),
            ]);
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
