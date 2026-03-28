# Streams

Streams represent individual encoded tracks of a video (video, audio, muxed, or the original file).

## Create Stream

Add a new stream to a video.

```
POST /api/streams
```

**Request Body:**

```json
{
  "video_id": 1,
  "name": "720p",
  "type": "video",
  "input_params": {
    "codec": "h264",
    "width": 1280,
    "height": 720,
    "bitrate": "2500k"
  }
}
```

## Update Stream

```
PUT /api/streams/{id}
```

**Request Body:**

```json
{
  "name": "720p Updated",
  "input_params": { ... }
}
```

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
| `muxed` | Combined video + audio track |

## Stream Statuses

| Status | Description |
|--------|-------------|
| `pending` | Waiting to be processed |
| `running` | Currently encoding |
| `completed` | Successfully encoded |
| `failed` | Encoding failed (check `error_log`) |

## Stream Properties

| Field | Type | Description |
|-------|------|-------------|
| `ulid` | string | Unique identifier |
| `video_id` | integer | Parent video ID |
| `name` | string | Display name |
| `type` | string | Stream type |
| `status` | string | Processing status |
| `width` | integer | Video width in pixels |
| `height` | integer | Video height in pixels |
| `size` | integer | File size in bytes |
| `language` | string | Language code (e.g., `en`, `es`) |
| `channels` | integer | Audio channels |
| `progress` | integer | Encoding progress (0-100) |
| `meta` | object | FFprobe metadata |
| `input_params` | object | Encoding parameters |
| `error_log` | string | Error details (if failed) |
| `started_at` | datetime | When encoding started |
| `completed_at` | datetime | When encoding finished |
