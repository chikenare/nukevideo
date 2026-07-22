<?php

namespace App\Observers;

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
        if ($node->wasChanged('is_active') && ! $node->is_active) {
            StopNodeServicesJob::dispatch($node);
        }
    }
}
