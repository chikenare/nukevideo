<?php

namespace App\Settings;

use App\Services\CloudflareDns;
use Spatie\LaravelSettings\Settings;

/**
 * The Cloudflare account that holds the proxies' DNS, when there is one. Optional: with no token
 * the hostnames are the operator's to point at the nodes, as they always were.
 *
 * Kept here and not in the environment because it is the panel that uses it, on behalf of
 * whoever is deploying — a token in `.env` would need a shell on the API host to set or rotate.
 * No zone is stored: {@see CloudflareDns} finds the one a hostname belongs to among those the
 * token can see, so a fleet spread over several domains needs nothing configured per domain.
 */
class CloudflareSettings extends Settings
{
    /** An API token with Zone:Read and DNS:Edit on the zones the proxies live in, or ''. */
    public string $api_token;

    /**
     * Whether the records are created behind Cloudflare's proxy (the orange cloud). Off by
     * default: the edge is already the cache, and a DNS-only record keeps the viewer talking to
     * it directly. The fleet's default only — a node says otherwise in its own
     * `cloudflare_proxied`.
     */
    public bool $proxied;

    public static function group(): string
    {
        return 'cloudflare';
    }

    public static function encrypted(): array
    {
        return ['api_token'];
    }
}
