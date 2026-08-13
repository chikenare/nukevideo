<?php

namespace App\Jobs;

use App\Console\Commands\DispatchPendingVideosCommand;
use App\Models\Node;
use App\Models\Template;
use App\Services\SSHService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The other half of {@see StopNodeServicesJob}. Deactivating a node stops its service container,
 * and `--restart unless-stopped` makes that stick across reboots — so without this, re-activating
 * only flipped a flag: the node counted as capacity again ({@see DispatchPendingVideosCommand},
 * {@see Template::missingCapacity()}) with nothing draining its queues, and every video
 * handed to it sat until the reaper failed it.
 */
class StartNodeServicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30];

    public function __construct(private readonly Node $node) {}

    public function handle(SSHService $ssh): void
    {
        $node = $this->node->load('sshKey');
        $containers = implode(' ', $node->deployedContainerNames());

        // The same set {@see StopNodeServicesJob} stops, or reactivating a proxy would put it back
        // in rotation with no Traefik in front of it. Only starts containers that already exist: a
        // node that was never deployed has nothing to start, and saying so is more useful than a
        // deploy that half-happens behind a toggle.
        $started = trim($ssh->run(
            ip: $node->ip_address,
            user: $node->user,
            privateKey: $node->sshKey->private_key,
            command: "docker start {$containers} 2>&1 || true",
            timeout: 60,
        ));

        Log::info('Node services started', [
            'node_id' => $node->id,
            'started' => $started ?: '(nothing to start — deploy the node first)',
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Failed to start node services', [
            'node_id' => $this->node->id,
            'error' => $e->getMessage(),
        ]);

        // The flag is what the dispatcher and the capacity check believe, so it must not outlive
        // the attempt to make it true — an unreachable host is exactly the case this job exists
        // for, and leaving `is_active` set would hand work to a node with nothing running on it.
        // A query-builder update does not fire the observer, so this cannot loop.
        Node::whereKey($this->node->id)->update(['is_active' => false]);
    }
}
