<?php

use App\Models\Node;
use App\Services\NodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function workerNode(): Node
{
    return Node::create([
        'ip_address' => '10.0.0.99',
        'user' => 'deploy',
        'name' => 'worker-test',
        'type' => 'worker',
        'accel' => 'intel',
        'is_active' => true,
        'is_storage_server' => true,
        'storage_endpoint' => 'http://10.0.0.99:9000',
    ]);
}

describe('worker deploy drain', function () {
    it('drains the old container before replacing it, and only then removes it', function () {
        $script = app(NodeService::class)->buildDeployScript(workerNode());
        $grace = NodeService::WORKER_STOP_GRACE;

        // SIGKILLing Horizon mid-encode strands its jobs as reserved in Redis for ~31 minutes
        // while the new container sits idle; `docker stop --time` lets it finish them first.
        expect($script)->toContain("docker stop --time={$grace}")
            ->and(strpos($script, "docker stop --time={$grace}"))
            ->toBeLessThan(strpos($script, 'docker rm -f nukevideo_worker_'));
    });

    it('bakes the same grace into the new container for every future stop', function () {
        $script = app(NodeService::class)->buildDeployScript(workerNode());

        // A host reboot or a hand-typed `docker stop` must drain too, not kill at Docker's 10s.
        expect($script)->toContain('--stop-timeout '.NodeService::WORKER_STOP_GRACE);
    });

    it('keeps the drain long enough for one full chunk pass', function () {
        // The grace exists to cover the longest job the gpu/cpu transcode supervisors run.
        expect(NodeService::WORKER_STOP_GRACE)->toBeGreaterThan(NodeService::WORKER_TIMEOUT);
    });
});
