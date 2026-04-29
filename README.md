# NukeVideo

> [!WARNING] This project is in early development. APIs, database schemas, and configuration may change without notice. Not recommended for production use yet.

Open-source, self-hosted video processing and delivery engine. Upload, encode, and serve video via adaptive bitrate streaming. Designed as a core API to integrate into your own backend.

## What it does

```
Upload → S3 → Webhook → Download → Encode → Upload → Stream
```

1. Video files are uploaded to S3 via multipart upload
2. S3 webhook triggers processing
3. Worker nodes download and encode based on templates (FFmpeg)
4. Processed streams are uploaded back to S3
5. Proxy nodes serve content via HLS/DASH with token-based access control

## Features

- **Multi-format encoding** — HLS, DASH, MP4 with customizable templates (codecs, bitrates, resolutions)
- **Distributed processing** — Scale by adding worker nodes; jobs auto-distribute to least busy nodes
- **GPU acceleration** — NVIDIA GPU support for hardware-accelerated encoding
- **Adaptive bitrate streaming** — Nginx VOD module with on-the-fly HLS/DASH packaging from S3
- **CDN mode** — Proxy nodes can operate behind Cloudflare or other CDNs with cache bypass
- **Consistent hashing** — Video-to-node routing maximizes cache hits across proxy nodes
- **S3-compatible storage** — AWS S3, MinIO, RustFS, or any S3-compatible service
- **Usage tracking** — Per-user metrics (upload volume, encoding time) in ClickHouse
- **Bandwidth analytics** — Real-time session tracking, top IPs, top videos
- **RESTful API** — Sanctum auth, webhook support, multipart uploads via Uppy
- **External system integration** — Track external user IDs and resource IDs for usage

## Tech stack

| Layer | Technology |
|-------|-----------|
| API | Laravel, PHP 8.3 |
| Frontend | Vue 3, TypeScript, Vite |
| Queue | Redis, Laravel Horizon |
| Encoding | FFmpeg (CPU + NVIDIA GPU) |
| Streaming | Nginx + Kaltura VOD module |
| Storage | S3 (AWS, MinIO, RustFS) |
| Analytics | ClickHouse |
| Logging | Vector.dev |
| Proxy | Traefik |
| Infra | Docker |

## Architecture

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Client     │────>│  Traefik    │────>│  Proxy Node │──> S3
│  (Browser)   │     │  (Router)   │     │  (Nginx VOD)│
└─────────────┘     └─────────────┘     └─────────────┘
                           │
                    ┌──────┴──────┐
                    │   API       │
                    │  (Laravel)  │
                    └──────┬──────┘
                           │
              ┌────────────┼────────────┐
              │            │            │
        ┌─────┴─────┐ ┌───┴───┐ ┌─────┴─────┐
        │  Worker 1  │ │ Redis │ │ ClickHouse│
        │  (FFmpeg)  │ │       │ │ (Analytics)│
        ├────────────┤ └───────┘ └───────────┘
        │  Worker N  │
        │  (FFmpeg)  │
        └────────────┘
```

## Quick start (development)

```bash
docker compose -f docker-compose.dev.yml up -d
docker compose -f docker-compose.dev.yml exec nukevideo-api php artisan migrate --seed
docker compose -f docker-compose.dev.yml exec nukevideo-api php artisan clickhouse:migrate
```

Default credentials: `test@example.com` / `password`