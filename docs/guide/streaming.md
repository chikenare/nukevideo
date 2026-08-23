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
| `tid` | string | Your own tracking id for this viewer — a customer, a campaign. On a self-hosted edge it becomes the first segment of the signed path (`/{tid}/{videoUlid}/play/...`), so every segment the player fetches carries it and the bytes land against it in the [bandwidth analytics](/api/users#bandwidth-by-tracking-id). Up to 64 characters of `A-Z a-z 0-9 _ -`. Bunny ignores it here: its directory token leaves no room for it, and segments would not carry it. |

## Token-Based Access Control

Playback URLs are signed with a time-limited token so segments can't be fetched without authorization:

- **Stream token** — Long-lived (configurable, e.g. 100 days) and scoped to a video's content, used for the manifest.
- **Query/segment token** — Short-lived (e.g. 1 hour) for the individual segment requests the player derives from the manifest.

The exact signing scheme depends on the delivery layer:

- **Proxy nodes** validate Akamai-style stream tokens (HMAC) and then read segments from S3 using AWS authentication.
- **Bunny CDN** uses HMAC-SHA256 tokens in directory mode: the token is a path prefix scoped to the video's directory, so the manifest and all of its relative segments authenticate under one token.

## Caching

The delivery layer caches CMAF **segments** locally (or at the CDN edge) so repeated requests don't hit S3 every time. **Manifests bypass the cache** to stay fresh. A self-hosted proxy node caches into its own disk pool, sized automatically — see [Nodes: Cache disks](/guide/nodes#cache-disks). A tracking id in the path costs no cache space: the edge strips it before the cache and the bucket, so one cached copy of a segment serves every id.

## Bandwidth Monitoring

On self-hosted proxy nodes a Vector.dev pipeline parses the access logs and ships bandwidth data to the API (`POST /api/internal/bandwidth`, secured by a shared internal secret), which writes per-video, per-IP usage rows to ClickHouse for analytics.
