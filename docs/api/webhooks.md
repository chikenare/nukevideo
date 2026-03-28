# Webhooks

NukeVideo uses webhooks to receive notifications when video files are uploaded to S3 storage.

## Video Uploaded

Triggered when a video file upload to S3 is complete.

```
POST /webhooks/video-uploaded
```

### Authentication

The webhook must include a valid signature. The signature is verified using the `WEBHOOK_SECRET` environment variable.

### Request Headers

| Header | Description |
|--------|-------------|
| `X-Webhook-Signature` | HMAC signature for request verification |

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
