# Getting Started

This guide walks you through running NukeVideo locally for development with Docker.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) and Docker Compose
- [Git](https://git-scm.com/)

The local stack is defined in `compose.yml`. It uses Traefik to route every service under `*.nukevideo.localhost`, which requires an **external** Docker network named `traefik` to exist first.

## Quick Start

```bash
git clone https://github.com/chikenare/nukevideo.git
cd nukevideo

# Traefik routes all services; this external network must exist first
docker network create traefik

cp .env.example .env
docker compose up -d --build

# Application setup (run inside the API container)
docker compose exec nukevideo-api php artisan key:generate
docker compose exec nukevideo-api php artisan migrate --seed
docker compose exec nukevideo-api php artisan clickhouse:migrate
```

## Default Login

The seeder creates an admin user:

- **Email:** `test@example.com`
- **Password:** `password`

Sign in at the admin SPA (see the URL below).

## Local URLs

Traefik publishes each service under a `*.nukevideo.localhost` hostname (these resolve automatically, no `/etc/hosts` edits needed):

| Service | URL |
|---------|-----|
| API (Laravel) | http://api.nukevideo.localhost |
| Admin SPA | http://app.nukevideo.localhost |
| Docs | http://docs.nukevideo.localhost |
| Adminer (DB UI) | http://adminer.nukevideo.localhost |
| ClickHouse UI | http://ch.nukevideo.localhost |
| RustFS S3 API | http://s3-data.nukevideo.localhost |
| RustFS console | http://s3-ui.nukevideo.localhost |
| RedisInsight | http://redis.nukevideo.localhost |
| Traefik dashboard | http://localhost:8080 |

## Next Steps

- [What is NukeVideo?](/guide/what-is-nukevideo) — Architecture overview.
- [Video Processing](/guide/video-processing) — The encoding pipeline.
- [Templates](/guide/templates) — Define encoding configurations.
- [Streaming & VOD](/guide/streaming) — How packaged content is served.
- [CDN & Delivery](/guide/cdn) — Proxy nodes vs Bunny CDN.
- [Configuration](/guide/configuration) — Environment variables.
- [API Reference](/api/authentication) — Start integrating with the API.
