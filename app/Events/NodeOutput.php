<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class NodeOutput implements ShouldBroadcastNow
{
    public function __construct(
        public int $nodeId,
        public string $output,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel("node.{$this->nodeId}");
    }
}
