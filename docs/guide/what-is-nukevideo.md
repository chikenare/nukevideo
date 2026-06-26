# What is NukeVideo?

NukeVideo is an open-source, self-hosted video processing and delivery engine. It handles the core pipeline — uploading, encoding, and serving video via adaptive bitrate streaming — so you can integrate it into your own backend.

## Why NukeVideo?

Most video solutions are either expensive SaaS products or full-blown platforms tightly coupled to their own frontend. NukeVideo takes a different approach: it provides just the video engine — upload, process, deliver — and stays out of the way so you can build your own product on top:

- **Self-hosted** — Your data stays on your servers.
- **Scalable** — Add worker and proxy nodes as your needs grow.
- **Flexible** — Define custom encoding templates for any use case.
- **Open source** — Inspect, modify, and contribute to the codebase.

## Key Features

### Video Processing Pipeline
Upload a video and NukeVideo handles the rest — downloading the original, transcoding into multiple formats, extracting thumbnails, generating storyboards, and uploading processed files to S3 storage.

### Encoding Templates
Define reusable encoding configurations with custom video/audio streams, resolutions, bitrates, and codecs. Apply them to any video with a single API call.

### Distributed Architecture
NukeVideo supports two types of nodes:
- **Worker nodes** — Handle video encoding jobs distributed by workload (light, medium, heavy).
- **Proxy nodes** — Serve video content via Nginx VOD module with adaptive bitrate streaming.

### Streaming & VOD
Deliver content in HLS, DASH, or direct MP4 with secure token validation and S3-backed storage. The Nginx VOD module handles on-the-fly packaging without pre-segmenting files.

### Analytics & Monitoring
Track bandwidth consumption, encoding progress, and node health through ClickHouse-powered analytics.


## How It Works

```
Upload → S3 → Webhook → Download → Encode → Upload → Stream
```

1. A video file is uploaded to S3 storage via multipart upload.
2. A webhook notifies NukeVideo that a new file is ready.
3. A worker node downloads the original and starts encoding based on the template.
4. Processed streams (video, audio, subtitles) are uploaded back to S3.
5. Proxy nodes serve the content via HLS/DASH with token-based access control.
