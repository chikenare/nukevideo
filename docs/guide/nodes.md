# Nodes

Nodes are remote servers that handle video encoding (workers) or content delivery (proxies). NukeVideo manages nodes via SSH and deploys Docker containers to them.

## Node Types

| Type | Purpose | Docker Image |
|------|---------|-------------|
| `worker` | Video encoding with FFmpeg | `nukevideo/worker` |
| `proxy` | Video streaming via Nginx VOD | `nukevideo/proxy` |

## Worker Nodes

Worker nodes run FFmpeg-based containers that process video encoding jobs from Redis queues.

### Job Distribution

The system automatically assigns videos to the **least busy** worker with available slots. Each worker node can run 1–20 parallel encoding containers. GPU nodes are preferred for GPU-accelerated codecs, with fallback to CPU nodes when GPU nodes are full.

### GPU Support

Worker nodes with NVIDIA GPUs can be flagged with `has_gpu: true`. The setup script installs the NVIDIA Container Toolkit, and encoding jobs that require GPU codecs (e.g., `h264_nvenc`) are routed to GPU nodes first.

## Proxy Nodes

Proxy nodes run a custom Nginx build with Kaltura's VOD modules for on-the-fly video packaging and delivery.

They handle:
- HLS and DASH manifest generation
- On-the-fly segmentation from S3 sources
- Token-based access control
- S3 authentication for fetching media files
- Local response caching (configurable)

### CDN Mode

When a proxy node sits behind a CDN (e.g., Cloudflare), enable **CDN mode** to disable local Nginx caching. The CDN handles caching at the edge, so local cache becomes redundant.

```
POST /api/nodes
{
  "name": "proxy-cdn-1",
  "type": "proxy",
  "hostname": "cdn.example.com",
  "cdn_mode": true,
  ...
}
```

When `cdn_mode` is enabled:
- Nginx's `proxy_cache` is set to `off` — no local disk caching
- The VOD module, token validation, CORS headers, and logging continue to work normally
- The CDN_MODE environment variable is injected into the container at deploy time

### Cloudflare Real IP

The proxy includes built-in support for resolving real client IPs when behind Cloudflare or other reverse proxies. The `cloudflare-realip.conf` file is included in the Nginx config and trusts:

- Docker internal networks (`172.16.0.0/12`, `10.0.0.0/8`)
- All Cloudflare IPv4 and IPv6 ranges

The `X-Forwarded-For` header is used with `real_ip_recursive on`, which correctly resolves the client IP regardless of how many trusted proxies are in the chain.

## Managing Nodes

### Prerequisites

Before adding a node, you need an SSH key registered in NukeVideo:

```
POST /api/ssh-keys
{
  "name": "Production Key",
  "public_key": "ssh-ed25519 AAAA...",
  "private_key": "-----BEGIN OPENSSH PRIVATE KEY-----..."
}
```

### Creating a Node

```
POST /api/nodes
{
  "name": "worker-us-east-1",
  "ip_address": "203.0.113.10",
  "user": "deploy",
  "type": "worker",
  "ssh_key_id": 1,
  "hostname": "worker-1.nukevideo.com"
}
```

### Deploying

Deployment runs a series of steps on the remote server via SSH:

1. Check the deployment steps available:
   ```
   GET /api/nodes/{id}/deploy/steps
   ```

2. Execute a specific step:
   ```
   POST /api/nodes/{id}/deploy
   { "step": "step_name" }
   ```

### Monitoring

- **Metrics** — `GET /api/nodes/metrics` returns health data for all nodes.
- **Containers** — `GET /api/nodes/{id}/containers` lists Docker containers on the node.
- **Pending Jobs** — `GET /api/nodes/{id}/pending-jobs` returns queue statistics.

## API Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| `GET` | `/api/nodes` | List all nodes | Admin |
| `POST` | `/api/nodes` | Create a node | Admin |
| `GET` | `/api/nodes/{id}` | Get a node | Admin |
| `PUT` | `/api/nodes/{id}` | Update a node | Admin |
| `DELETE` | `/api/nodes/{id}` | Delete a node and its containers | Admin |
| `GET` | `/api/nodes/metrics` | Node health metrics | Admin |
| `GET` | `/api/nodes/{id}/containers` | List containers | Admin |
| `GET` | `/api/nodes/{id}/pending-jobs` | Queue statistics | Admin |
| `GET` | `/api/nodes/{id}/deploy/steps` | Get deploy steps | Admin |
| `POST` | `/api/nodes/{id}/deploy` | Execute deploy step | Admin |
