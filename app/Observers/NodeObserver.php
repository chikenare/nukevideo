<?php

namespace App\Observers;

use App\Models\Node;
use Illuminate\Support\Str;

class NodeObserver
{
    public function creating(Node $node): void
    {
        $node->uuid = Str::uuid()->toString();
    }
}
