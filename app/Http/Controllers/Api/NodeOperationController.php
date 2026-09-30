<?php

namespace App\Http\Controllers\Api;

use App\Data\ActivityLogData;
use App\Data\Node\DeployNodeData;
use App\Data\Node\DeployNodesData;
use App\Data\Node\StopNodeData;
use App\Enums\NodeAction;
use App\Enums\NodeType;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Services\NodeOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\LaravelData\Optional;

class NodeOperationController extends Controller
{
    public function __construct(private NodeOperationService $operations) {}

    public function index(Request $request): JsonResponse
    {
        $page = Activity::inLog('node')
            ->when($request->query('node'), fn ($q, $id) => $q->where('subject_type', Node::class)->where('subject_id', $id))
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => array_map(fn ($a) => ActivityLogData::fromModel($a), $page->items()),
            'currentPage' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    public function lines(Request $request, Activity $operation): JsonResponse
    {
        abort_unless($operation->log_name === 'node', 404);
        $after = max(0, $request->integer('after'));
        $lines = $this->operations->lines($operation->id, $after);

        return response()->json([
            'lines' => $lines,
            'next' => $after + count($lines),
            'status' => $operation->properties['status'],
        ]);
    }

    public function deploy(DeployNodeData $data, Node $node): JsonResponse
    {
        // The disk rules and the local-panel default are unchanged from the old SSE endpoint:
        // null past this point means "every spare disk", which only a worker may reach.
        $disks = $data->disks instanceof Optional ? null : $data->disks;
        if ($disks === null && app()->isLocal()) {
            $disks = [];
        }
        abort_if($disks === null && $node->type === NodeType::PROXY, 422, 'A proxy deploy needs the list of disks to format (an empty list formats none).');

        return $this->queue($this->operations->begin($node, NodeAction::Deploy, $data->force, $disks));
    }

    public function start(Node $node): JsonResponse
    {
        return $this->queue($this->operations->begin($node, NodeAction::Start));
    }

    public function stop(StopNodeData $data, Node $node): JsonResponse
    {
        return $this->queue($this->operations->begin($node, NodeAction::Stop, $data->force));
    }

    public function deployMany(DeployNodesData $data): JsonResponse
    {
        $batch = (string) Str::ulid();
        $started = [];
        $skipped = [];

        foreach (Node::whereKey($data->nodes)->get() as $node) {
            // A stopped node stays stopped: a deploy activates what it deploys, and one taken out
            // for maintenance must not come back because the fleet was updated — nor, if its host
            // is down, fail and cancel every proxy chained after it.
            //
            // `[]` for proxies: a fleet deploy updates what is there and never formats a disk
            // nobody was shown. Adding disks to a pool stays a per-node deploy.
            $op = $node->is_active
                ? $this->operations->begin($node, NodeAction::Deploy, $data->force, [], $batch)
                : null;
            $op ? $started[] = $op : $skipped[] = $node->id;
        }

        $this->operations->dispatch(...$started);

        return response()->json([
            'data' => array_map(fn ($op) => ActivityLogData::fromModel($op), $started),
            'skipped' => $skipped,
        ], 202);
    }

    private function queue(?Activity $op): JsonResponse
    {
        abort_if($op === null, 409, 'This node already has an operation running.');
        $this->operations->dispatch($op);

        return response()->json(['data' => ActivityLogData::fromModel($op)], 202);
    }
}
