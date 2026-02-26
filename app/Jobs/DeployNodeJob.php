<?php

namespace App\Jobs;

use App\Models\Node;
use App\Services\NodeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeployNodeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 360;

    public function __construct(
        private int $id,
    ) {
    }

    public function handle(NodeService $service): void
    {
        $node = Node::with('sshKey')->find($this->id);
        if ($node) {
            $service->deploy($node);
        }
    }
}
