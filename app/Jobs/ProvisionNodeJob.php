<?php

namespace App\Jobs;

use App\Models\Node;
use App\Services\NodeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProvisionNodeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(
        private int $nodeId,
    ) {}

    public function handle(NodeService $service): void
    {
        $node = Node::with('sshKey')->findOrFail($this->nodeId);

        $service->provisionNode($node);
    }

    public function failed(Throwable $e): void
    {
        $node = Node::find($this->nodeId);
        if ($node) {
            $node->update(['status' => 'provision_failed']);
        }
    }
}
