# Streaming & VOD

NukeVideo serves **pre-packaged, static CMAF**. Encoded renditions are packaged once by [shaka-packager](https://github.com/shaka-project/shaka-packager) into shared segments that are stored on S3 and served as-is. There is no on-the-fly repackaging at request time.

## One Package, Both Protocols

Each output is packaged in a single pass into CMAF fragments. Because HLS and DASH can reference the same fragmented-MP4 segments, that one package serves **both** protocols:

| Protocol | Manifest | Use Case |
|----------|----------|----------|
| HLS | `.m3u8` | Apple devices, most browsers, widest compatibility |
| DASH | `.mpd` | Android, smart TVs, DRM-ready |

Subtitles are packaged as CMAF too and referenced from both manifests. Multiple audio and subtitle tracks (with per-language labels) are supported.

## How Delivery Works

The packaged segments and manifests live on S3. A delivery layer sits in front of the bucket to enforce access control and cache segments close to viewers:

```
Client → Delivery (proxy node or Bunny CDN) → S3 (CMAF) → Client
```

1. The client requests a manifest with a signed token.
2. The delivery layer validates the token.
3. The manifest is served, then the client fetches the CMAF segments it references.
4. Segments are cached at the edge; manifests bypass the cache so updates are picked up immediately.

There are two delivery paths — self-hosted **proxy nodes** or **Bunny CDN**. Both serve the same static CMAF from S3; only the front door differs. See [CDN & Delivery](/guide/cdn) for the full comparison and setup.

## Requesting a Playback URL

The API mints a signed manifest URL for a video's output:

```
POST /api/outputs/{ulid}
```

The response contains the signed URL for the requested format (HLS or DASH). The URL points at whichever delivery layer is configured (a proxy node host or the Bunny pull-zone host) and carries the access token.

**Request body** (all optional):

| Field | Type | Notes |
|-------|------|-------|
| `format` | `dash` \| `hls` | Defaults to the output's first available format. |
| `resolution` | integer | Caps the ladder at this height. |
| `ip` | string | The viewer's address, when the link is minted from your backend: the token is bound to the address that fetches the manifest. |
| `tracking_id` | string | Your own tracking id for this viewer — a customer, a campaign. It never appears in the link: the mint records the link's token against your id server-side, so every request the link produces — the manifest, each segment — is attributed to it in the [bandwidth analytics](/api/users#bandwidth-by-tracking-id) when the CDN log is ingested. Up to 64 characters of `A-Z a-z 0-9 _ -`. The mapping is best-effort (it lives server-side, for the token's lifetime plus a margin). When omitted on a session or personal-token request the traffic is attributed to the ULID of the authenticated user — the admin panel's own playback stays attributed that way. A project key that omits it leaves the traffic unattributed: naming the viewer is the integrator's job. |

## Token-Based Access Control

Playback URLs are signed with a time-limited token so segments can't be fetched without authorization.
One token covers a whole playback session — the manifest and every segment the player derives from
it — and it is minted by the API with the provider's **token window** (one hour by default). The
segments do not get a window of their own: they expire with the link, so a session that outlives
the window has to request a fresh link.

The exact signing scheme depends on the delivery layer:

- **Proxy nodes** validate Akamai-style tokens (HMAC) scoped to the manifest's directory, rewrite the manifest so its segment URLs carry that same token, and read the segments from S3 using AWS authentication.
- **Bunny CDN** uses HMAC-SHA256 tokens in directory mode: the token is a path prefix scoped to the video's directory, so the manifest and all of its relative segments authenticate under one token.

Either way the token is what ties a session's traffic back to the link that was minted, which is
how a `tracking_id` is attributed without ever appearing in the URL.

## Caching

The delivery layer caches CMAF **segments** locally (or at the CDN edge) so repeated requests don't hit S3 every time. **Manifests bypass the cache** to stay fresh. A self-hosted proxy node caches into its own disk pool, sized automatically — see [Nodes: Cache disks](/guide/nodes#cache-disks).

## Bandwidth Monitoring

On self-hosted proxy nodes a Vector.dev pipeline parses the access logs and ships bandwidth data to the API (`POST /api/internal/bandwidth`, secured by a shared internal secret), which writes per-video, per-IP usage rows to ClickHouse for analytics.
