<?php

namespace App\Http\Controllers\Api;

use App\Data\Node\StoreNodeData;
use App\Data\Node\UpdateNodeData;
use App\Data\NodeData;
use App\Data\ValidationCheckData;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Services\DockerService;
use App\Services\NodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class NodeController extends Controller
{
    public function __construct(
        private NodeService $nodeService,
    ) {}

    public function index()
    {
        return $this->nodeService->index();
    }

    public function store(StoreNodeData $data)
    {
        $node = $this->nodeService->createNode($data->toDatabase());

        return response()->json(['data' => NodeData::fromModel($node->fresh())]);
    }

    public function show(string $id)
    {
        $node = Node::findOrFail($id);

        return response()->json(['data' => NodeData::fromModel($node)]);
    }

    public function update(UpdateNodeData $data, Node $node)
    {
        $node->update($data->toDatabase());

        return response()->json([
            'data' => NodeData::fromModel($node->fresh()),
            'message' => 'Node updated successfully',
        ]);
    }

    public function deploy(Node $node)
    {
        $node->load('sshKey');

        // Skip waiting for in-flight jobs (they redeliver ~31 min later instead): ?drain=0.
        $drain = request()->boolean('drain', true);

        return response()->stream(function () use ($node, $drain) {
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

                $this->nodeService->runFullDeploy($node, function ($output) use ($send) {
                    $send('output', $output);
                }, $drain);
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

        return response()->json(['checks' => ValidationCheckData::collect($checks)]);
    }

    public function destroy(string $id)
    {
        $node = Node::with('sshKey')->findOrFail($id);

        try {
            $docker = app(DockerService::class);
            $containers = $docker->listContainers($node);
            $prefixes = ["nukevideo_{$node->type->value}_{$node->id}"];
            if ($node->type->value === 'worker') {
                $prefixes[] = "nukevideo_storage_{$node->id}";
            }

            foreach ($containers as $container) {
                $name = ltrim($container['Names'] ?? '', '/');
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $docker->removeContainer($node, $name);
                        break;
                    }
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

    /**
     * How long a freshly generated bootstrap command stays usable. It is pasted into a terminal
     * within seconds of being generated, so the window only has to cover that — and every second
     * of it is a second in which the URL sits in a shell history, an access log or a chat.
     */
    private const BOOTSTRAP_TTL_MINUTES = 15;

    /** Remembers a spent nonce for longer than any signature can stay valid. */
    private const BOOTSTRAP_REPLAY_TTL_MINUTES = 60;

    public function generateBootstrapToken(Node $node)
    {
        if ($node->type->value !== 'worker') {
            return response()->json(['message' => 'Bootstrap tokens are only available for worker nodes.'], 422);
        }

        // The script this URL serves carries the instance's entire credential set in cleartext —
        // APP_KEY, the database and Redis passwords, the S3 and ClickHouse keys, the webhook and
        // internal secrets, the CDN token secret. It is delivered as a `curl … | bash` one-liner,
        // so the URL predictably ends up in shell history, in the proxy's access log and in
        // whatever ticket the admin pasted it into. The nonce below makes reading it there
        // useless: the first fetch spends it and every later one gets a 410.
        $url = URL::temporarySignedRoute(
            'nodes.bootstrap',
            now()->addMinutes(self::BOOTSTRAP_TTL_MINUTES),
            ['node' => $node->id, 'nonce' => Str::random(40)],
            absolute: true,
        );

        return response()->json(['command' => "curl -fsSL \"{$url}\" | bash"]);
    }

    public function bootstrapScript(Request $request, Node $node)
    {
        // The signature already proves the nonce is one we minted, so the only thing left to
        // establish is that it has not been spent. `add` is the atomic half of that check: it
        // succeeds for exactly one caller, so two concurrent fetches cannot both win.
        $nonce = (string) $request->query('nonce');
        $spent = $nonce === '' || ! Cache::add(
            "node-bootstrap-used:{$node->id}:{$nonce}",
            true,
            now()->addMinutes(self::BOOTSTRAP_REPLAY_TTL_MINUTES),
        );

        if ($spent) {
            Log::warning('Bootstrap script refused: token already used or missing', [
                'node_id' => $node->id,
                'ip' => $request->ip(),
            ]);

            return response('This bootstrap command has already been used. Generate a new one.', 410);
        }

        Log::info('Bootstrap script served', ['node_id' => $node->id, 'ip' => $request->ip()]);

        $script = $this->nodeService->buildDeployScript($node);

        return response($script, 200, [
            'Content-Type' => 'text/x-sh',
            // Nothing between here and the terminal should keep a copy.
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
