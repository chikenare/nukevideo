<?php

use App\Models\Node;
use App\Models\SshKey;
use App\Models\User;
use App\Services\NodeService;
use App\Services\ProxyCacheService;
use App\Services\SSHService;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function cacheNode(array $attributes = []): Node
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
        'name' => 'edge-test',
        'type' => 'proxy',
        'hostname' => 'edge.example.com',
        'is_active' => true,
        'ssh_key_id' => $key->id,
        ...$attributes,
    ]);
}

function inProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

function inDevelopment(): void
{
    app()->detectEnvironment(fn () => 'local');
}

beforeEach(function () {
    config(['nuke.registry' => null]);
    CdnSettings::fake([
        'provider' => 'self_hosted',
        'providers' => [
            'self_hosted' => ['token_secret' => 'secret'],
            'bunny' => ['host' => 'cdn.example.com', 'token_key' => 'key'],
        ],
    ]);
});

describe('a production proxy deploy', function () {
    beforeEach(fn () => inProduction());

    it('provisions the spare disks into a pool and mounts it into the edge', function () {
        $node = cacheNode();
        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->toContain('cache_disk_inventory()')
            ->and($script)->toContain('provision_cache_pool')
            ->and($script)->toContain("CACHE_DIRECTORY='".ProxyCacheService::MOUNT."/nukevideo_proxy_{$node->id}'")
            ->and($script)->toContain('-v "$CACHE_MOUNT:'.ProxyCacheService::CONTAINER_PATH.'"');
    });

    it('keeps the shell and the application agreeing on the pool\'s names', function () {
        // The constants live twice: in cache-disks.sh, which does the work, and in PHP, which
        // validates the pool and builds the directory. A drift between them is a pool the panel
        // cannot find.
        $shell = NodeService::deployScript('cache-disks');

        expect($shell)->toContain('CACHE_POOL_MOUNT='.ProxyCacheService::MOUNT)
            ->and($shell)->toContain('CACHE_POOL_LABEL='.ProxyCacheService::FS_LABEL)
            ->and($shell)->toContain('CACHE_POOL_MD='.ProxyCacheService::MD_DEVICE);
    });

    it('keeps an existing pool rather than rebuilding it', function () {
        // A redeploy is how a node is updated. Formatting on every one would throw the cache away
        // each time, so members already carrying the label are mounted as they are.
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect($script)->toContain('$4 == "nukevideo"')
            ->and($script)->toContain('Existing cache pool found — keeping it');
    });

    it('falls back to a capped docker volume when the host has no spare disk', function () {
        $node = cacheNode();
        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->toContain("CACHE_VOLUME='nukevideo_proxy_cache_{$node->id}'")
            ->and($script)->toContain("CACHE_FALLBACK_MAX_SIZE='".ProxyCacheService::FALLBACK_MAX_SIZE."'")
            ->and($script)->toContain('${CACHE_MAX_SIZE:+-e "VOD_CACHE_MAX_SIZE=$CACHE_MAX_SIZE"}');
    });

    it('no longer hands the edge a cache size from the settings', function () {
        // The edge sizes its cache to the pool on boot. A size injected from CdnSettings — 10g by
        // default — would silently cap a 10 TB pool at that.
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect($script)->not->toContain("'VOD_CACHE_MAX_SIZE=")
            ->and($script)->not->toContain('VOD_CACHE_INACTIVE=');
    });

    it('leaves a worker\'s disks alone', function () {
        $node = cacheNode(['type' => 'worker', 'is_storage_server' => true, 'storage_endpoint' => 'http://10.0.0.99:9000']);
        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->not->toContain('cache_disk_inventory')
            ->and($script)->not->toContain('mdadm');
    });

    it('caps every container\'s log', function () {
        // With the cache surviving redeploys, the edge's per-segment access log was the one file
        // on the OS disk that nothing ever truncated.
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect(substr_count($script, '--log-opt max-size=100m --log-opt max-file=5'))
            ->toBe(preg_match_all('/^[A-Z_]*RUN_ARGS=\'--name/m', $script));
    });
});

describe('a development proxy deploy', function () {
    beforeEach(fn () => inDevelopment());

    it('lists the disks but ticks none of them', function () {
        $node = cacheNode();
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturn("/dev/sdb\t4000000000000\tDISK\tempty\t");
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->getJson("/api/nodes/{$node->id}/cache-disks")
            ->assertOk()
            ->assertJsonPath('data.preselect', false)
            ->assertJsonCount(1, 'data.disks');
    });

    it('treats a deploy without a list as "format nothing"', function () {
        $node = cacheNode();
        $captured = '';
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args['input'] ?? $args[5];   // named when mocked directly, positional otherwise

            return '';
        });
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        // The deploy streams: nothing runs until the body is read.
        $body = $this->post("/api/nodes/{$node->id}/deploy")->assertOk()->streamedContent();
        expect($body)->not->toContain('"type":"error"', $body);

        expect($captured)->toContain("CHOSEN_DISKS=''")
            ->and($captured)->toContain("CACHE_VOLUME='nukevideo_dev_proxy_cache_{$node->id}'");
    });
});

describe('the disk inventory', function () {
    it('parses what the host reports, one disk per line', function () {
        inProduction();
        $node = cacheNode();

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturn(implode("\n", [
            "/dev/nvme0n1\t512110190592\tSAMSUNG MZVL2512\tsystem\tmounted at /",
            "/dev/sda\t10000831348736\tST10000NM0016\tempty\t",
            "/dev/sdb\t10000831348736\tST10000NM0016\tforeign\text4 \"backup\"",
            'not a disk line',
        ]));
        app()->instance(SSHService::class, $ssh);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->getJson("/api/nodes/{$node->id}/cache-disks")
            ->assertOk()
            ->assertJsonCount(3, 'data.disks')
            ->assertJsonPath('data.disks.0.state', 'system')
            ->assertJsonPath('data.disks.1.size', 10000831348736)
            ->assertJsonPath('data.disks.2.detail', 'ext4 "backup"');
    });
});

describe('traefik in front of the edge', function () {
    it('is deployed from a development panel too, without TLS', function () {
        // A development panel deploys to dedicated servers as well as to the developer's own
        // machine; the node decides at run time whether port 80 is already taken.
        inDevelopment();
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect($script)->toContain("TRAEFIK_RUN_ARGS='--name nukevideo_traefik")
            ->and($script)->not->toContain('certificatesresolvers')
            ->and($script)->toContain("grep -q ':80->'");
    });

    it('terminates TLS in production', function () {
        inProduction();

        expect(app(NodeService::class)->buildDeployScript(cacheNode()))
            ->toContain('--certificatesresolvers.le.acme.httpchallenge.entrypoint=web');
    });
});

describe('choosing the disks', function () {
    beforeEach(fn () => inProduction());

    it('formats only the disks the operator ticked and says which it left alone', function () {
        $script = app(NodeService::class)->buildDeployScript(cacheNode(), ['/dev/sdb', '/dev/sdc']);

        expect($script)->toContain("CHOSEN_DISKS='/dev/sdb /dev/sdc'")
            ->and($script)->toContain('Left untouched, not selected');
    });

    it('formats nothing when the list is empty, and everything when there is none', function () {
        $none = app(NodeService::class)->buildDeployScript(cacheNode(), []);
        $all = app(NodeService::class)->buildDeployScript(cacheNode());

        expect($none)->toContain("CHOSEN_DISKS=''")
            ->and($all)->not->toContain('CHOSEN_DISKS=');
    });

    it('refuses a device path that is not one', function () {
        // The path lands in a shell script on the node.
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/nodes/'.cacheNode()->id.'/deploy', ['disks' => ['/dev/sda; rm -rf /']])
            ->assertUnprocessable();
    });
});
