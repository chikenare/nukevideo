# Video Processing

NukeVideo uses FFmpeg to transcode uploaded videos into multiple streams and formats. The entire pipeline is queue-based and runs on distributed worker nodes.

## Pipeline Overview

When a video is uploaded, the following jobs execute in order:

```
OnVideoUploaded
  └── Batch
       ├── DownloadOriginalFileJob
       ├── ProcessStreamJob (× N streams)
       ├── ExtractThumbnailJob
       ├── GenerateVideoStoryboard
       └── ProcessSubtitlesJob
            └── Then
                 ├── UploadStreamJob (× N streams)
                 └── CleanupVideoResourcesJob
```

## Video Statuses

A video goes through these statuses during processing:

| Status | Description |
|--------|-------------|
| `pending` | Video uploaded, waiting to be processed |
| `running` | Processing is in progress |
| `completed` | All streams encoded and uploaded |
| `failed` | One or more streams failed to process |

## Stream Types

Each video can have multiple streams:

| Type | Description |
|------|-------------|
| `original` | The source file as uploaded |
| `video` | Encoded video-only track |
| `audio` | Encoded audio-only track |
| `muxed` | Combined video + audio track |

## Processing Steps

### 1. Download Original

The worker downloads the original file from S3 to its local temporary storage. This is idempotent — if the file already exists locally, the download is skipped.

### 2. Probe & Create Streams

The original file is probed with FFprobe to extract metadata (duration, resolution, codecs, audio tracks). Based on the video's template configuration, stream records are created in the database.

### 3. Encode Streams

Each stream is processed by FFmpeg according to its parameters:
- **Video streams** — Transcoded with specified codec, resolution, bitrate, and profile.
- **Audio streams** — Extracted and encoded with specified codec and bitrate.
- **Muxed streams** — Combined video + audio in a single file.

Progress is tracked in real-time and broadcast via WebSockets.

### 4. Extract Thumbnail

A thumbnail is extracted at 30% of the video duration and uploaded to S3. The video record is updated with the thumbnail path.

### 5. Generate Storyboard

Sprite sheets are created for seek preview:
- Frames extracted at 10-second intervals.
- Arranged in a 10×10 grid (100 thumbnails per sprite).
- A WebVTT file is generated with coordinates for each frame.

### 6. Process Subtitles

Subtitle tracks are extracted from the original file and converted to WebVTT format. Each subtitle stream is uploaded to S3.

### 7. Upload & Cleanup

Processed streams are uploaded to S3. Once all streams are complete, the video status changes to `completed` and temporary files are cleaned up.

## Workload Distribution

Videos are assigned to worker nodes based on their source resolution:

| Workload | Resolution | Example |
|----------|-----------|---------|
| `light` | < 720p | 480p, 360p |
| `medium` | 720p – 1080p | 720p, 1080p |
| `heavy` | > 1080p | 1440p, 4K |

The system selects the **least busy** worker node that supports the required workload. If no matching node is available, it falls back to nodes with higher-capacity workloads (e.g., a `medium` video can run on a `heavy` node).

## Error Handling

- If a stream fails, it is marked as `failed` with the error logged.
- If any stream in the batch fails, the entire video is marked as `failed`.
- The `OnVideoUploaded` job uses exponential backoff (30s, 60s, 120s, 5m, 10m) and retries for up to 6 hours.
- On final failure, temporary files are cleaned up automatically.
