# Nodes

Nodes are remote servers that handle video encoding (workers) or content delivery (proxies). NukeVideo manages nodes via SSH and deploys Docker containers to them.

## Node Types

| Type | Purpose |
|------|---------|
| `worker` | Video encoding with FFmpeg |
| `proxy` | CMAF delivery from S3 with token validation |

## Worker Nodes

Worker nodes run FFmpeg-based containers that process video encoding jobs from Redis queues. Sources are split into chunks and encoded in parallel across containers (SVT-AV1, x264/x265), then packaged into static CMAF.

### Job Distribution

The system automatically assigns videos to the **least busy** worker with available slots. Each worker node can run 1–20 parallel encoding containers.

## Proxy Nodes

Proxy nodes run a custom nginx build that delivers the **pre-packaged CMAF** from S3. They do not repackage anything at request time.

They handle:
- Validating Akamai-style stream tokens
- Reading packaged segments from S3 using AWS authentication
- Local segment caching (manifests bypass the cache)
- Cloudflare real-IP resolution
- Shipping access-log bandwidth to ClickHouse via Vector.dev

> **Alternative:** You don't have to run proxy nodes at all. **Bunny CDN** can deliver the same static CMAF straight from your S3 origin, configured entirely from the admin panel. See [CDN & Delivery](/guide/cdn) to decide which fits your deployment.

### CDN Mode

When a proxy node sits behind a CDN (e.g., Cloudflare), enable **CDN mode** to disable the local nginx segment cache. The CDN handles caching at the edge, so the local cache becomes redundant.

```
POST /api/nodes
{
  "name": "proxy-cdn-1",
  "type": "proxy",
  "hostname": "cdn.example.com",
  ...
}
```

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
| `GET` | `/api/nodes/{id}/containers` | List containers | Admin |
| `GET` | `/api/nodes/{id}/pending-jobs` | Queue statistics | Admin |
| `GET` | `/api/nodes/{id}/deploy/steps` | Get deploy steps | Admin |
| `POST` | `/api/nodes/{id}/deploy` | Execute deploy step | Admin |
