<?php

use App\Http\Controllers\Api\NodeController;
use App\Jobs\StartNodeServicesJob;
use App\Jobs\StopNodeServicesJob;
use App\Models\Node;
use App\Models\SshKey;
use App\Services\DockerService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lifecycleNode(array $attributes = []): Node
{
    $key = SshKey::create([
        'name' => 'test',
        'public_key' => 'ssh-ed25519 AAAA',
        'private_key' => 'PRIVATE',
        'fingerprint' => 'SHA256:test',
    ]);

    return Node::create([
        'ip_address' => '10.0.0.99',
        'user' => 'deploy',
        'name' => 'node-test',
        'type' => 'worker',
        'is_active' => true,
        'is_storage_server' => true,
        'storage_endpoint' => 'http://10.0.0.99:9000',
        'ssh_key_id' => $key->id,
        ...$attributes,
    ]);
}

/** Runs the job against a fake SSH and hands back the command it would have run. */
function sshCommandOf(object $job): string
{
    $captured = '';

    $ssh = Mockery::mock(SSHService::class);
    $ssh->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args[3];   // ip, user, privateKey, command, ...

            return '';
        });

    app()->instance(SSHService::class, $ssh);
    $job->handle($ssh);

    return $captured;
}

describe('deactivating a node', function () {
    it('stops everything its deploy raised on a proxy', function () {
        // Traefik and Vector exist only to serve that proxy; leaving them up after the proxy is
        // down leaves an edge that terminates TLS for nothing.
        $node = lifecycleNode(['type' => 'proxy', 'hostname' => 'edge.example.com', 'is_storage_server' => false]);

        $command = sshCommandOf(new StopNodeServicesJob($node));

        expect($command)->toContain("nukevideo_proxy_{$node->id}")
            ->and($command)->toContain('nukevideo_traefik')
            ->and($command)->toContain('nukevideo_vector')
            ->and($command)->toStartWith('docker stop ');
    });

    it('never stops the chunk store the whole fleet reads through', function () {
        // Every node's `chunks` disk points at this one container. Taking a single worker out of
        // rotation must not take the fleet's storage with it — and `--restart unless-stopped`
        // means a stop here survives reboots, so it would stay down until a manual redeploy.
        $node = lifecycleNode();

        $command = sshCommandOf(new StopNodeServicesJob($node));

        expect($command)->toContain("nukevideo_worker_{$node->id}")
            ->and($command)->not->toContain('nukevideo_storage_');
    });

    it('starts back exactly what it stopped', function () {
        $node = lifecycleNode(['type' => 'proxy', 'hostname' => 'edge.example.com', 'is_storage_server' => false]);

        $stop = sshCommandOf(new StopNodeServicesJob($node));
        $start = sshCommandOf(new StartNodeServicesJob($node));

        // Asymmetry here means a reactivated proxy comes back with no Traefik in front of it.
        expect(str_replace('docker start ', '', explode(' 2>', $start)[0]))
            ->toBe(str_replace('docker stop ', '', explode(' 2>', $stop)[0]));
    });
});

describe('deleting a node', function () {
    it('removes its own containers and its chunk store, but nothing else', function () {
        $node = lifecycleNode();
        $other = lifecycleNode(['name' => 'other']);

        $removed = [];
        $docker = Mockery::mock(DockerService::class);
        $docker->shouldReceive('listContainers')->andReturn([
            ['Names' => "/nukevideo_worker_{$node->id}"],
            ['Names' => "/nukevideo_storage_{$node->id}"],
            ['Names' => "/nukevideo_worker_{$other->id}"],
            ['Names' => '/nukevideo_dev_worker_1'],
            ['Names' => '/unrelated_container'],
        ]);
        $docker->shouldReceive('removeContainer')->andReturnUsing(function ($n, $name) use (&$removed) {
            $removed[] = $name;
        });
        app()->instance(DockerService::class, $docker);

        app(NodeController::class)->destroy((string) $node->id);

        expect($removed)->toBe(["nukevideo_worker_{$node->id}", "nukevideo_storage_{$node->id}"])
            ->and(Node::find($node->id))->toBeNull()
            ->and(Node::find($other->id))->not->toBeNull();
    });

    it('does not take a container whose name it is merely a prefix of', function () {
        // `nukevideo_worker_1` is a prefix of `nukevideo_worker_11`. With a prefix match, deleting
        // node 1 killed node 11's worker whenever the two shared a host.
        $node = lifecycleNode();
        $name = $node->serviceContainerName();

        $removed = [];
        $docker = Mockery::mock(DockerService::class);
        $docker->shouldReceive('listContainers')->andReturn([
            ['Names' => "/{$name}1"],
            ['Names' => "/{$name}_extra"],
        ]);
        $docker->shouldReceive('removeContainer')->andReturnUsing(function ($n, $c) use (&$removed) {
            $removed[] = $c;
        });
        app()->instance(DockerService::class, $docker);

        app(NodeController::class)->destroy((string) $node->id);

        expect($removed)->toBe([]);
    });
});
