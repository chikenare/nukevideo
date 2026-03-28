# Nodes

Nodes are remote servers that handle video encoding (workers) or content delivery (proxies). NukeVideo manages nodes via SSH and deploys Docker containers to them.

## Node Types

| Type | Purpose | Docker Image |
|------|---------|-------------|
| `worker` | Video encoding with FFmpeg | `nukevideo/worker` |
| `proxy` | Video streaming via Nginx VOD | `nukevideo/proxy` |

## Worker Nodes

Worker nodes run FFmpeg-based containers that process video encoding jobs from Redis queues.

### Workloads

Each worker node is configured with one or more workload levels based on its hardware capacity:

| Workload | Resolution Range | Typical Use |
|----------|-----------------|-------------|
| `light` | < 720p | Low-res encodes, thumbnails |
| `medium` | 720p – 1080p | Standard HD content |
| `heavy` | > 1080p | 4K, high-bitrate content |

### Job Distribution

The system automatically assigns videos to the **least busy** worker that supports the required workload. If no exact match is available, it falls back to nodes with higher capacity (e.g., a `medium` job can run on a `heavy` node).

### Instances

Each worker node can run multiple Docker container instances for parallel processing. The `instances` field tracks which containers are active on the node.

## Proxy Nodes

Proxy nodes run a custom Nginx build with Kaltura's VOD modules for on-the-fly video packaging and delivery.

They handle:
- HLS and DASH manifest generation
- On-the-fly segmentation from S3 sources
- Token-based access control
- S3 authentication for fetching media files

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
