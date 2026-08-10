# Webhooks

NukeVideo uses webhooks to receive notifications when video files are uploaded to S3 storage.

## Video Uploaded

Triggered when a video file upload to S3 is complete.

```
POST /webhooks/video-uploaded
```

### Authentication

Every request is authenticated against the `WEBHOOK_SECRET` environment variable, in one of two
forms. If `WEBHOOK_SECRET` is unset, both forms are rejected.

**Bearer token** — the generic form, and the one to use for any bucket that lets you set an
arbitrary header:

```
Authorization: Bearer <WEBHOOK_SECRET>
```

**HMAC signature** — for object stores that sign their notifications themselves. The value is the
base64-encoded SHA-256 HMAC of the raw request body, keyed with `WEBHOOK_SECRET`:

```
x-e2-notification-signature: <base64(hmac_sha256(body, WEBHOOK_SECRET))>
```

When the signature header is present it is the one checked, and a bad signature is rejected
outright rather than falling back to the bearer token.

### Request Headers

| Header | Description |
|--------|-------------|
| `Authorization` | `Bearer <WEBHOOK_SECRET>` — the generic form |
| `x-e2-notification-signature` | base64 HMAC-SHA256 of the body; takes precedence when sent |

### Request Body

The webhook payload contains information about the uploaded file, including:
- File path in S3
- User ID
- Template ID
- File metadata

### Processing

When a valid webhook is received, NukeVideo:

1. Validates the webhook signature.
2. Creates a video record in the database.
3. Dispatches the `OnVideoUploaded` job to start processing.
4. The job probes the file, creates stream records, and dispatches the encoding batch.

### Retry Behavior

If the initial processing fails, the `OnVideoUploaded` job retries with exponential backoff:

| Attempt | Delay |
|---------|-------|
| 1 | 30 seconds |
| 2 | 60 seconds |
| 3 | 120 seconds |
| 4 | 5 minutes |
| 5 | 10 minutes |

The job will retry for up to **6 hours** before being marked as failed.

### Failure Handling

On final failure, the system:
- Marks the video as `failed`.
- Cleans up any temporary files.
- Logs the error for investigation.

## Configuring Webhooks

Set the webhook secret in your `.env` file:

```env
WEBHOOK_SECRET=your_secure_random_string
```

When configuring your S3 provider to send webhooks, point the notification URL to:

```
https://api.yourdomain.com/webhooks/video-uploaded
```

Ensure the webhook secret matches between your S3 configuration and the NukeVideo `.env` file.
