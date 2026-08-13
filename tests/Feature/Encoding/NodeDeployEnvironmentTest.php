<?php

use App\Models\Node;
use App\Services\NodeService;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function deployableNode(array $attributes = []): Node
{
    return Node::create([
        'ip_address' => '10.0.0.99',
        'user' => 'deploy',
        'name' => 'node-test',
        'type' => 'worker',
        'is_active' => true,
        'is_storage_server' => true,
        'storage_endpoint' => 'http://10.0.0.99:9000',
        ...$attributes,
    ]);
}

function fakeCdnProvider(string $provider): void
{
    CdnSettings::fake([
        'provider' => $provider,
        'providers' => [
            'self_hosted' => ['token_secret' => 'secret'],
            'bunny' => ['host' => 'cdn.example.com', 'token_key' => 'key'],
        ],
    ]);
}

function asLocalEnvironment(): void
{
    // `isLocal()` reads the container's `env` binding, which `config()` does not touch.
    app()->detectEnvironment(fn () => 'local');
}

// The image names are assertions about Docker Hub unless a test says otherwise, and the developer
// running the suite may well have a registry of their own in `.env`.
beforeEach(fn () => config(['nuke.registry' => null]));

describe('development and production deploys on one host', function () {
    beforeEach(fn () => fakeCdnProvider('self_hosted'));

    it('keeps every docker name a development deploy writes out of production space', function () {
        // The same machine is a production worker and the development host. Node ids come from
        // each environment's own database, so both want `nukevideo_worker_1`: unprefixed, deploying
        // the dev node replaces the running production container and repoints the chunk volume.
        asLocalEnvironment();
        $node = deployableNode();
        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->toContain("nukevideo_dev_worker_{$node->id}")
            ->and($script)->toContain("nukevideo_dev_storage_{$node->id}")
            ->and($script)->toContain('nukevideo_dev_chunks:/data')
            ->and($script)->not->toContain('nukevideo_worker_')
            ->and($script)->not->toContain('nukevideo_storage_')
            ->and($script)->not->toContain('nukevideo_chunks:');
    });

    it('gives each proxy its own Traefik router name', function () {
        // Traefik keys routers by name across every container it discovers on the host, and it
        // discovers all of them. A fixed `routers.proxy` meant a second proxy — or the development
        // host beside production — redefined the same router with a different rule and entrypoint;
        // Traefik drops the conflicting one and the edge stops resolving.
        $first = deployableNode(['type' => 'proxy', 'hostname' => 'a.example.com']);
        $second = deployableNode(['type' => 'proxy', 'hostname' => 'b.example.com']);

        $scripts = app(NodeService::class);

        expect($scripts->buildDeployScript($first))->toContain("routers.nukevideo-proxy-{$first->id}.rule")
            ->and($scripts->buildDeployScript($second))->toContain("routers.nukevideo-proxy-{$second->id}.rule")
            ->and($scripts->buildDeployScript($first))->not->toContain('routers.proxy.');
    });

    it('keeps a development proxy out of production\'s Traefik namespace', function () {
        asLocalEnvironment();
        $node = deployableNode(['type' => 'proxy', 'hostname' => 'dev.example.com']);

        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->toContain("routers.nukevideo_dev-proxy-{$node->id}.")
            ->and($script)->not->toContain("routers.nukevideo-proxy-{$node->id}.");
    });

    it('scopes the vector label to the environment', function () {
        // Vector reads the Docker socket, so it sees the other environment's containers too. A
        // shared `vector.enable=true` had production ingesting development's access logs and
        // billing them to production.
        $prod = app(NodeService::class)->buildDeployScript(deployableNode(['type' => 'proxy', 'hostname' => 'a.example.com']));

        asLocalEnvironment();
        $dev = app(NodeService::class)->buildDeployScript(deployableNode(['type' => 'proxy', 'hostname' => 'b.example.com']));

        expect($prod)->toContain('vector.enable=nukevideo')
            ->and($prod)->not->toContain('vector.enable=true')
            ->and($dev)->toContain('vector.enable=nukevideo_dev')
            ->and($dev)->toContain('VECTOR_SCOPE=nukevideo_dev');
    });

    it('still deploys production under the plain names', function () {
        $node = deployableNode();
        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->toContain("nukevideo_worker_{$node->id}")
            ->and($script)->toContain("nukevideo_storage_{$node->id}")
            ->and($script)->toContain('nukevideo_chunks:/data')
            ->and($script)->not->toContain('nukevideo_dev');
    });

    it('builds the development image instead of pulling one that does not exist', function () {
        // Built from the release target, so a development node runs the same shape as production —
        // and under its own tag, because `:dev` is compose's (target `api-dev`, no code in it) and
        // the next `compose up --build` would rebuild it from under the node.
        asLocalEnvironment();
        $script = app(NodeService::class)->buildDeployScript(deployableNode());

        expect($script)->toContain('docker build --target api-prod -t chikenare/nukevideo-api:node-dev "$SOURCE_DIR"')
            // Compose's own tag, the one with no code in it, must never be what a node runs.
            ->and($script)->not->toContain('chikenare/nukevideo-api:dev');
    });

    it('builds from the compose project it finds on the node, and says where from', function () {
        // Nothing to configure: the working copy is wherever the panel itself runs from, and compose
        // labels every container it creates with that directory. The path is printed so the image
        // can be pushed or copied to an external test node afterwards.
        asLocalEnvironment();
        $script = app(NodeService::class)->buildDeployScript(deployableNode());

        expect($script)->toContain('--filter label=com.docker.compose.service=nukevideo-api')
            ->and($script)->toContain('{{.Label "com.docker.compose.project.working_dir"}}')
            // Building from an empty context would produce an image with no project in it.
            ->and($script)->toContain('if [ -n "$SOURCE_DIR" ]; then')
            ->and($script)->toContain('from $SOURCE_DIR')
            ->and(strpos($script, 'SOURCE_DIR=$('))
            ->toBeLessThan(strpos($script, 'docker build --target'));
    });

    it('builds the proxy from its own release target', function () {
        asLocalEnvironment();
        $script = app(NodeService::class)->buildDeployScript(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com', 'is_storage_server' => false])
        );

        expect($script)->toContain('docker build --target proxy-prod -t chikenare/nukevideo-proxy:node-dev');
    });

    it('falls back to the published image on a node that has no working copy', function () {
        // An external test node runs what the last deploy from the development machine pushed;
        // same script, the node itself decides which half applies.
        asLocalEnvironment();
        config(['nuke.registry' => '10.0.0.240:5000']);
        $script = app(NodeService::class)->buildDeployScript(deployableNode());

        expect($script)->toContain('if [ -n "$SOURCE_DIR" ]; then')
            ->and($script)->toContain('docker build --target api-prod -t 10.0.0.240:5000/nukevideo-api:node-dev')
            ->and($script)->toContain('docker push 10.0.0.240:5000/nukevideo-api:node-dev')
            ->and($script)->toContain('pull_image 10.0.0.240:5000/nukevideo-api:node-dev');
    });

    it('never pushes a development build when the registry is docker hub', function () {
        // Unset means the namespace the releases are published under. A working copy has no
        // business going there.
        asLocalEnvironment();
        $script = app(NodeService::class)->buildDeployScript(deployableNode());

        expect($script)->toContain('chikenare/nukevideo-api:node-dev')
            ->and($script)->not->toContain('docker push');
    });

    it('leaves production on docker hub when no registry is configured', function () {
        $script = app(NodeService::class)->buildDeployScript(deployableNode());

        expect($script)->toContain('pull_image chikenare/nukevideo-api:'.config('app.version'));
    });

    it('pulls production from the configured registry', function () {
        config(['nuke.registry' => 'registry.example.com:5000/']);
        $script = app(NodeService::class)->buildDeployScript(deployableNode());

        // Trailing slash trimmed — the name would otherwise carry a double separator.
        expect($script)->toContain('pull_image registry.example.com:5000/nukevideo-api:'.config('app.version'))
            ->and($script)->not->toContain('chikenare/');
    });

    it('leaves production pulling the released image, never building on the node', function () {
        $script = app(NodeService::class)->buildDeployScript(deployableNode());
        $image = 'chikenare/nukevideo-api:'.config('app.version');

        expect($script)->toContain("pull_image {$image}")
            ->and($script)->not->toContain('docker build')
            ->and($script)->not->toContain('SOURCE_DIR');
    });
});

describe('vector placement', function () {
    it('ships edge logs from a self-hosted proxy', function () {
        fakeCdnProvider('self_hosted');
        $script = app(NodeService::class)->buildDeployScript(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com', 'is_storage_server' => false])
        );

        expect($script)->toContain('docker run -d --name nukevideo_vector')
            ->and($script)->toContain('/etc/vector/vector.yaml');
    });

    it('does not run vector on a worker node, and clears one an older deploy left', function () {
        // Only the vod nginx writes the `ip=/bytes=/video=` lines the transform keeps; a worker's
        // logs are all dropped, so vector there reads every line of Horizon to produce nothing.
        fakeCdnProvider('self_hosted');
        $script = app(NodeService::class)->buildDeployScript(deployableNode());

        expect($script)->not->toContain('docker run -d --name nukevideo_dev_vector')
            ->and($script)->not->toContain('docker run -d --name nukevideo_vector')
            ->and($script)->toContain('docker rm -f nukevideo_vector');
    });

    it('does not run vector behind bunny, whose logs come from its own API', function () {
        // Viewer traffic never touches our edge on Bunny; `bunny:ingest-logs` polls the Logging
        // API into the same bandwidth pipeline instead.
        fakeCdnProvider('bunny');
        $script = app(NodeService::class)->buildDeployScript(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com', 'is_storage_server' => false])
        );

        expect($script)->not->toContain('docker run -d --name nukevideo_vector')
            ->and($script)->toContain('docker rm -f nukevideo_vector');
    });

    it('hands vector only the two variables its config reads', function () {
        fakeCdnProvider('self_hosted');
        $script = app(NodeService::class)->buildDeployScript(
            deployableNode(['type' => 'proxy', 'hostname' => 'edge.example.com', 'is_storage_server' => false])
        );

        preg_match('/^docker run -d --name nukevideo_vector .*$/m', $script, $matches);

        expect($matches)->not->toBeEmpty()
            ->and($matches[0])->toContain('INTERNAL_API_URL=')
            ->and($matches[0])->toContain('INTERNAL_API_SECRET=')
            // The node environment carries database, S3 and webhook credentials with it.
            ->and($matches[0])->not->toContain('APP_KEY=')
            ->and($matches[0])->not->toContain('AWS_SECRET_ACCESS_KEY=');
    });
});
