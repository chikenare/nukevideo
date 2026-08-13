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

function asEnvironment(string $env): void
{
    // `environment()` reads the container's `env` binding, which `config()` does not touch.
    app()->detectEnvironment(fn () => $env);
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

    it('kills outright in development and staging instead of waiting', function (string $env) {
        // These redeploy every few minutes and their in-flight encodes are disposable, so holding
        // each deploy for a full chunk pass buys nothing. `\nDRAIN=0\n` and not just `DRAIN=0`:
        // the `--no-drain` case arm contains that string in every environment.
        asEnvironment($env);

        expect(NodeService::drainGrace())->toBe(0)
            ->and(app(NodeService::class)->buildDeployScript(workerNode()))
            ->toContain("\nDRAIN=0\n");
    })->with(['local', 'staging']);

    it('drains on production and on any environment it was not told about', function (string $env) {
        // Allowlisted rather than "not production": an unset or mistyped APP_ENV has to land on
        // the side that waits, since the cost of guessing wrong is a job stranded for ~31 minutes.
        asEnvironment($env);
        $grace = NodeService::WORKER_STOP_GRACE;

        expect(NodeService::drainGrace())->toBe($grace)
            ->and(app(NodeService::class)->buildDeployScript(workerNode()))
            ->toContain("\nDRAIN={$grace}\n");
    })->with(['production', 'testing', 'whatever-this-is']);
});
