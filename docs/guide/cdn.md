# CDN & Delivery

NukeVideo serves the same [static CMAF](/guide/streaming) (shared HLS + DASH segments on S3) through one of two delivery modes. You pick the mode per deployment in the admin panel under **CDN Settings** by setting the `provider` field to `self_hosted` or `bunny`.

Both modes gate access with time-limited tokens and read the packaged output from your S3 bucket. Only the front door differs.

## Self-Hosted Proxy Nodes

`provider: self_hosted`

Proxy nodes are servers you run and manage from the admin panel (over SSH — see [Nodes](/guide/nodes)). Each runs a custom nginx build that:

- Validates Akamai-style stream tokens (HMAC) on incoming requests.
- Reads the pre-packaged CMAF segments from S3 using AWS authentication.
- Caches segments locally so repeat requests don't hit S3 every time; **manifests bypass the cache** to stay fresh.
- Resolves the real client IP behind Cloudflare or another reverse proxy.
- Ships access-log bandwidth to ClickHouse through a Vector.dev pipeline.

Token validation and cache behavior are configured in **CDN Settings** under the `self_hosted` provider (token secret, stream/query token expiry, cache size and inactivity). These are injected into the proxy container at deploy time — see [Configuration: Proxy Node Delivery](/guide/configuration#proxy-node-delivery).

**Choose self-hosted when** you want full control over delivery, keep traffic on your own infrastructure, need the built-in bandwidth analytics per node, or already run edge servers.

## Bunny CDN

`provider: bunny`

Instead of running proxy nodes, point a [Bunny](https://bunny.net/) pull-zone at your S3 bucket as its origin. Bunny pulls the packaged CMAF on demand and handles edge caching globally — there is no delivery infrastructure for you to operate.

Access is protected with Bunny's **token authentication** (HMAC-SHA256) in **directory mode**: the token is embedded as a path prefix scoped to the video's directory (via `token_path`), so the manifest **and all of its relative segments** authenticate under a single token. The client's IP is **not** folded into the signature, so tokens work across CDN edges and roaming clients.

Configure it in the admin panel under **CDN Settings** with the `bunny` provider:

| Setting | Description |
|---------|-------------|
| **Host** | The Bunny pull-zone hostname that fronts your S3 origin |
| **Token key** | The pull-zone's URL-signing key, used to sign the HMAC token |
| **Token window** | Token validity window, in seconds |

Bunny is configured entirely from the panel (stored in the database); it does not use `.env` variables.

**Choose Bunny when** you want a global CDN without operating proxy nodes, need to scale delivery quickly, or prefer to offload edge caching and bandwidth entirely to a managed provider.

## Which Should I Use?

| | Self-hosted proxy nodes | Bunny CDN |
|---|---|---|
| Infrastructure to run | Your own proxy servers | None (managed by Bunny) |
| Origin | S3 (via AWS auth) | S3 pull-zone origin |
| Token scheme | Akamai-style HMAC | HMAC-SHA256, directory mode |
| IP binding | Cloudflare real-IP aware | Not IP-bound |
| Edge caching | Local nginx cache per node | Bunny global edge |
| Bandwidth analytics | Built-in (Vector → ClickHouse) | Via Bunny's own reporting |

Both serve identical CMAF, so you can start with one and switch by changing the CDN Settings provider — no re-packaging required.
