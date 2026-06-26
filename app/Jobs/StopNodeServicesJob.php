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

        $stopped = trim($ssh->run(
            ip: $node->ip_address,
            user: $node->user,
            privateKey: $node->sshKey->private_key,
            command: "docker ps -q --filter name=nukevideo_ | xargs -r docker stop",
            timeout: 60,
        ));

        Log::info('Node services stopped', [
            'node_id' => $node->id,
            'stopped' => $stopped ?: '(none running)',
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
