<?php

use App\Models\Node;
use App\Models\User;
use App\Services\NodeService;
use App\Services\ProxyCacheService;
use App\Services\SSHService;
use App\Settings\AppSettings;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function cacheNode(array $attributes = []): Node
{
    // The panel's one key, as any SSH-backed call reads it ({@see \App\Services\SshKeyService}).
    AppSettings::fake(['ssh_private_key' => 'PRIVATE', 'ssh_public_key' => 'ssh-ed25519 AAAA nukevideo', 'ssh_fingerprint' => 'ff']);

    return Node::create([
        'ip_address' => '10.0.0.99',
        'user' => 'deploy',
        'name' => 'edge-test',
        'type' => 'proxy',
        'hostname' => 'edge.example.com',
        'is_active' => true,
        ...$attributes,
    ]);
}

/**
 * Runs the deploy's own `cache_select_disks` against an inventory, the way the node would: the
 * selection is pure string work, so it can be executed here rather than read for keywords.
 *
 * @param  array<string, string>  $inventory  device => state
 * @param  array<string, string>  $chosen  CHOSEN_DISKS / CHOSEN_SHARED, when the panel sent them
 * @return array{ok: bool, output: string, format: array<int, string>, share: array<int, string>}
 */
function selectDisks(array $inventory, array $chosen = []): array
{
    $lines = collect($inventory)->map(fn (string $state, string $device) => "{$device}\t1000\tDISK\t{$state}\t\t0")->implode("\n");
    $script = 'SUDO=""; . "$SCRIPT"; POOL_DISKS=$(echo "$INVENTORY" | awk -F"\t" \'$4 == "nukevideo" { print $1 }\');'
        .' cache_select_disks; echo "FORMAT=$(echo $FREE_DISKS)"; echo "SHARE=$(echo $SHARED_DISKS)"';

    $result = Process::env(['SCRIPT' => resource_path('deploy/cache-disks.sh'), 'INVENTORY' => $lines, ...$chosen])
        ->run(['bash', '-c', $script]);
    preg_match('/^FORMAT=(.*)$/m', $result->output(), $format);
    preg_match('/^SHARE=(.*)$/m', $result->output(), $share);

    return [
        'ok' => $result->successful(),
        'output' => $result->output(),
        'format' => array_values(array_filter(explode(' ', $format[1] ?? ''))),
        'share' => array_values(array_filter(explode(' ', $share[1] ?? ''))),
    ];
}

function inProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

function inDevelopment(): void
{
    app()->detectEnvironment(fn () => 'local');
    // A development deploy pulls `node-dev` from a registry, and is refused without one.
    config(['nuke.registry' => '10.0.0.240:5000']);
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

    it('stops the old edge gracefully and fails the deploy when the new one never answers', function () {
        // A proxy that does not come up must fail its operation, or a fleet deploy's chain would
        // move on and take the next edge down with the same broken image.
        $script = app(NodeService::class)->buildDeployScript(cacheNode(), []);

        // SIGTERM is nginx's fast shutdown, which drops open connections: only SIGQUIT drains.
        expect($script)->toContain('--stop-signal SIGQUIT')
            ->and($script)->toContain('docker stop "$SERVICE_CONTAINER"')
            ->and($script)->toContain('http://127.0.0.1/healthz')
            ->and($script)->toContain('Edge did not come up');
    });

    it('leaves Traefik alone only when it already runs exactly what it would be replaced with', function () {
        // Replacing it drops TLS for every site on the host: the longest gap of a proxy deploy.
        // But the image alone is not enough: a development panel's Traefik has no TLS, runs under
        // the same name, and a production deploy that kept it would serve no HTTPS at all.
        $node = cacheNode();
        preg_match("/^TRAEFIK_CONFIG='([0-9a-f]+)'$/m", app(NodeService::class)->buildDeployScript($node, []), $production);
        inDevelopment();
        preg_match("/^TRAEFIK_CONFIG='([0-9a-f]+)'$/m", $script = app(NodeService::class)->buildDeployScript($node, []), $development);

        expect($production[1] ?? null)->not->toBeNull()
            ->and($development[1] ?? null)->not->toBe($production[1] ?? null)
            ->and($script)->toContain("nukevideo.config={$development[1]}")
            ->and($script)->toContain('= "true $TRAEFIK_CONFIG"');
    });

    it('keeps the shell and the application agreeing on the pool\'s names', function () {
        // The constants live twice: in cache-disks.sh, which does the work, and in PHP, which
        // validates the pool and builds the directory. A drift between them is a pool the panel
        // cannot find.
        $shell = NodeService::deployScript('cache-disks');

        expect($shell)->toContain('CACHE_POOL_MOUNT='.ProxyCacheService::MOUNT)
            ->and($shell)->toContain('CACHE_POOL_LABEL='.ProxyCacheService::FS_LABEL)
            ->and($shell)->toContain('CACHE_POOL_MD='.ProxyCacheService::MD_DEVICE)
            ->and($shell)->toContain('CACHE_POOL_NAME=${CACHE_POOL_MD##*/}');
    });

    it('labels the pool with something XFS accepts', function () {
        // mkfs.xfs refuses a label over twelve characters, and the array's name was once used
        // for both: every deploy that had a disk to format died on that line.
        expect(strlen(ProxyCacheService::FS_LABEL))->toBeLessThanOrEqual(12);
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
        $node = cacheNode(['type' => 'worker']);
        $script = app(NodeService::class)->buildDeployScript($node);

        expect($script)->not->toContain('cache_disk_inventory')
            ->and($script)->not->toContain('mdadm');
    });

    it('caps every container\'s log, the edge\'s tighter than the rest', function () {
        // With the cache surviving redeploys, the edge's per-segment access log was the one file
        // on the OS disk that nothing ever truncated. The edge's log also carries the raw playback
        // token of every request, replayable until it expires, so it keeps a much shorter window.
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect(substr_count($script, '--log-opt max-size=100m --log-opt max-file=5'))
            ->toBe(preg_match_all('/^[A-Z_]*RUN_ARGS=\'--name/m', $script) - 1)
            ->and($script)->toMatch('/^RUN_ARGS=\'--name nukevideo_proxy_\d+ --restart unless-stopped --log-opt max-size=20m --log-opt max-file=3 /m');
    });

    it('tells the edge to expect the pool, so a boot without it caps the cache', function () {
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect($script)->toContain('${CACHE_EXPECT_POOL:+-e "VOD_CACHE_EXPECT_POOL=$CACHE_EXPECT_POOL"}')
            ->and($script)->toContain('CACHE_EXPECT_POOL=1')
            ->and($script)->toContain('touch "$CACHE_DIRECTORY/$CACHE_POOL_MARKER"');
    });

    it('checks for sudo before anything else runs on the node', function () {
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        expect(strpos($script, 'cannot sudo without a password'))->toBeLessThan(strpos($script, 'set -e'));
    });

    it('installs every tool through the one helper, which fetches the package lists first', function () {
        $script = app(NodeService::class)->buildDeployScript(cacheNode());

        // Defined before the first section that calls it.
        expect(strpos($script, 'ensure_commands()'))->toBeLessThan(strpos($script, 'ensure_commands "curl=curl ca-certificates"'))
            ->and($script)->toContain('ensure_commands mdadm=mdadm mkfs.xfs=xfsprogs')
            ->and($script)->toContain('ensure_commands sfdisk=fdisk ||');

        // An install of its own, against lists nobody refreshed, is how a first deploy went on
        // without sfdisk.
        foreach (['common', 'cache-disks', 'proxy', 'worker', 'vector'] as $section) {
            expect(NodeService::deployScript($section))->not->toContain('apt-get install -y -qq fdisk')
                ->and(NodeService::deployScript($section))->not->toContain('apt-get install -y -qq mdadm');
        }

        $packages = NodeService::deployScript('packages');
        expect(strpos($packages, ' update -qq'))->toBeLessThan(strpos($packages, 'install -y -qq'));
    });
});

describe('a development proxy deploy', function () {
    beforeEach(fn () => inDevelopment());

    it('lists the disks but ticks none of them', function () {
        $node = cacheNode();
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturn("/dev/sdb\t4000000000000\tDISK\tempty\t\t0");
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
            $captured = $args['input'] ?? $args[5] ?? $captured;   // named when mocked directly, positional otherwise

            return '';
        });
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/nodes/{$node->id}/deploy")->assertStatus(202);
        expect(Activity::inLog('node')->latest('id')->first()->properties['status'])->toBe('succeeded');

        expect($captured)->toContain("CHOSEN_DISKS=''")
            ->and($captured)->toContain("CACHE_VOLUME='nukevideo_dev_proxy_cache_{$node->id}'");
    });
});

describe('the disk inventory', function () {
    it('ticks the spare disks for a production panel', function () {
        inProduction();
        $node = cacheNode();
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturn("/dev/sdb\t4000000000000\tDISK\tempty\t\t0");
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->getJson("/api/nodes/{$node->id}/cache-disks")->assertOk()->assertJsonPath('data.preselect', true);
    });

    it('installs sfdisk before listing, so a first setup is offered its system disks', function () {
        // Nothing has been deployed on a host the first time its disks are listed, so nothing
        // has installed the tool that finds a system disk's unpartitioned space either.
        inProduction();
        $input = '';
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturnUsing(function (...$args) use (&$input) {
            $input = $args['input'] ?? $args[5];

            return '';
        });
        app()->instance(SSHService::class, $ssh);

        $node = cacheNode();
        app(ProxyCacheService::class)->inventory($node);

        // Off stdout, which is the listing; and never able to fail it.
        expect($input)->toContain('ensure_commands sfdisk=fdisk >&2 || true')
            ->and(strpos($input, 'ensure_commands()'))->toBeLessThan(strpos($input, 'ensure_commands sfdisk=fdisk >&2'))
            ->and(strpos($input, 'cannot sudo without a password'))->toBeLessThan(strpos($input, 'ensure_commands()'));
    });

    it('is written by the script in the six fields the parser reads', function () {
        // The two sides only meet on a node. A field added to one and not the other drops every
        // disk from the listing, and the panel then has nothing to show.
        $shell = NodeService::deployScript('cache-disks');

        expect($shell)->toContain("printf '%s\\t%s\\t%s\\t%s\\t%s\\t%s\\n' \"\$dev\"");
    });

    it('parses what the host reports, one disk per line', function () {
        inProduction();
        $node = cacheNode();

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturn(implode("\n", [
            "/dev/nvme0n1\t512110190592\tSAMSUNG MZVL2512\tsystem\tmounted at /\t0",
            "/dev/sda\t10000831348736\tST10000NM0016\tempty\t\t0",
            "/dev/sdb\t10000831348736\tST10000NM0016\tforeign\text4 \"backup\"\t0",
            "/dev/sdc\t10000831348736\tST10000NM0016\tshared\tmounted at /, with unpartitioned space\t9946070941696",
            'not a disk line',
        ]));
        app()->instance(SSHService::class, $ssh);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->getJson("/api/nodes/{$node->id}/cache-disks")
            ->assertOk()
            ->assertJsonCount(4, 'data.disks')
            ->assertJsonPath('data.disks.0.state', 'system')
            ->assertJsonPath('data.disks.1.size', 10000831348736)
            ->assertJsonPath('data.disks.1.free', 0)
            ->assertJsonPath('data.disks.2.detail', 'ext4 "backup"')
            ->assertJsonPath('data.disks.3.state', 'shared')
            ->assertJsonPath('data.disks.3.free', 9946070941696);
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

    it('hands the node one list of disks to format and another of disks to share', function () {
        // A name alone does not say which fate was agreed to, and the node reads the state again.
        $script = app(NodeService::class)->buildDeployScript(cacheNode(), ['/dev/sdb'], ['/dev/sda']);

        expect($script)->toContain("CHOSEN_DISKS='/dev/sdb'")
            ->and($script)->toContain("CHOSEN_SHARED='/dev/sda'");
    });

    it('never shares a disk nobody named, even when it formats every spare one', function () {
        $none = app(NodeService::class)->buildDeployScript(cacheNode());
        expect($none)->toContain("CHOSEN_SHARED=''");

        $picked = selectDisks(['/dev/sda' => 'shared', '/dev/sdb' => 'empty', '/dev/sdc' => 'foreign', '/dev/nvme0n1' => 'system']);

        expect($picked['ok'])->toBeTrue()
            ->and($picked['format'])->toBe(['/dev/sdb', '/dev/sdc'])
            ->and($picked['share'])->toBe([]);
    });

    it('gives each ticked disk the fate it was ticked for', function () {
        $picked = selectDisks(
            ['/dev/sda' => 'shared', '/dev/sdb' => 'empty', '/dev/sdc' => 'foreign'],
            ['CHOSEN_DISKS' => '/dev/sdb', 'CHOSEN_SHARED' => '/dev/sda'],
        );

        expect($picked['ok'])->toBeTrue()
            ->and($picked['format'])->toBe(['/dev/sdb'])
            ->and($picked['share'])->toBe(['/dev/sda'])
            ->and($picked['output'])->toContain('Left untouched, not selected: /dev/sdc');
    });

    it('refuses to deploy when a disk ticked as shared no longer is one', function () {
        // Its filesystem got unmounted between the panel and the deploy: the disk reads as
        // `foreign` now, and a single list would have sent it, whole, to the wipe.
        $picked = selectDisks(
            ['/dev/sda' => 'foreign', '/dev/sdb' => 'empty'],
            ['CHOSEN_DISKS' => '/dev/sdb', 'CHOSEN_SHARED' => '/dev/sda'],
        );

        expect($picked['ok'])->toBeFalse()
            ->and($picked['output'])->toContain('ERROR: /dev/sda was to be shared')
            ->and($picked['format'])->toBe([]);
    });

    it('never selects a system disk, whichever list names it', function () {
        $format = selectDisks(['/dev/sda' => 'system', '/dev/sdb' => 'shared'], ['CHOSEN_DISKS' => '/dev/sda /dev/sdb', 'CHOSEN_SHARED' => '']);
        $share = selectDisks(['/dev/sda' => 'system'], ['CHOSEN_DISKS' => '', 'CHOSEN_SHARED' => '/dev/sda']);

        // Ticked to be formatted, a system or shared disk is simply not among the spare ones.
        expect($format['ok'])->toBeTrue()
            ->and($format['format'])->toBe([])
            ->and($format['share'])->toBe([])
            ->and($share['ok'])->toBeFalse();
    });

    it('accepts the same lists again once the disk carries the pool', function () {
        // An API client redeploying with the body of its first deploy is asking for what it has.
        $picked = selectDisks(['/dev/sda' => 'nukevideo', '/dev/sdb' => 'nukevideo'], ['CHOSEN_DISKS' => '/dev/sdb', 'CHOSEN_SHARED' => '/dev/sda']);

        expect($picked['ok'])->toBeTrue()
            ->and($picked['format'])->toBe([])
            ->and($picked['share'])->toBe([]);
    });

    it('carries the shared list from the request to the node', function () {
        $node = cacheNode();
        $captured = '';
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args['input'] ?? $args[5] ?? $captured;

            return '';
        });
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/nodes/{$node->id}/deploy", ['disks' => ['/dev/sdb'], 'sharedDisks' => ['/dev/sda']])->assertStatus(202);

        expect($captured)->toContain("CHOSEN_DISKS='/dev/sdb'")
            ->and($captured)->toContain("CHOSEN_SHARED='/dev/sda'");
    });

    it('refuses a disk named in both lists, and a shared path that is not one', function () {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $node = cacheNode();

        $this->postJson("/api/nodes/{$node->id}/deploy", ['disks' => ['/dev/sda'], 'sharedDisks' => ['/dev/sda']])->assertUnprocessable();
        $this->postJson("/api/nodes/{$node->id}/deploy", ['disks' => [], 'sharedDisks' => ['/dev/sda; reboot']])->assertUnprocessable();
    });

    it('formats nothing when the list is empty, and everything when there is none', function () {
        $none = app(NodeService::class)->buildDeployScript(cacheNode(), []);
        $all = app(NodeService::class)->buildDeployScript(cacheNode());

        expect($none)->toContain("CHOSEN_DISKS=''")
            ->and($all)->not->toContain('CHOSEN_DISKS=');
    });

    it('refuses a production proxy deploy that does not say which disks', function () {
        // The service reads a missing list as "every spare disk". The panel omitted the field
        // whenever it had nothing to show — including after failing to read the host's disks —
        // and that silence formatted every disk it had not been able to list.
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $node = cacheNode();

        $this->postJson("/api/nodes/{$node->id}/deploy")->assertUnprocessable();
        $this->postJson("/api/nodes/{$node->id}/deploy", ['disks' => null])->assertUnprocessable();
    });

    it('accepts an empty list as "format none"', function () {
        $node = cacheNode();
        $captured = '';
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args['input'] ?? $args[5] ?? $captured;

            return '';
        });
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/nodes/{$node->id}/deploy", ['disks' => []])->assertStatus(202);
        expect(Activity::inLog('node')->latest('id')->first()->properties['status'])->toBe('succeeded');
        expect($captured)->toContain("CHOSEN_DISKS=''");
    });

    it('still deploys a worker without a list', function () {
        $node = cacheNode(['type' => 'worker']);
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->once()->andReturn('');
        app()->instance(SSHService::class, $ssh);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/nodes/{$node->id}/deploy")->assertStatus(202);
        expect(Activity::inLog('node')->latest('id')->first()->properties['status'])->toBe('succeeded');
    });
});

describe('the pool check', function () {
    beforeEach(fn () => inProduction());

    it('reports a host that never had a pool as a notice, and an unmounted pool as an error', function () {
        $node = cacheNode();
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('run')->andReturnUsing(function (...$args) {
            $command = $args['command'] ?? $args[3];
            if (str_contains($command, 'mountpoint -q')) {
                expect($command)->toContain('blkid -L '.ProxyCacheService::FS_LABEL)
                    ->and($command)->toContain('no cache pool provisioned')
                    ->and($command)->toContain('NOT mounted');

                return 'no cache pool provisioned, edge caches on a docker volume';
            }

            return 'ok';
        });
        app()->instance(SSHService::class, $ssh);

        $checks = collect(app(NodeService::class)->runValidation($node))->keyBy('key');

        expect($checks['cache']['status'])->toBe('ok')
            ->and($checks['cache']['output'])->toContain('docker volume');
    });
});

describe('the node env overrides', function () {
    beforeEach(fn () => inProduction());

    it('cannot replace what the deploy owns', function () {
        // A node's env is a free-text field. Merged last, one line in it re-pointed the edge at
        // another API, another bucket, or a token secret of the editor's choosing.
        $node = cacheNode(['env' => "VOD_TOKEN_SECRET=mine\nINTERNAL_API_URL=http://evil.example\nAWS_SECRET_ACCESS_KEY=mine\nSECURE_TOKEN_EXPIRES_TIME=5d"]);

        $env = app(NodeService::class)->getEnvironmentVariables($node);

        expect($env)->toContain('VOD_TOKEN_SECRET=secret')
            ->and($env)->not->toContain('VOD_TOKEN_SECRET=mine')
            ->and($env)->not->toContain('INTERNAL_API_URL=http://evil.example')
            ->and($env)->not->toContain('AWS_SECRET_ACCESS_KEY=mine')
            // Tuning knobs still override.
            ->and($env)->toContain('SECURE_TOKEN_EXPIRES_TIME=5d');
    });

    it('refuses a device path that is not one', function () {
        // The path lands in a shell script on the node.
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/nodes/'.cacheNode()->id.'/deploy', ['disks' => ['/dev/sda; rm -rf /']])
            ->assertUnprocessable();
    });
});
