<?php

namespace App\Services;

use App\Models\Node;
use App\Settings\CloudflareSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The proxies' DNS records in Cloudflare: one address record per proxy node, `hostname → ip`,
 * written when the node is deployed and removed with it.
 *
 * It only ever touches what it wrote. Every record it creates carries {@see COMMENT}, and a
 * record under the same name without it is somebody's: an apex that already serves a site, a
 * CNAME to another provider. Overwriting one because a node was given its hostname would take
 * that site down from a deploy dialog, so it is refused with the reason instead.
 *
 * Everything here is optional. No token, a hostname in a zone the token cannot see, a node
 * reached over a private address: each is a deploy that goes on as it always did, with a line
 * saying the record is the operator's to create.
 */
class CloudflareDns
{
    private const API = 'https://api.cloudflare.com/client/v4';

    /** How a record says it is ours. Compared whole, so editing it in the dashboard disowns it. */
    public const COMMENT = 'Managed by NukeVideo';

    /** The record types that answer for a name, and so collide with the one we want there. */
    private const ADDRESS_TYPES = ['A', 'AAAA', 'CNAME'];

    public function __construct(private CloudflareSettings $settings) {}

    public function configured(): bool
    {
        return $this->settings->api_token !== '';
    }

    /**
     * Point the node's hostname at its address, and say what was done in one line for the deploy
     * log. Null when there is nothing to say: no token, or a node that has no record to keep.
     *
     * Throws when the record should exist and could not be written — a deploy that went on
     * would raise an edge whose certificate can never be issued, and fail a minute later with a
     * much worse message.
     */
    public function sync(Node $node): ?string
    {
        if (! $this->configured() || ! $node->hostname) {
            return null;
        }

        $hostname = strtolower($node->hostname);
        $ip = $node->ip_address;

        // The node's address is the one the panel reaches it by over SSH, which on a LAN is not
        // one a viewer can use. Publishing it would be a record that resolves and never answers.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return "Skipped: {$ip} is not a public address, so {$hostname} is left for you to point at this node.";
        }

        $zone = $this->zoneFor($hostname);

        if (! $zone) {
            return "Skipped: no Cloudflare zone this token can see covers {$hostname}.";
        }

        $type = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
        // The node's own choice when it made one, the installation's otherwise.
        $proxied = $node->cloudflare_proxied ?? $this->settings->proxied;
        $mode = $proxied ? 'proxied' : 'DNS only';

        $records = $this->addressRecords($zone['id'], $hostname);
        $ours = array_values(array_filter($records, fn ($r) => ($r['comment'] ?? null) === self::COMMENT));
        $foreign = array_values(array_filter($records, fn ($r) => ($r['comment'] ?? null) !== self::COMMENT));

        foreach ($foreign as $record) {
            // The other address family beside ours is a dual-stack name somebody completed by
            // hand, and answers alongside without contradicting anything.
            if ($record['type'] !== 'CNAME' && $record['type'] !== $type) {
                continue;
            }

            // Already what we would have written, by someone else's hand: nothing to do, and
            // not ours to take over — or to delete when the node goes.
            if ($record['type'] === $type && self::sameAddress($record['content'], $ip)) {
                return "{$hostname} already points at {$ip} (a record NukeVideo does not manage, left as it is).";
            }

            throw new \RuntimeException(
                "Cloudflare already has a {$record['type']} record for {$hostname} pointing at {$record['content']}, and NukeVideo did not create it. "
                .'Delete it, or point it at '.$ip.' yourself, and deploy again.'
            );
        }

        $payload = [
            'type' => $type,
            'name' => $hostname,
            'content' => $ip,
            // 1 is Cloudflare's "automatic", and the only value a proxied record accepts.
            'ttl' => 1,
            'proxied' => $proxied,
            'comment' => self::COMMENT,
        ];

        // One record is kept and rewritten in place; any other of ours under the name is a
        // leftover (the node moved from IPv4 to IPv6, say) and would answer alongside it.
        $current = array_shift($ours);
        foreach ($ours as $stale) {
            $this->call('delete', "zones/{$zone['id']}/dns_records/{$stale['id']}");
        }

        if (! $current) {
            $this->call('post', "zones/{$zone['id']}/dns_records", $payload);

            return "Created {$type} {$hostname} → {$ip} ({$mode}) in {$zone['name']}.";
        }

        if ($current['type'] === $type && self::sameAddress($current['content'], $ip) && (bool) ($current['proxied'] ?? false) === $proxied) {
            return "{$hostname} already points at {$ip} ({$mode}).";
        }

        $this->call('put', "zones/{$zone['id']}/dns_records/{$current['id']}", $payload);

        return "Updated {$hostname} → {$ip} ({$mode}) in {$zone['name']}.";
    }

    /**
     * Whether viewers reach this node through Cloudflare's proxy, which is what the deploy asks
     * to decide whose `X-Forwarded-For` the node may believe. The node's own answer counts with
     * or without a token: a record proxied by hand puts Cloudflare in front just the same.
     */
    public function proxies(Node $node): bool
    {
        return (bool) ($node->cloudflare_proxied ?? ($this->configured() && $this->settings->proxied));
    }

    /**
     * The addresses Cloudflare's proxy connects from, read from the list the edge nginx already
     * trusts for the same purpose, so the two can never disagree about who Cloudflare is.
     *
     * @return array<int, string>
     */
    public static function proxyRanges(): array
    {
        $config = file_get_contents(base_path('vod/nginx/cloudflare-realip.conf'));

        // The file opens with the docker networks, which are the edge's business alone.
        $cloudflare = substr($config, (int) strpos($config, '# Cloudflare'));
        preg_match_all('/^set_real_ip_from\s+(\S+);/m', $cloudflare, $matches);

        return $matches[1];
    }

    /**
     * Remove the record this class created for a hostname while it pointed at `$ip`, and only
     * that one. Returns how many.
     *
     * The address is part of the match because a hostname outlives a node: a server is replaced
     * by creating the new node under the same name, deploying it — which repoints the record —
     * and then deleting the old one. Without the address that last step deleted the record the
     * new node had just been given.
     */
    public function remove(string $hostname, string $ip): int
    {
        if (! $this->configured()) {
            return 0;
        }

        $hostname = strtolower($hostname);
        $zone = $this->zoneFor($hostname);

        if (! $zone) {
            return 0;
        }

        $removed = 0;
        foreach ($this->addressRecords($zone['id'], $hostname) as $record) {
            if (($record['comment'] ?? null) === self::COMMENT && self::sameAddress($record['content'] ?? '', $ip)) {
                $this->call('delete', "zones/{$zone['id']}/dns_records/{$record['id']}");
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * The names of the zones a token can see — the stored one, or one being tried before it is
     * saved. It is the cheapest call that needs exactly the permission everything else here
     * starts from, which makes it the token check as well.
     *
     * @return array<int, string>
     */
    public function zones(?string $token = null): array
    {
        return array_column($this->allZones($token), 'name');
    }

    /**
     * The zone a hostname belongs to: the longest one it ends with, so `edge.eu.example.com`
     * lands in a delegated `eu.example.com` when the token sees both.
     *
     * @return array{id: string, name: string}|null
     */
    private function zoneFor(string $hostname): ?array
    {
        $match = null;

        foreach ($this->allZones() as $zone) {
            $name = strtolower($zone['name']);
            if ($hostname !== $name && ! str_ends_with($hostname, ".{$name}")) {
                continue;
            }
            if (! $match || strlen($name) > strlen($match['name'])) {
                $match = ['id' => $zone['id'], 'name' => $name];
            }
        }

        return $match;
    }

    /** @return array<int, array{id: string, name: string}> */
    private function allZones(?string $token = null): array
    {
        $zones = [];
        $page = 1;

        do {
            $body = $this->call('get', 'zones', ['per_page' => 50, 'page' => $page], $token);
            array_push($zones, ...($body['result'] ?? []));
            $pages = (int) ($body['result_info']['total_pages'] ?? 1);
        } while ($page++ < $pages);

        return $zones;
    }

    /** @return array<int, array<string, mixed>> */
    private function addressRecords(string $zoneId, string $hostname): array
    {
        // Asked for by name and filtered by name again, over every page: the answer decides
        // whether a record gets overwritten, so it does not rest on the filter having been
        // honoured — were it ignored, this reads the whole zone and is still right.
        $records = [];
        $page = 1;

        do {
            $body = $this->call('get', "zones/{$zoneId}/dns_records", ['name' => $hostname, 'per_page' => 100, 'page' => $page]);
            array_push($records, ...($body['result'] ?? []));
            $pages = (int) ($body['result_info']['total_pages'] ?? 1);
        } while ($page++ < $pages);

        return array_values(array_filter(
            $records,
            fn ($record) => in_array($record['type'] ?? null, self::ADDRESS_TYPES, true)
                && strtolower($record['name'] ?? '') === $hostname,
        ));
    }

    /**
     * Compared as addresses, not as text: Cloudflare hands an AAAA back in its shortest form,
     * and a node entered as `2001:0db8::0001` would otherwise be "moved" on every deploy. A
     * CNAME's target is no address at all and never equals one.
     */
    private static function sameAddress(string $a, string $b): bool
    {
        $a = @inet_pton($a);

        return $a !== false && $a === @inet_pton($b);
    }

    /**
     * One request, with Cloudflare's own words in the failure: its errors name the cause (a
     * token without the permission, a record that already exists) far better than a status code.
     */
    private function call(string $method, string $path, array $data = [], ?string $token = null): array
    {
        try {
            $response = Http::baseUrl(self::API)
                ->withToken($token ?? $this->settings->api_token)
                ->acceptJson()
                ->timeout(15)
                ->{$method}($path, $data);
        } catch (ConnectionException $e) {
            throw new \RuntimeException("Cloudflare could not be reached: {$e->getMessage()}");
        }

        $body = $response->json() ?? [];

        if ($response->failed() || ! ($body['success'] ?? false)) {
            $errors = implode('; ', array_filter(array_column($body['errors'] ?? [], 'message')));

            throw new \RuntimeException('Cloudflare refused the request: '.($errors ?: "HTTP {$response->status()}"));
        }

        return $body;
    }
}
