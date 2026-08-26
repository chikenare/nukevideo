<?php

namespace App\Http\Controllers\Api;

use App\Data\CacheDiskData;
use App\Data\Node\DeployNodeData;
use App\Data\Node\StoreNodeData;
use App\Data\Node\UpdateNodeData;
use App\Data\NodeData;
use App\Data\ValidationCheckData;
use App\Enums\NodeType;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Services\DockerService;
use App\Services\NodeService;
use App\Services\ProxyCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\LaravelData\Optional;

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

    public function deploy(DeployNodeData $data, Node $node)
    {
        // Skip waiting for in-flight jobs (they redeliver ~31 min later instead): ?drain=0.
        $drain = request()->boolean('drain', true);

        // Which spare disks to format into the cache pool, as the panel listed them. An empty
        // list means none — keep the cache off the disks and in a volume. The list is mandatory
        // for a production proxy ({@see DeployNodeData}): the service reads a missing one as
        // "every spare disk", and a missing list must never mean that by accident. A development
        // panel's deploy target is often the developer's own machine, where "every spare disk"
        // is a backup drive, so there an absent list means none.
        $disks = $data->disks instanceof Optional ? null : $data->disks;

        if ($disks === null && app()->isLocal()) {
            $disks = [];
        }

        // Belt and braces over the validation: null past this point is "all", and only a
        // worker (whose deploy ignores the list) may get there without having chosen.
        abort_if($disks === null && $node->type === NodeType::PROXY, 422, 'A proxy deploy needs the list of disks to format (an empty list formats none).');

        return response()->stream(function () use ($node, $drain, $disks) {
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
                }, $drain, $disks);
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
        $checks = $this->nodeService->runValidation($node);

        return response()->json(['checks' => ValidationCheckData::collect($checks)]);
    }

    /**
     * What a deploy would do to the node's disks, so the panel can show it before running one.
     * Only a proxy has a cache pool; a worker gets an empty list.
     */
    public function cacheDisks(Node $node, ProxyCacheService $cache)
    {
        if ($node->type !== NodeType::PROXY) {
            return response()->json(['data' => ['preselect' => false, 'disks' => []]]);
        }

        return response()->json(['data' => [
            // Whether the panel ticks the spare disks by default. Not from a development panel,
            // whose deploy target is often the developer's own machine.
            'preselect' => ! app()->isLocal(),
            'disks' => CacheDiskData::collect($cache->inventory($node)),
        ]]);
    }

    public function destroy(string $id)
    {
        $node = Node::findOrFail($id);

        try {
            $docker = app(DockerService::class);
            $containers = $docker->listContainers($node);
            // Through the model: the names carry an environment prefix, and a hand-built one here
            // would either miss this environment's containers or match the other environment's.
            $owned = $node->deployedContainerNames();
            if ($node->type === NodeType::WORKER) {
                // Only on delete. The node is gone for good, so its chunk store goes with it —
                // and if this was the storage server, another node has to be flagged as one.
                $owned[] = $node->storageContainerName();
            }

            foreach ($containers as $container) {
                $name = ltrim($container['Names'] ?? '', '/');
                // Exact names, not prefixes: `nukevideo_worker_1` is a prefix of
                // `nukevideo_worker_11`, so deleting node 1 also removed node 11's worker whenever
                // the two shared a host — which is the normal case for a development node.
                if (in_array($name, $owned, true)) {
                    $docker->removeContainer($node, $name);
                }
            }

            if ($node->type === NodeType::PROXY) {
                // The fallback cache volume is the node's alone and is otherwise never reclaimed.
                // A cache pool directory on a dedicated disk is not touched: the pool belongs to
                // the host and is what the next node deployed there picks up.
                $docker->removeVolume($node, ProxyCacheService::volumeFor($node));
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
        if ($node->type !== NodeType::WORKER) {
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
