<?php

namespace App\Observers;

use App\Jobs\StopNodeServicesJob;
use App\Models\Node;

class NodeObserver
{
    public function updated(Node $node): void
    {
        if ($node->wasChanged('is_active') && ! $node->is_active) {
            StopNodeServicesJob::dispatch($node);
        }
    }
}
