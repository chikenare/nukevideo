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
        // `-t`, not `--time`/`--timeout`: the long forms flipped between docker CLI generations.
        expect($script)->toContain("DRAIN={$grace}")
            ->and($script)->toContain('docker stop -t "$DRAIN"')
            ->and(strpos($script, 'docker stop -t'))
            ->toBeLessThan(strpos($script, 'docker rm -f nukevideo_worker_'));
    });

    it('lets a run opt out of the drain', function () {
        $script = app(NodeService::class)->buildDeployScript(workerNode());

        // `curl ... | bash -s -- --no-drain` (or --drain=N); the panel sends ?drain=0 the same way.
        expect($script)->toContain('--no-drain) DRAIN=0')
            ->and($script)->toContain('--drain=*) DRAIN=')
            ->and($script)->toContain('[ "$DRAIN" -gt 0 ]');
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
