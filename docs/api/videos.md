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
| `per_page` | integer | Items per page |

**Response:**

```json
{
  "data": [
    {
      "id": 1,
      "ulid": "01HX...",
      "name": "my-video.mp4",
      "status": "completed",
      "duration": 120.5,
      "aspect_ratio": "16:9",
      "thumbnail_path": "videos/01HX.../thumbnail.jpg",
      "template_id": 1,
      "streams": [...],
      "created_at": "2025-01-15T10:30:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 72
  }
}
```

## Get Video

```
GET /api/videos/{ulid}
```

Returns a single video with its streams and outputs.

**Response:**

```json
{
  "data": {
    "id": 1,
    "ulid": "01HX...",
    "name": "my-video.mp4",
    "status": "completed",
    "duration": 120.5,
    "aspect_ratio": "16:9",
    "thumbnail_path": "videos/01HX.../thumbnail.jpg",
    "template": { ... },
    "streams": [
      {
        "ulid": "01HY...",
        "type": "video",
        "status": "completed",
        "width": 1920,
        "height": 1080,
        "size": 52428800,
        "progress": 100
      }
    ],
    "outputs": [
      {
        "ulid": "01HZ...",
        "format": "hls",
        "streams": [...]
      }
    ]
  }
}
```

## Update Video

```
PUT /api/videos/{ulid}
```

**Request Body:**

```json
{
  "name": "Updated Video Name",
  "template_id": 2
}
```

## Delete Video

Deletes the video, its streams, outputs, and associated S3 files.

```
DELETE /api/videos/{ulid}
```

## Get Video Sources

Returns signed streaming URLs for the video. Requires an active proxy node.

```
GET /api/videos/{ulid}/sources
```

**Response:**

```json
{
  "sources": [
    {
      "format": "hls",
      "url": "https://proxy.example.com/hls/..."
    },
    {
      "format": "dash",
      "url": "https://proxy.example.com/dash/..."
    },
    {
      "format": "mp4",
      "url": "https://proxy.example.com/proxy/..."
    }
  ]
}
```

## Video Statuses

| Status | Description |
|--------|-------------|
| `pending` | Uploaded, waiting for processing |
| `running` | Encoding in progress |
| `completed` | All streams processed |
| `failed` | Processing failed |

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

These fields are stored on the video record and returned in API responses as `externalUserId` and `externalResourceId`. The `externalUserId` is also recorded in usage tracking, allowing you to query per-user metrics via the [Usage API](/api/users#usage).
