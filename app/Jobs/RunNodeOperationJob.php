<?php

namespace App\Jobs;

use App\Services\NodeOperationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RunNodeOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CONNECTION = 'node-ops';

    public const QUEUE = 'node-ops';

    // Once. A failed deploy is for the operator to read and rerun, not for the queue to repeat
    // against a host that just refused it — and a retried stop would log a second drain.
    public $tries = 1;

    // A draining deploy: WORKER_STOP_GRACE (660s) plus pull and startup (the SSH cap is +600).
    // Must stay under the `node-ops` connection's retry_after (config/queue.php).
    public $timeout = 1500;

    public function __construct(public int $operationId)
    {
        $this->onConnection(self::CONNECTION)->onQueue(self::QUEUE);
    }

    public function handle(NodeOperationService $operations): void
    {
        $operations->run($this->operationId);
    }

    // Also reached when the job never finished handle() (timeout, killed worker): the only place
    // left to free the node.
    public function failed(\Throwable $e): void
    {
        app(NodeOperationService::class)->fail($this->operationId, $e->getMessage());
    }
}
