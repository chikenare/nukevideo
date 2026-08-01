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
