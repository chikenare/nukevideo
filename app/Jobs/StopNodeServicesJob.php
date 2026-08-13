<?php

namespace App\Jobs;

use App\Models\Node;
use App\Services\SSHService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StopNodeServicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30];

    public function __construct(private readonly Node $node) {}

    public function handle(SSHService $ssh): void
    {
        $node = $this->node->load('sshKey');
        $containers = implode(' ', $node->deployedContainerNames());

        // Everything the deploy raised for this node — on a proxy that is Traefik and Vector too,
        // which served nothing once the proxy itself is down. Never `nukevideo_storage_{id}`, the
        // RustFS the `chunks` disk of EVERY node points at: this used to run
        // `--filter name=nukevideo_`, a substring match that caught it, and deactivating one worker
        // took the whole fleet's chunk store down with it. Since the containers run with
        // `--restart unless-stopped` a `docker stop` survives reboots, so it stayed down until
        // someone redeployed the node by hand.
        //
        // One call for all of them; docker reports the ones that were not there and stops the rest,
        // which is the normal case for a node deployed before it had these companions.
        $stopped = trim($ssh->run(
            ip: $node->ip_address,
            user: $node->user,
            privateKey: $node->sshKey->private_key,
            command: "docker stop {$containers} 2>/dev/null || true",
            timeout: 60,
        ));

        Log::info('Node services stopped', [
            'node_id' => $node->id,
            'stopped' => $stopped ?: '(not running)',
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Failed to stop node services', [
            'node_id' => $this->node->id,
            'error' => $e->getMessage(),
        ]);
    }
}
