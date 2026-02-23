<?php

namespace App\Console\Commands;

use App\Models\Node;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PruneInactiveNodes extends Command
{
    protected $signature = 'nodes:prune {--minutes=30 : Minutes of inactivity before pruning}';

    protected $description = 'Remove nodes that have been inactive for a specified amount of time';

    public function handle()
    {
        $minutes = (int) $this->option('minutes');
        $threshold = Carbon::now()->subMinutes($minutes);

        $inactiveNodes = Node::where('updated_at', '<', $threshold)->get();

        if ($inactiveNodes->isEmpty()) {
            $this->info('No inactive nodes found.');
            return;
        }

        $this->table(
            ['ID', 'Name', 'Status', 'Location', 'Last Seen'],
            $inactiveNodes->map(fn (Node $node) => [
                $node->id,
                $node->name,
                $node->status,
                $node->location,
                $node->updated_at->diffForHumans(),
            ])
        );

        $count = $inactiveNodes->count();

        Node::whereIn('id', $inactiveNodes->pluck('id'))->delete();

        $this->info("Pruned {$count} inactive node(s).");
    }
}
