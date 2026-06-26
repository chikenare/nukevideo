# Video Processing

NukeVideo uses FFmpeg to transcode uploaded videos into multiple streams and formats. The entire pipeline is queue-based and runs on distributed worker nodes.

## Pipeline Overview

When a video is uploaded, the following jobs execute in order:

```
OnVideoUploaded (DOWNLOADING → RUNNING)
  └── SegmentVideoJob
       ├── ExtractThumbnailJob
       ├── GenerateVideoStoryboard
       └── Bus::batch — one chain per chunk window (RUNNING)
            ├── ProcessChunkJob  (encodes all streams for that window)
            └── UploadChunkJob × N streams
                 └── .then() — fires once all chunks complete (UPLOADING)
                      └── ConcatenateVideoChunksJob × N streams
                           └── (each output settles independently → COMPLETED / FAILED)
```

## Video Statuses

`video.status` tracks the ingestion pipeline phase. Output-level results are tracked independently on each `output`.

| Status | Description |
|--------|-------------|
| `pending` | Video uploaded, waiting to be dispatched |
| `downloading` | Source file being fetched from S3 |
| `running` | Segmentation and chunk encoding in progress |
| `uploading` | Encoded chunks being concatenated and uploaded |
| `completed` | All outputs have settled (at least one completed) |
| `failed` | Ingestion pipeline failed before outputs could be produced |

## Output Statuses

Each output (HLS, DASH, etc.) settles independently. A video can have a mix of `completed` and `failed` outputs simultaneously.

| Status | Description |
|--------|-------------|
| `pending` | Waiting for encode batch |
| `running` | Chunks encoding in progress |
| `completed` | Final file in S3, ready for playback |
| `failed` | Concat or upload failed; can be deleted by the user |

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

### 3. Encode Chunks

The video is divided into keyframe-aligned windows. For each window, a single `ProcessChunkJob` runs FFmpeg to encode **all streams at once**, producing one output file per stream. The encode batch fans out across available workers, with `UploadChunkJob` per stream handling upload to the chunk store independently so each can retry on transient errors.

Encoding progress is tracked per output in Redis and exposed via `output.progress` (0–100).

### 4. Extract Thumbnail

A thumbnail is extracted at 30% of the video duration and uploaded to S3. The video record is updated with the thumbnail path.

### 5. Generate Storyboard

Sprite sheets are created for seek preview:
- Frames extracted at 10-second intervals.
- Arranged in a 10×10 grid (100 thumbnails per sprite).
- A WebVTT file is generated with coordinates for each frame.

### 6. Process Subtitles

Subtitle tracks are extracted from the original file and converted to WebVTT format. Each subtitle stream is uploaded to S3.

### 7. Concatenate & Settle

Once all chunks are uploaded, `ConcatenateVideoChunksJob` runs per stream outside the main batch. Each job concatenates the chunks for one stream and uploads the final file to S3. When all streams of an output are in S3, that output is marked `completed`. If a concat job fails permanently, only the outputs that depend on that stream are marked `failed` — other outputs continue unaffected.

Once every output has reached a terminal state (`completed` or `failed`), the video is marked `completed` (if at least one output succeeded) or `failed` (if all outputs failed), and temporary files are cleaned up.

## Workload Distribution

Videos are assigned to worker nodes based on their source resolution:

| Workload | Resolution | Example |
|----------|-----------|---------|
| `light` | < 720p | 480p, 360p |
| `medium` | 720p – 1080p | 720p, 1080p |
| `heavy` | > 1080p | 1440p, 4K |

The system selects the **least busy** worker node that supports the required workload. If no matching node is available, it falls back to nodes with higher-capacity workloads (e.g., a `medium` video can run on a `heavy` node).

## Error Handling

Failures are scoped to the phase where they occur:

- **Ingestion failure** (download, segmentation, or encode batch) — The whole video is marked `failed` and all outputs are marked `failed`, since no output can be produced without a complete set of encoded chunks.
- **Concat failure** (`ConcatenateVideoChunksJob`) — Only the outputs that depend on the failed stream are marked `failed`. Other outputs continue and can complete normally. The user can delete failed outputs while the video continues to function with the remaining ones.
- **All outputs failed** — The video is marked `failed` and the webhook fires `video.error`.
- The `OnVideoUploaded` job uses exponential backoff (30s, 60s, 120s, 5m, 10m) and retries for up to 6 hours before the video is marked `failed`.
- On final failure, temporary local and chunk-store files are cleaned up automatically.
