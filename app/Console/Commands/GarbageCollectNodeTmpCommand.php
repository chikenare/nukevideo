<?php

namespace App\Console\Commands;

use App\Jobs\PruneNodeTmpJob;
use App\Models\Node;
use Illuminate\Console\Command;

class GarbageCollectNodeTmpCommand extends Command
{
    protected $signature = 'videos:gc-tmp';

    protected $description = 'Reclaim orphaned local tmp on worker nodes left by jobs whose worker/node crashed';

    public function handle(): void
    {
        // One job per node, on the node's own queue, so each runs locally and
        // only sweeps its own tmp volume. A dead node simply drains the job when
        // it comes back (containers restart unless-stopped).
        Node::active()->worker()->get()->each(function (Node $node) {
            PruneNodeTmpJob::dispatch($node->id)->onQueue($node->resolveQueue());
        });
    }
}
