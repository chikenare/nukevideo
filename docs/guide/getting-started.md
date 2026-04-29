# Getting Started

This guide will help you set up NukeVideo for local development using Docker.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) and Docker Compose
- [Git](https://git-scm.com/)

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/chikenare/nukevideo.git
cd nukevideo
```

### 2. Configure environment

```bash
cp .env.example .env
```

### 3. Start the services

```bash
docker compose -f docker-compose.yml up -d
```

## Next Steps

- [Video Processing](/guide/video-processing) — Learn about the encoding pipeline.
- [API Reference](/api/authentication) — Start integrating with the API.
