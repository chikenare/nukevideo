# Videos

Manage video resources. Videos are created automatically when a file is uploaded via S3 and the webhook is triggered.

## List Videos

Returns a paginated list of videos for the authenticated user.

```
GET /api/videos
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | integer | Page number |
| `perPage` | integer | Items per page (default 15, maximum 100) |
| `search` | string | Matches against the video name |
| `externalUserId` | string | Exact match on the id supplied at upload |
| `externalResourceId` | string | Exact match on the id supplied at upload |
| `status` | string | One status, or several comma-separated (`completed,failed`): `pending`, `downloading`, `running`, `uploading`, `completed`, `failed`. An unknown value is a `422` |
| `sort` | string | `created_at` (default), `name`, `size`, `duration` or `status`. `size` is what the listing shows — package plus retained file bytes over every stream |
| `direction` | string | `desc` (default) or `asc` |

**Response:**

The pagination fields sit alongside `data`, not inside a `meta` object, and — as everywhere in
this API — the payload is camelCase. Each row is a full video, `outputs` and `streams` included
(they are elided below for brevity, not empty in the response), so a listing page carries every
field [Get Video](#get-video) returns. That is why `perPage` is capped at 100.

```json
{
  "data": [
    {
      "ulid": "01HX...",
      "name": "my-video.mp4",
      "status": "completed",
      "duration": 120.5,
      "aspectRatio": "16:9",
      "createdAt": "2025-01-15T10:30:00+00:00",
      "externalUserId": "user-42",
      "externalResourceId": "movie-7",
      "thumbnailUrl": "https://api.example.com/api/videos/01HX.../thumbnail.jpg",
      "storyboardUrl": "https://api.example.com/api/videos/01HX.../storyboard.vtt",
      "outputs": ["..."],
      "streams": ["..."],
      "size": 812739584,
      "servedSize": 734003200
    }
  ],
  "currentPage": 1,
  "perPage": 15,
  "total": 72
}
```

## Get Video

```
GET /api/videos/{ulid}
```

Returns a single video with its streams and outputs. The object is exactly the one the listing
returns for each row, and the one a [webhook](/api/webhooks#event-payload) carries in its `data`.

**Response:**

```json
{
  "data": {
    "ulid": "01HX...",
    "name": "my-video.mp4",
    "status": "completed",
    "duration": 120.5,
    "aspectRatio": "16:9",
    "createdAt": "2025-01-15T10:30:00+00:00",
    "externalUserId": "user-42",
    "externalResourceId": "movie-7",
    "thumbnailUrl": "https://cdn.example.com/01HX.../assets/thumbnail.jpg",
    "storyboardUrl": "https://cdn.example.com/01HX.../assets/storyboard.vtt",
    "size": 812739584,
    "servedSize": 734003200,
    "outputs": [
      {
        "ulid": "01HZ...",
        "formats": ["hls", "dash"],
        "status": "completed",
        "progress": 100,
        "createdAt": "2025-01-15T10:31:02+00:00",
        "streams": [ "...the subset of `streams` this output packages..." ]
      }
    ],
    "streams": [
      {
        "ulid": "01HY...",
        "name": null,
        "type": "video",
        "packageSize": 52428800,
        "fileSize": 51380224,
        "inputParams": { "video_codec": "h264", "target_vmaf": 93 },
        "meta": {},
        "width": 1920,
        "height": 1080,
        "language": null,
        "forced": false,
        "hearingImpaired": false,
        "channels": null,
        "errorLog": null,
        "createdAt": "2025-01-15T10:31:02+00:00"
      }
    ]
  }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `duration` | number | Seconds. `0` until the source has been probed. |
| `aspectRatio` | string | Empty until the source has been probed. |
| `thumbnailUrl` / `storyboardUrl` | string | Served unsigned from the CDN when a delivery node is available, and from `GET /api/videos/{ulid}/{filename}` otherwise. The URL is stable, but the object only exists once the video has been processed. |
| `size` | integer | Every stream's `packageSize` plus `fileSize` — the whole S3 footprint. |
| `servedSize` | integer | Only `packageSize`, i.e. the bytes that serve playback. `size` minus this is retained source and processed-file overhead. |
| `outputs[].formats` | string[] | Plural: one package serves both `hls` and `dash`. |
| `outputs[].progress` | integer | 0-100, averaged over the encoded chunks. Live only while the output is encoding: it reads `100` as soon as the output is `completed`, and `0` once the progress data has expired. Treat it as a value to poll, never as one to store. |
| `outputs[].streams` | object[] | The subset of the video's streams this output packages. Streams are **shared** between outputs when their parameters resolve identically, so the same stream can appear under several outputs. |

Streams follow the [Stream Properties](/api/streams#stream-properties) table. Note that
`inputParams` and `meta` are raw JSON columns passed through as stored, so **their keys are
snake_case** (`video_codec`, `audio_codec`, `target_vmaf`) while every other field is camelCase.
`hearingImpaired` is the exception that is lifted out of `meta` for you.

## Update Video

```
PUT|PATCH /api/videos/{ulid}
```

**Request Body:**

```json
{
  "name": "Updated Video Name",
  "externalUserId": "user-123",
  "externalResourceId": "post-456"
}
```

| Field | Type | Description |
| --- | --- | --- |
| `name` | string | Required. |
| `externalUserId` | string\|null | Optional. Send `null` to clear it. |
| `externalResourceId` | string\|null | Optional. Send `null` to clear it. |

Responds with `message` and the full updated video under `data`, in the same shape as
[Get Video](#get-video).

## Delete Video

Deletes the video, its streams, outputs, and associated S3 files.

```
DELETE /api/videos/{ulid}
```

## Playback URLs

Playback links are minted **per output**, not per video — a video with several outputs has one
manifest family per output, and the ladder cap and format are chosen at mint time:

```
POST /api/outputs/{ulid}
```

Take the output ULID from this video's `outputs[]`. See
[Streaming & VOD](/guide/streaming#requesting-a-playback-url) for the request body and the token
model.

## Download Tracks

Mint signed URLs for a video's tracks in **one** request. Prefer this over calling
[Download a Track](/api/streams#download-a-track) per stream: everything a mint needs besides the
signature itself — your authentication, the project lookup, the video's status, the delivery node —
belongs to the video, not to the track, so asking per track pays for all of it again each time. A
video with several renditions, audio languages and subtitles is one request here and a dozen there.

```
POST /api/videos/{ulid}/downloads
```

**Request Body:**

```json
{
  "streamUlids": ["01KZW4GN1K3B7Y4RFQBGM0KQF6"],
  "trackingId": "customer-42"
}
```

| Field | Type | Notes |
|-------|------|-------|
| `streamUlids` | string[] \| null | Which tracks to mint. **Omit it to get every downloadable track of the video**, which is the usual call. An empty array is taken literally and returns nothing. Up to 200 entries. |
| `trackingId` | string \| null | Your own tracking id, applied to every link in the batch. Same rules and same behaviour as the per-track mint. |

The links are the same ones the per-track endpoint returns, signed per object and expiring on the
same window. Tracks that could not be minted do not fail the request — they come back in `skipped`
with a reason, so one stale id in a list does not cost the rest their links.

**Response:**

```json
{
  "data": {
    "links": [
      {
        "url": "https://cdn.example.com/01J.../download/audio/01J....mp4?token=HS256-...&expires=1787709204",
        "expiresAt": "2026-08-13T02:41:24+00:00",
        "filename": "01KZW4GN1K3B7Y4RFQBGM0KQF6.mp4",
        "type": "audio",
        "size": 4779203
      }
    ],
    "skipped": [
      { "ulid": "01KZW4GN1K3B7Y4RFQBGM0KQF7", "reason": "not_retained" }
    ]
  }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `links` | object[] | One entry per minted track, in the order you asked for them, or in the video's own track order when `streamUlids` was omitted. Same fields as the per-track response. |
| `skipped` | object[] | One entry per track that produced no link, with the `ulid` you sent and a `reason`. |

| `reason` | Meaning |
|----------|---------|
| `not_retained` | The template has `keepProcessedFiles` off, so the track was discarded before it reached storage. |
| `not_downloadable` | The untouched original. It lives in its own zone and is never handed out. Only ever returned for a ULID you named explicitly. |
| `not_found` | This video has no such track — a stale id, or one belonging to another video. |

Responds `409` while the video is still processing, `503` when no delivery node is available, `404`
when the video belongs to another project, and `422` when validation fails. The first two are
conditions of the whole video, so they fail the request rather than appearing in `skipped`.

## Video Statuses

`video.status` reflects the ingestion pipeline phase. Check individual `outputs[].status` to see which encodings succeeded or failed.

| Status | Description |
|--------|-------------|
| `pending` | Uploaded, waiting to be dispatched |
| `downloading` | Source file being fetched |
| `running` | Segmentation and encoding in progress |
| `uploading` | Encoded chunks being assembled and uploaded to S3 |
| `completed` | All outputs have reached a terminal state (at least one completed) |
| `failed` | Ingestion pipeline failed before any output could be produced |

## Output Statuses

Each output tracks its own encoding independently. A video can have some outputs `completed` and others `failed` at the same time — failed outputs can be deleted by the user while the video continues to function with the remaining completed outputs.

| Status | Description |
|--------|-------------|
| `pending` | Waiting for the encode batch to start |
| `running` | Chunks being encoded and uploaded |
| `completed` | Final file assembled and available in S3 |
| `failed` | Concat or upload failed; output is unusable |

## Upload Flow

Videos are not created via a direct `POST` endpoint. Instead:

1. Initiate a multipart upload to S3 (see [S3 Upload](#s3-multipart-upload)).
2. S3 triggers a webhook when the upload completes.
3. NukeVideo creates the video record and starts processing.

## S3 Multipart Upload

These endpoints coordinate multipart uploads with S3:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/s3/params` | Get upload parameters |
| `POST` | `/api/s3/multipart` | Create multipart upload |
| `GET` | `/api/s3/multipart/{uploadId}` | Get uploaded parts |
| `GET` | `/api/s3/multipart/{uploadId}/{partNumber}` | Sign a part |
| `POST` | `/api/s3/multipart/{uploadId}/complete` | Complete upload |
| `DELETE` | `/api/s3/multipart/{uploadId}` | Abort upload |

### Upload Metadata

When creating an upload, you can pass optional metadata to associate the video with external systems:

```json
{
  "filename": "video.mp4",
  "metadata": {
    "template": "01ABC...",
    "externalUserId": "user-123",
    "externalResourceId": "post-456"
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `metadata.template` | string | **Required.** Template ULID to use for processing |
| `metadata.externalUserId` | string | Optional. ID of the user in your external system |
| `metadata.externalResourceId` | string | Optional. ID of the resource (post, product, etc.) in your external system |

These fields are stored on the video record and returned in API responses as `externalUserId` and `externalResourceId`. The `externalUserId` is also recorded in usage tracking, allowing you to query per-user metrics via the [Usage API](/api/analytics#usage).
