# Streams

Streams represent individual encoded tracks of a video (video, audio, subtitle, or the original file).

Streams are created by the pipeline when a video is ingested, not through the API.

## Update Stream

Relabel an **audio or subtitle** track. The change is written to the video's already-published DASH
and HLS manifests in place, so it takes effect on the next player request without re-encoding or
re-packaging.

```
PUT|PATCH /api/streams/{ulid}
```

**Request Body:**

```json
{
  "name": "Latin American Spanish",
  "language": "es-MX",
  "forced": false,
  "hearingImpaired": false
}
```

| Field | Type | Notes |
|-------|------|-------|
| `name` | string | **Required.** Shown in the player's track selector. Must be unique among the video's tracks of the same type, and cannot contain `,`, `"` or a line break. |
| `language` | string \| null | **Required**, but nullable. BCP-47 (`es`, `en-US`, `es-MX`), and it must be a language that exists — the packager normalizes it, so a manifest may show `en` for a source tagged `eng`. |
| `forced` | boolean | **Required.** Subtitles only — marks a track that carries foreign-language dialogue. |
| `hearingImpaired` | boolean \| null | Optional, **subtitles only** — a `true` on an audio track is a `422`, because audio SDH is baked into the packaged manifests. Omit it (or send `null`) to keep whatever the probe or a previous edit set. Stored in the stream's `meta`, and returned lifted out of it as `hearingImpaired`. |

`name`, `language` and `forced` are required on every call, `PATCH` included — omitting one is a
`422`, not a field left untouched. Read the track first and send all three back. `hearingImpaired`
is the only field that is genuinely optional.

Returns `message` and the updated stream under `data`. Responds `400` for a video rendition, the
original file, or a video that is still being processed, and `422` when validation fails.

## Download a Track

Mint a signed URL for **one** track. Downloads are per-track by design: the encoded video
renditions carry no audio, so a single playable file does not exist on our side — you fetch the
tracks you want and mux them yourself (for example with `ffmpeg -i video.mp4 -i audio.mp4 -c copy
out.mp4`).

```
POST /api/streams/{ulid}/download
```

List a video's tracks first with `GET /api/videos/{ulid}`, then request a link per track you want.

For more than one track of the same video, use
[Download Tracks](/api/videos#download-tracks) instead — it mints them all in one request, and
the per-request work this endpoint repeats is per video rather than per track.

**Request Body:**

```json
{ "trackingId": "customer-42" }
```

| Field | Type | Notes |
|-------|------|-------|
| `trackingId` | string \| null | Your own tracking id — a customer, a tenant. The bytes of the transfer land against it in the bandwidth analytics. Up to 64 characters of `A-Z a-z 0-9 _ -`. |

The id never enters the link, nor the response. The mint records the link's token against your id
server-side, and the CDN's access log — which carries the token on every request the link
produces — is attributed through it. The same link is minted
whoever asked for it, so treat the id as a label you chose, never as an authorization input. The
mapping is best-effort: it lives server-side for the token's lifetime plus a margin. Traffic with
no id is still recorded, under an empty one — except on a session or personal-token request, where
an omitted `trackingId` defaults to the ULID of the authenticated user, so the panel's own
downloads stay attributed. A project key that omits it leaves the traffic unattributed: naming the
viewer is the integrator's job.

Read the bytes back per id with `topTrackingIds` on the
[Analytics API](/api/analytics#bandwidth-by-tracking-id), optionally narrowed to one video. The id is
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

Removes one track from an already-packaged video: the CMAF segments are deleted and the track is
edited out of the published manifests, so playback stops offering it without a re-encode.

```
DELETE /api/streams/{ulid}
```

Responds `400` while the video is still processing, and `400` when the track is the last video
rendition of any output — that would leave the output's published manifests with no video at all.
Deleting a track does **not** notify your webhook, so a mirrored copy of the video has to be
refreshed from the response or from [Get Video](/api/videos#get-video).

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
| `ulid` | string | Unique identifier. Not stable across a re-probe: `videos:retry --reprobe` drops the derived streams and mints new ULIDs. |
| `name` | string \| null | Display name. `null` on video renditions — they carry no label anywhere, the panel shows their height instead. |
| `type` | string | `original`, `video`, `audio` or `subtitle` |
| `width` | integer \| null | Video width in pixels |
| `height` | integer \| null | Video height in pixels |
| `packageSize` | integer \| null | Bytes of the packaged CMAF segments — what serves playback. `null` on the `original`, which is never packaged. |
| `fileSize` | integer \| null | Bytes of the retained rendition file, i.e. "there is a file to fetch". `null` when the template has `keepProcessedFiles` off, because the renditions are discarded before the sync and never reach storage — so it is `null` from the start rather than becoming `null` later. This is the field that decides whether a track is downloadable. |
| `language` | string \| null | Language code (e.g., `en`, `es-MX`) |
| `forced` | boolean | Forced subtitle track |
| `hearingImpaired` | boolean | SDH track. Stored inside `meta`, lifted out here for you. |
| `channels` | integer \| null | Audio channels |
| `meta` | object \| null | FFprobe metadata. Raw JSON: **snake_case keys**. |
| `inputParams` | object \| null | Encoding parameters (`video_codec`, `audio_codec`, `target_vmaf`, …). Raw JSON: **snake_case keys**. |
| `errorLog` | string \| null | Error details if the stream's encode failed |
| `createdAt` | string | ISO 8601 timestamp |

`packageSize` and `fileSize` answer two different questions and are never summed per stream: the
video's totals are `servedSize` (packaged bytes) and `size` (both), on
[the video itself](/api/videos#get-video).
