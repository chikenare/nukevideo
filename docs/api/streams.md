# Streams

Streams represent individual encoded tracks of a video (video, audio, muxed, or the original file).

Streams are created by the pipeline when a video is ingested, not through the API.

## Update Stream

Relabel an **audio or subtitle** track. The change is written to the video's already-published DASH
and HLS manifests in place, so it takes effect on the next player request without re-encoding or
re-packaging.

```
PUT /api/streams/{ulid}
```

**Request Body:**

```json
{
  "name": "Latin American Spanish",
  "language": "es-MX",
  "forced": false
}
```

| Field | Type | Notes |
|-------|------|-------|
| `name` | string | Shown in the player's track selector. Must be unique among the video's tracks of the same type, and cannot contain `,`, `"` or a line break. |
| `language` | string \| null | BCP-47 (`es`, `en-US`, `es-MX`). The packager normalizes it, so a manifest may show `en` for a source tagged `eng`. |
| `forced` | boolean | Subtitles only — marks a track that carries foreign-language dialogue. |

Returns the updated stream. Responds `400` for a video rendition, the original file, or a video
that is still being processed, and `422` when validation fails.

## Download a Track

Mint a signed URL for **one** track. Downloads are per-track by design: the encoded video
renditions carry no audio, so a single playable file does not exist on our side — you fetch the
tracks you want and mux them yourself (for example with `ffmpeg -i video.mp4 -i audio.mp4 -c copy
out.mp4`).

```
POST /api/streams/{ulid}/download
```

List a video's tracks first with `GET /api/videos/{ulid}`, then request a link per track you want.

**Request Body:**

```json
{ "tracking_id": "customer-42" }
```

| Field | Type | Notes |
|-------|------|-------|
| `tracking_id` | string \| null | Your own tracking id — a customer, a tenant. The bytes of the transfer land against it in the bandwidth analytics. Up to 64 characters of `A-Z a-z 0-9 _ -`. |

The id never enters the link, nor the response. The mint records the link's token against your id
server-side, and the CDN's access log — which carries the token on every request the link
produces — is attributed through it. The same link is minted
whoever asked for it, so treat the id as a label you chose, never as an authorization input. The
mapping is best-effort: it lives server-side for the token's lifetime plus a margin. Traffic with
no id is still recorded, under an empty one — except on a session or personal-token request, where
an omitted `tracking_id` defaults to the ULID of the authenticated user, so the panel's own
downloads stay attributed. A project key that omits it leaves the traffic unattributed: naming the
viewer is the integrator's job.

Read the bytes back per id with `topTrackingIds` on the
[Analytics API](/api/users#bandwidth-by-tracking-id), optionally narrowed to one video. The id is
attributed from the CDN's access log, so it lands **after** the transfer, not with the link: a
self-hosted edge reports within seconds, a Bunny pull zone within about seven minutes.

**Response:**

```json
{
  "data": {
    "url": "https://cdn.example.com/01J.../download/audio/01J....mp4?token=HS256-...&expires=1787709204",
    "expiresAt": "2026-08-13T02:41:24+00:00",
    "filename": "01KZW4GN1K3B7Y4RFQBGM0KQF6.mp4",
    "type": "audio",
    "size": 4779203
  }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `url` | string | Signed for **this object only** — a link to one rendition will not fetch another. Not bound to your IP, so it survives resumes and download managers. |
| `expiresAt` | string | ISO 8601. Validity is checked when the request starts, so a transfer already in flight is not cut off; a resume after this fails and needs a new link. |
| `filename` | string | The stored name — a ULID plus its extension. Unique per track, so fetching several never lands two on the same name. |
| `type` | string | `video`, `audio` or `subtitle`. |
| `size` | integer \| null | Bytes, when known. |

Responds `422` for the original file, `409` while the video is still processing, `404` when the
track was not retained (a template with `keepProcessedFiles` off discards the renditions before they
reach storage) or belongs to another project, and `503` when no delivery node is available.

## Delete Stream

```
DELETE /api/streams/{id}
```

## Stream Types

| Type | Description |
|------|-------------|
| `original` | The source file as uploaded |
| `video` | Video-only encoded track |
| `audio` | Audio-only encoded track |
| `subtitle` | Text track |

## Stream Properties

| Field | Type | Description |
|-------|------|-------------|
| `ulid` | string | Unique identifier |
| `name` | string | Display name |
| `type` | string | Stream type |
| `width` | integer | Video width in pixels |
| `height` | integer | Video height in pixels |
| `packageSize` | integer | Bytes of packaged CMAF segments |
| `fileSize` | integer | Bytes of the retained source or rendition file |
| `language` | string | Language code (e.g., `en`, `es-MX`) |
| `forced` | boolean | Forced subtitle track |
| `channels` | integer | Audio channels |
| `meta` | object | FFprobe metadata |
| `inputParams` | object | Encoding parameters |
| `errorLog` | string | Error details if the stream's concat job failed |
| `createdAt` | string | ISO 8601 timestamp |
