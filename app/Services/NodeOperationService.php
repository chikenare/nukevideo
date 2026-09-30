<?php

namespace App\Services;

use App\Enums\NodeAction;
use App\Enums\NodeType;
use App\Jobs\RunNodeOperationJob;
use App\Models\Node;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Spatie\Activitylog\Models\Activity;

/**
 * Deploy, start and stop, run in the background. The record of an operation (who, what, how it
 * ended) is an activitylog entry; its output is a Redis list the panel polls with a cursor, so
 * closing the tab loses nothing and anyone can reopen it.
 */
class NodeOperationService
{
    // Longer than any operation can run (RunNodeOperationJob::$timeout), counted from when it
    // starts ({@see holdLock()}), so a lock outlives its job only if the job's failed() never ran
    // either — and then it expires on its own.
    private const LOCK_TTL = 1800;

    private const LINES_TTL = 7 * 86400;

    public function __construct(private NodeService $nodes) {}

    /** Null when the node already has an operation queued or running: one SSH session per host. */
    public function begin(Node $node, NodeAction $action, bool $force = false, ?array $disks = null, ?string $batch = null): ?Activity
    {
        $lock = Cache::lock(self::lockName($node->id), self::LOCK_TTL);

        if (! $lock->get()) {
            return null;
        }

        return activity('node')
            ->performedOn($node)
            ->causedBy(auth()->user())
            ->event("node_{$action->value}")
            ->withProperties([
                'action' => $action->value,
                'force' => $force,
                'disks' => $disks,
                'batch' => $batch,
                'status' => 'queued',
                'lock' => $lock->owner(),
            ])
            ->log(ucfirst($action->value).' '.$node->name);
    }

    /**
     * Workers in parallel: a fleet with no encoding capacity for a few minutes only makes the
     * queues wait. Proxies one at a time, chained: the chain stops at the first failure, so a
     * broken image takes down one edge, not the delivery fleet.
     */
    public function dispatch(Activity ...$operations): void
    {
        $proxies = [];
        foreach ($operations as $op) {
            if ($op->subject->type === NodeType::PROXY) {
                $proxies[] = $op->id;
            } else {
                RunNodeOperationJob::dispatch($op->id);
            }
        }

        if ($proxies) {
            Bus::chain(array_map(fn ($id) => new RunNodeOperationJob($id), $proxies))
                ->onConnection(RunNodeOperationJob::CONNECTION)
                ->onQueue(RunNodeOperationJob::QUEUE)
                ->catch(fn () => app(self::class)->cancelQueued($proxies))
                ->dispatch();
        }
    }

    public function run(int $operationId): void
    {
        $op = Activity::findOrFail($operationId);
        $node = $op->subject;
        $action = NodeAction::from($op->properties['action']);
        $force = (bool) $op->properties['force'];
        $out = fn (string $chunk) => $this->append($op->id, $chunk);

        // The lock was taken when the operation was queued, and behind a long fleet deploy it can
        // expire before this runs. Extend it, or take it back if nobody else has; if someone has,
        // another operation owns the host now and this one must not touch it.
        if (! $this->holdLock($op)) {
            $this->fail($op->id, 'Another operation took this node over while this one was queued.');

            throw new \RuntimeException("Operation {$op->id} lost its node lock.");
        }

        $this->setStatus($op, 'running');

        try {
            match ($action) {
                NodeAction::Deploy => $this->nodes->runFullDeploy($node, $out, drain: ! $force, disks: $op->properties['disks']),
                NodeAction::Start => $this->nodes->startServices($node, $out),
                NodeAction::Stop => $this->stop($node, $force, $out),
            };
        } catch (\Throwable $e) {
            $this->fail($op->id, $e->getMessage());

            throw $e;
        }

        if ($action !== NodeAction::Stop) {
            $node->update(['is_active' => true]);
        }

        $this->finish($op, 'succeeded');
    }

    public function fail(int $operationId, string $message): void
    {
        $op = Activity::find($operationId);
        if (! $op || in_array($op->properties['status'], ['succeeded', 'failed', 'cancelled'], true)) {
            return;
        }

        // The last two lines, on one: SSHService reports stdout when stderr is empty, so a failed
        // deploy's message can be its whole log — already in the lines, and the error is sent
        // with every row of every list poll.
        $lines = array_filter(array_map('trim', explode("\n", $message)), fn ($l) => $l !== '');
        $message = mb_substr(implode(' | ', array_slice($lines, -2)), 0, 500);

        $this->append($op->id, "ERROR: {$message}");
        $this->finish($op, 'failed', $message);
    }

    /** The proxies a failed chain never reached. */
    public function cancelQueued(array $operationIds): void
    {
        foreach (Activity::whereKey($operationIds)->get() as $op) {
            if ($op->properties['status'] === 'queued') {
                $this->finish($op, 'cancelled');
            }
        }
    }

    /** Whether the node has an operation queued or running (checked, not held). */
    public function busy(Node $node): bool
    {
        $lock = Cache::lock(self::lockName($node->id), 10);
        if (! $lock->get()) {
            return true;
        }
        $lock->release();

        return false;
    }

    public function lines(int $operationId, int $after): array
    {
        return Redis::lrange(self::linesKey($operationId), $after, -1) ?: [];
    }

    // Before the drain, not after: the dispatcher and the capacity check read the flag, and a
    // node draining for eleven minutes must not be handed new videos meanwhile.
    private function stop(Node $node, bool $force, \Closure $out): void
    {
        $node->update(['is_active' => false]);
        $this->nodes->stopServices($node, $force, $out);
    }

    private function holdLock(Activity $op): bool
    {
        $name = self::lockName($op->subject_id);
        $owner = $op->properties['lock'];

        return Cache::restoreLock($name, $owner)->refresh(self::LOCK_TTL)
            || Cache::lock($name, self::LOCK_TTL, $owner)->get();
    }

    private function append(int $operationId, string $chunk): void
    {
        $lines = array_values(array_filter(
            array_map(fn ($l) => rtrim($l, "\r"), explode("\n", $chunk)),
            fn ($l) => trim($l) !== '',
        ));
        if ($lines) {
            Redis::rpush(self::linesKey($operationId), ...$lines);
            Redis::expire(self::linesKey($operationId), self::LINES_TTL);
        }
    }

    private function finish(Activity $op, string $status, ?string $error = null): void
    {
        $this->setStatus($op, $status, $error);
        Cache::restoreLock(self::lockName($op->subject_id), $op->properties['lock'])->release();
    }

    private function setStatus(Activity $op, string $status, ?string $error = null): void
    {
        $op->properties = $op->properties->merge(array_filter(['status' => $status, 'error' => $error]));
        $op->save();
    }

    private static function lockName(int $nodeId): string
    {
        return "node-ops:lock:{$nodeId}";
    }

    private static function linesKey(int $operationId): string
    {
        return "node-ops:{$operationId}";
    }
}
