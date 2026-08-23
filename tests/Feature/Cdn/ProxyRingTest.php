<?php

use App\Console\Commands\ProbeProxyNodes;
use App\Models\Node;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function ringProxy(array $attributes = []): Node
{
    static $n = 0;
    $n++;

    return Node::create([
        'ip_address' => "10.0.0.{$n}",
        'user' => 'deploy',
        'name' => "edge-{$n}",
        'type' => 'proxy',
        'hostname' => "edge-{$n}.example.com",
        'is_active' => true,
        ...$attributes,
    ]);
}

beforeEach(function () {
    app()->detectEnvironment(fn () => 'production');
    CdnSettings::fake([
        'provider' => 'self_hosted',
        'providers' => ['self_hosted' => ['token_secret' => 'secret'], 'bunny' => []],
    ]);
});

describe('the proxy ring', function () {
    it('keeps a video on the same node for as long as the fleet is unchanged', function () {
        ringProxy();
        ringProxy();
        ringProxy();

        $first = Node::findProxyForVideo('01HZZZZZZZZZZZZZZZZZZZZZZZ');

        expect(Node::findProxyForVideo('01HZZZZZZZZZZZZZZZZZZZZZZZ')->id)->toBe($first->id);
    });

    it('skips a draining node without stopping it', function () {
        // Draining is the maintenance tool: the node goes on serving the sessions it has and
        // only stops receiving new links. Deactivating is what stops containers.
        $kept = ringProxy();
        $draining = ringProxy(['is_draining' => true]);

        foreach (range(1, 20) as $i) {
            expect(Node::findProxyForVideo(str_pad((string) $i, 26, '0', STR_PAD_LEFT))->id)->toBe($kept->id);
        }
        expect($draining->fresh()->is_active)->toBeTrue();
    });

    it('skips a node the probe has given up on', function () {
        $kept = ringProxy();
        ringProxy(['health_failures' => Node::HEALTH_FAILURE_THRESHOLD]);

        foreach (range(1, 20) as $i) {
            expect(Node::findProxyForVideo(str_pad((string) $i, 26, '0', STR_PAD_LEFT))->id)->toBe($kept->id);
        }
    });

    it('tolerates failures below the threshold', function () {
        // One slow answer must not move a node's whole catalogue, cold, onto its neighbours.
        $flaky = ringProxy(['health_failures' => Node::HEALTH_FAILURE_THRESHOLD - 1]);

        expect(Node::findProxyForVideo('01HZZZZZZZZZZZZZZZZZZZZZZZ')->id)->toBe($flaky->id);
    });

    it('never picks a proxy without a hostname', function () {
        // It used to, and rendered the link as `https:///...`.
        ringProxy(['hostname' => null]);
        $named = ringProxy();

        expect(Node::findProxyForVideo('01HZZZZZZZZZZZZZZZZZZZZZZZ')->id)->toBe($named->id);
    });

    it('falls back to every active node when the probe has condemned the whole fleet', function () {
        // If nothing is routable, the probe is more likely wrong than the fleet dead — the API
        // host may have lost its own network. A link to a node that may be down beats none.
        ringProxy(['health_failures' => 10]);
        ringProxy(['health_failures' => 10]);

        expect(Node::findProxyForVideo('01HZZZZZZZZZZZZZZZZZZZZZZZ'))->not->toBeNull();
    });

    it('still honours draining inside the fallback', function () {
        ringProxy(['health_failures' => 10, 'is_draining' => true]);

        expect(Node::findProxyForVideo('01HZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeNull();
    });
});

describe('nodes:probe', function () {
    it('counts silence and clears it on the first good answer', function () {
        $node = ringProxy();

        // Stubs stack rather than replace, so the whole story is one sequence.
        $sequence = Http::sequence();
        foreach (range(1, Node::HEALTH_FAILURE_THRESHOLD) as $i) {
            $sequence->push('', 500);
        }
        $sequence->push('', 204, [ProbeProxyNodes::HEADER => (string) $node->id]);
        Http::fake(["https://{$node->hostname}/healthz" => $sequence]);

        foreach (range(1, Node::HEALTH_FAILURE_THRESHOLD) as $i) {
            $this->artisan('nodes:probe')->assertSuccessful();
        }
        expect($node->fresh()->isHealthy())->toBeFalse();

        $this->artisan('nodes:probe')->assertSuccessful();

        expect($node->fresh()->isHealthy())->toBeTrue()
            ->and($node->fresh()->last_healthy_at)->not->toBeNull();
    });

    it('does not take a 200 from something that is not the edge as the edge being up', function () {
        // Traefik with no router answers 404, Cloudflare 403 or a challenge page, a wrong DNS
        // record somebody else's site. The header is the only thing that proves nginx answered.
        $node = ringProxy();
        Http::fake(["https://{$node->hostname}/healthz" => Http::response('<html>welcome</html>', 200)]);

        $this->artisan('nodes:probe')->assertSuccessful();

        expect($node->fresh()->health_failures)->toBe(1);
    });

    it('does not mistake one edge for another', function () {
        $node = ringProxy();
        Http::fake(["https://{$node->hostname}/healthz" => Http::response('', 204, [ProbeProxyNodes::HEADER => '999'])]);

        $this->artisan('nodes:probe')->assertSuccessful();

        expect($node->fresh()->health_failures)->toBe(1);
    });

    it('leaves inactive nodes and the other provider alone', function () {
        $inactive = ringProxy(['is_active' => false, 'health_failures' => 2]);
        Http::fake();

        $this->artisan('nodes:probe')->assertSuccessful();

        Http::assertNothingSent();
        expect($inactive->fresh()->health_failures)->toBe(2);

        CdnSettings::fake(['provider' => 'bunny', 'providers' => ['self_hosted' => [], 'bunny' => ['host' => 'x']]]);
        ringProxy();
        $this->artisan('nodes:probe')->assertSuccessful();
        Http::assertNothingSent();
    });
});
