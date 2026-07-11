# What is NukeVideo?

NukeVideo is an open-source, self-hosted video processing and delivery engine. It handles the core pipeline — uploading, encoding, packaging, and serving video via adaptive bitrate streaming — so you can integrate it into your own backend.

## Why NukeVideo?

Most video solutions are either expensive SaaS products or full-blown platforms tightly coupled to their own frontend. NukeVideo takes a different approach: it provides just the video engine — upload, process, deliver — and stays out of the way so you can build your own product on top:

- **Self-hosted** — Your data stays on your servers.
- **Scalable** — Add worker and proxy nodes as your needs grow.
- **Flexible** — Define custom encoding templates for any use case.
- **Open source** — Inspect, modify, and contribute to the codebase.

## Architecture at a Glance

- **Backend** — Laravel (PHP 8.5). Production runs FrankenPHP + Laravel Octane; queues run on Laravel Horizon (Redis).
- **Admin panel** — Vue 3 + TypeScript SPA, served by nginx in production.
- **Databases** — MariaDB 11 for application data, ClickHouse for bandwidth and usage analytics.
- **Storage** — Any S3-compatible service (AWS S3, MinIO, RustFS, iDrive e2). Transfers use s5cmd.
- **Auth** — Laravel Sanctum (SPA cookie sessions plus API bearer tokens).

## Key Features

### Distributed Encoding Pipeline
Upload a video and NukeVideo handles the rest — mirroring the original, splitting it into chunks, and encoding those chunks in parallel across worker nodes with FFmpeg (SVT-AV1, x264/x265). Audio is transcoded to AAC, and thumbnails and storyboards are generated.

### Per-Title VMAF-Based CRF
Rather than using a fixed quality target for every video, the pipeline probes sample windows of the source, measures VMAF, and interpolates the CRF needed to hit a target VMAF per rendition — with a maxrate clamp to the scaled source bitrate. Simpler sources use fewer bits; complex ones get what they need.

### Static CMAF Packaging
Encoded output is packaged **once** by shaka-packager into static CMAF: each output produces shared segments that serve **both HLS and DASH** from the same files. Subtitles are packaged as CMAF too. There is no on-the-fly repackaging — the manifests and segments are prepared ahead of time and stored on S3.

### Encoding Templates
Define reusable encoding configurations with custom video/audio streams, resolutions, bitrates, and codecs. Apply them to any video with a single API call.

### Flexible Delivery
Choose per deployment how packaged content reaches viewers:

- **Self-hosted proxy nodes** — A custom nginx build that validates Akamai-style stream tokens, serves the pre-packaged CMAF from S3 with AWS auth, and caches segments locally (manifests bypass the cache).
- **Bunny CDN** — A Bunny pull-zone with HMAC token authentication that pulls directly from your S3 origin, with no proxy nodes to operate.

See [CDN & Delivery](/guide/cdn) for a full comparison.

### Analytics & Monitoring
Track bandwidth consumption and per-video usage through ClickHouse-powered analytics, and monitor encoding progress and node health from the admin panel.

## How It Works

```
Upload → S3 → Webhook → Encode (chunked) → Package (CMAF) → S3 → Deliver
```

1. A video file is uploaded to S3 via multipart upload (Uppy, client-side signing).
2. A webhook notifies NukeVideo that a new file is ready.
3. Worker nodes probe the source (per-title VMAF), split it into chunks, and encode them in parallel.
4. shaka-packager builds static CMAF (shared HLS + DASH segments, plus subtitles) and uploads it to S3.
5. Content is delivered via self-hosted proxy nodes or Bunny CDN, both gated by token-based access control.
