<?php

use App\Enums\NodeAction;
use App\Jobs\RunNodeOperationJob;
use App\Models\Node;
use App\Services\NodeOperationService;
use App\Services\NodeService;
use App\Services\SSHService;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function opsNode(array $attributes = []): Node
{
    AppSettings::fake(['ssh_private_key' => 'PRIVATE', 'ssh_public_key' => 'ssh-ed25519 AAAA nukevideo', 'ssh_fingerprint' => 'ff']);

    return Node::create([
        'ip_address' => '10.0.0.99',
        'user' => 'deploy',
        'name' => 'node-'.uniqid(),
        'type' => 'worker',
        'is_active' => true,
        ...$attributes,
    ]);
}

beforeEach(function () {
    // CI has no Redis; the line buffer is the only thing here that talks to it.
    Redis::shouldReceive('rpush', 'expire')->andReturn(1)->byDefault();
});

it('refuses a second operation on a busy node', function () {
    $node = opsNode();
    $ops = app(NodeOperationService::class);

    expect($ops->begin($node, NodeAction::Deploy))->toBeInstanceOf(Activity::class)
        ->and($ops->begin($node, NodeAction::Stop))->toBeNull();
});

it('records who asked for what', function () {
    $node = opsNode();
    $op = app(NodeOperationService::class)->begin($node, NodeAction::Stop, force: true);

    expect($op->log_name)->toBe('node')
        ->and($op->subject_id)->toBe($node->id)
        ->and($op->properties['action'])->toBe('stop')
        ->and($op->properties['force'])->toBeTrue()
        ->and($op->properties['status'])->toBe('queued');
});

it('marks the node inactive before draining it', function () {
    // The dispatcher and the capacity check read the flag; a node draining for eleven minutes
    // must not be handed new videos meanwhile.
    $node = opsNode();
    $service = Mockery::mock(NodeService::class);
    $service->shouldReceive('stopServices')->once()->andReturnUsing(function () use ($node) {
        expect($node->fresh()->is_active)->toBeFalse();
    });
    app()->instance(NodeService::class, $service);

    $ops = app(NodeOperationService::class);
    $ops->run($ops->begin($node, NodeAction::Stop)->id);
});

it('activates the node once a deploy or a start succeeds', function (NodeAction $action, string $method) {
    $node = opsNode(['is_active' => false]);
    $service = Mockery::mock(NodeService::class);
    $service->shouldReceive($method)->once();
    app()->instance(NodeService::class, $service);

    $ops = app(NodeOperationService::class);
    $op = $ops->begin($node, $action);
    $ops->run($op->id);

    expect($node->fresh()->is_active)->toBeTrue()
        ->and($op->fresh()->properties['status'])->toBe('succeeded');
})->with([
    [NodeAction::Deploy, 'runFullDeploy'],
    [NodeAction::Start, 'startServices'],
]);

it('marks a failed operation and frees the node', function () {
    $node = opsNode();
    $service = Mockery::mock(NodeService::class);
    $service->shouldReceive('runFullDeploy')->andThrow(new RuntimeException('pull failed'));
    app()->instance(NodeService::class, $service);

    $ops = app(NodeOperationService::class);
    $op = $ops->begin($node, NodeAction::Deploy);

    expect(fn () => $ops->run($op->id))->toThrow(RuntimeException::class);
    expect($op->fresh()->properties['status'])->toBe('failed')
        ->and($op->fresh()->properties['error'])->toBe('pull failed')
        ->and($ops->begin($node, NodeAction::Deploy))->not->toBeNull();
});

it('frees the node when the job dies before finishing', function () {
    // A timeout or a killed worker never reaches the end of run(); failed() is the only hook left.
    $node = opsNode();
    $ops = app(NodeOperationService::class);
    $op = $ops->begin($node, NodeAction::Deploy);

    (new RunNodeOperationJob($op->id))->failed(new RuntimeException('timed out'));

    expect($op->fresh()->properties['status'])->toBe('failed')
        ->and($ops->begin($node, NodeAction::Deploy))->not->toBeNull();
});

it('runs workers in parallel and proxies one at a time', function () {
    Bus::fake();
    $ops = app(NodeOperationService::class);
    $w1 = $ops->begin(opsNode(), NodeAction::Deploy);
    $w2 = $ops->begin(opsNode(), NodeAction::Deploy);
    $p1 = $ops->begin(opsNode(['type' => 'proxy']), NodeAction::Deploy);
    $p2 = $ops->begin(opsNode(['type' => 'proxy']), NodeAction::Deploy);

    $ops->dispatch($w1, $w2, $p1, $p2);

    // Each worker on its own; the chain's head is the third dispatch, and the only proxy one.
    Bus::assertDispatchedTimes(RunNodeOperationJob::class, 3);
    Bus::assertNotDispatched(RunNodeOperationJob::class, fn ($job) => $job->operationId === $p2->id);
    Bus::assertChained([
        new RunNodeOperationJob($p1->id),
        new RunNodeOperationJob($p2->id),
    ]);
});

it('cancels the proxies a failed chain never reached', function () {
    // Otherwise they sit `queued` forever and their nodes stay locked until the TTL.
    $ops = app(NodeOperationService::class);
    $p1 = $ops->begin($n1 = opsNode(['type' => 'proxy']), NodeAction::Deploy);
    $p2 = $ops->begin($n2 = opsNode(['type' => 'proxy']), NodeAction::Deploy);

    $ops->cancelQueued([$p1->id, $p2->id]);

    expect($p2->fresh()->properties['status'])->toBe('cancelled')
        ->and($ops->begin($n2, NodeAction::Deploy))->not->toBeNull();
});

it('stops only the node\'s own containers, gracefully or not', function (bool $force, string $verb) {
    $node = opsNode(['type' => 'proxy', 'hostname' => 'edge.example.com']);
    $command = null;
    $ssh = Mockery::mock(SSHService::class);
    $ssh->shouldReceive('run')->andReturnUsing(function (...$args) use (&$command) {
        $command = $args['command'] ?? $args[3];

        return '';
    });
    app()->instance(SSHService::class, $ssh);

    app(NodeService::class)->stopServices($node, $force, fn () => null);

    expect($command)->toStartWith("docker {$verb} nukevideo_proxy_{$node->id}")
        ->and($command)->not->toContain('traefik')
        ->and($command)->not->toContain('nukevideo_storage_');
})->with([[false, 'stop'], [true, 'kill']]);

it('keeps one clean line per line of output', function () {
    // SSH hands over whatever the remote wrote, CRLF included; a stray \r shows up in the panel.
    $node = opsNode();
    $service = Mockery::mock(NodeService::class);
    $service->shouldReceive('startServices')->andReturnUsing(fn ($n, $out) => $out("pulling\r\n\r\ndone\r"));
    app()->instance(NodeService::class, $service);

    $ops = app(NodeOperationService::class);
    $op = $ops->begin($node, NodeAction::Start);
    Redis::shouldReceive('rpush')->once()->with("node-ops:{$op->id}", 'pulling', 'done')->andReturn(2);

    $ops->run($op->id);
});

it('holds the node for as long as the operation runs, however long it queued', function () {
    // The lock is taken when the operation is queued. Behind a long fleet deploy it can expire
    // before the job starts; running without it would let a second operation on the same host.
    $node = opsNode();
    $ops = app(NodeOperationService::class);
    $op = $ops->begin($node, NodeAction::Deploy);
    $this->travel(31)->minutes();

    $service = Mockery::mock(NodeService::class);
    $service->shouldReceive('runFullDeploy')->once()->andReturnUsing(function () use ($node) {
        expect(app(NodeOperationService::class)->begin($node, NodeAction::Stop))->toBeNull();
    });
    app()->instance(NodeService::class, $service);

    app(NodeOperationService::class)->run($op->id);
});

it('never runs once another operation took the node over', function () {
    $node = opsNode();
    $ops = app(NodeOperationService::class);
    $op = $ops->begin($node, NodeAction::Deploy);
    $this->travel(31)->minutes();
    $ops->begin($node, NodeAction::Stop);

    $service = Mockery::mock(NodeService::class);
    $service->shouldNotReceive('runFullDeploy');
    app()->instance(NodeService::class, $service);

    expect(fn () => app(NodeOperationService::class)->run($op->id))->toThrow(RuntimeException::class);
    expect($op->fresh()->properties['status'])->toBe('failed');
});

it('keeps the tail of a long failure, not the whole deploy log again', function () {
    // SSHService reports stdout when stderr is empty: a failed deploy's message is its whole log,
    // which is already in the lines and would be repeated there and in every list poll.
    $node = opsNode();
    $log = implode("\n", array_map(fn ($i) => "step {$i}", range(1, 200)))."\nImage x not found";
    $service = Mockery::mock(NodeService::class);
    $service->shouldReceive('runFullDeploy')->andThrow(new RuntimeException($log));
    app()->instance(NodeService::class, $service);

    $ops = app(NodeOperationService::class);
    $op = $ops->begin($node, NodeAction::Deploy);
    Redis::shouldReceive('rpush')->once()->with("node-ops:{$op->id}", 'ERROR: step 200 | Image x not found')->andReturn(1);

    expect(fn () => $ops->run($op->id))->toThrow(RuntimeException::class);
    expect($op->fresh()->properties['error'])->toBe('step 200 | Image x not found');
});

it('is never redelivered while it still runs', function () {
    // It runs on the API host, where the redis connection redelivers after 90s — right for the
    // short jobs there, fatal for a deploy that would then run twice on one host. So it has a
    // connection of its own, whose retry_after outlasts it.
    $job = new RunNodeOperationJob(1);

    expect($job->connection)->toBe('node-ops')
        ->and($job->timeout)->toBeLessThan(config('queue.connections.node-ops.retry_after'));
});
