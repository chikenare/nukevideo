<?php

namespace App\Observers;

use App\Jobs\StartNodeServicesJob;
use App\Jobs\StopNodeServicesJob;
use App\Models\Node;
use Illuminate\Support\Str;

class NodeObserver
{
    public function creating(Node $node): void
    {
        $node->uuid = Str::uuid()->toString();
    }

    public function updated(Node $node): void
    {
        if (! $node->wasChanged('is_active')) {
            return;
        }

        // Symmetric on purpose: the flag is what the dispatcher and the capacity check read, so a
        // node whose containers do not match it is a node that accepts work it cannot do.
        $node->is_active
            ? StartNodeServicesJob::dispatch($node)
            : StopNodeServicesJob::dispatch($node);
    }
}
