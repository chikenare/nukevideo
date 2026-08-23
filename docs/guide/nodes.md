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

### Cache disks

A proxy node is dedicated hardware: a small NVMe (or a mirrored pair) for the operating system, and every other disk for the segment cache. The deploy provisions those disks itself — you do not prepare a filesystem or point the node at one.

What a deploy does with the host's disks:

- **Disks holding the system** — anything with a mountpoint or swap on it, including the members of a RAID or LVM volume that is mounted — are never touched. This is how the OS disks are recognised, not by their bus: a cache disk may be NVMe too.
- **Disks already carrying the cache** (the `nukevideo-cache` label) are mounted as they are. A redeploy — which is how a node is updated — keeps the cache warm.
- **Every other disk** is formatted into the cache pool: one mdadm **RAID 0** across all of them (plain XFS when there is only one), mounted at `/var/lib/nukevideo/cache`. RAID 0 because the cache is a copy of what S3 holds — redundancy would cost capacity and write amplification for nothing — and because one spindle cannot feed a 10 Gbps port. Disks of different sizes lose nothing: Linux md stripes them by zones.

The panel lists those disks, with whatever it found on them, and asks before the deploy runs; untick one to leave it alone (a slow or small disk only drags a stripe down). A host without a single spare disk caches into a docker volume on the OS disk, capped at 10 GB, and the deploy says so.

The edge then sizes its cache to the pool when it starts: everything but a reserve of 3 % (at least 20 GB) becomes `max_size`, the reserve becomes `min_free`, and the keys zone is sized for the number of segments the pool can hold. Nothing about the cache is configured by hand, and nothing expires by the calendar — eviction is the LRU's job.

**Reading the numbers.** The nodes page shows, per proxy and for the last week, the cache hit ratio by bytes, what the node served and what it fetched from S3 to do so (`GET /api/analytics/edges?from&to`, admin only). Downloads and manifests never enter the cache and are left out of the ratio. A ratio that holds while the pool fills is the cache doing its job; a ratio that falls with a full pool means the working set outgrew the disk — add disk, or add a node so each one holds less of the catalogue. A low ratio with an empty pool is a cold cache: a fresh deploy, a rebuilt pool, or a node that just joined the ring.

**When a node's pool is full** nothing breaks: nginx evicts the least recently played segments to make room, and the hit ratio is the only thing that drops. Grow the pool or add another proxy node; do not deactivate the full one, which would only move its videos, cold, onto the others.

**A dead disk** takes the whole pool with it. The node keeps serving — every request is a miss until the disk is replaced and the node redeployed, which builds a new pool. To rebuild a pool on purpose (to add a disk, for instance), wipe the label from its members — `wipefs -a` on each — and redeploy; the panel will list them as disks to format again.

**From a development panel** the list comes with nothing ticked: a development deploy's target is often the developer's own machine, where an unmounted disk is a backup, not a spare. Tick what you mean to wipe; with nothing ticked the edge caches into a docker volume.

### Cloudflare Real IP

The proxy includes built-in support for resolving real client IPs when behind Cloudflare or other reverse proxies. The `cloudflare-realip.conf` file is included in the Nginx config and trusts:

- Docker internal networks (`172.16.0.0/12`, `10.0.0.0/8`)
- All Cloudflare IPv4 and IPv6 ranges

The `X-Forwarded-For` header is used with `real_ip_recursive on`, which correctly resolves the client IP regardless of how many trusted proxies are in the chain.

### Scaling delivery

Every proxy node shares the token secret, so any of them can serve any video. What decides which one a viewer is sent to is a **consistent hash ring** over the routable proxies: each video lands on one node, whose cache is the only one that ever holds its segments. Adding a node moves about 1/(N+1) of the catalogue onto it — those videos arrive cold and the rest stay warm — and the fleet's traffic to S3 stays roughly what the catalogue churns, however many nodes there are. Do not put a load balancer or a round-robin DNS record in front of the proxies: it defeats the sharding and every node ends up caching everything.

A proxy is **routable** when it is active, has a hostname, is not draining and is answering the health probe.

**Draining.** Toggle it on a node before maintenance or before taking it out for good. New playback links go to its ring neighbours; the node goes on serving the sessions it has. Deactivating a node, by contrast, stops its containers at once. Playback links stay valid for the token window (an hour by default) plus the segment query-token window (another hour): wait that long after draining before deactivating, and nobody notices.

**Health.** `nodes:probe` runs every minute on the API host and fetches `/healthz` on every active proxy The edge answers with a header carrying its own node id, and only that counts as alive — a 404 from Traefik, a 403 from Cloudflare or somebody else's site at the hostname all count as silence. After three consecutive failures the node is taken out of new links and marked **unhealthy** in the panel; the first good probe puts it back, as does a successful deploy or reactivation. Sessions already on a node that dies are lost; this is for the next ones. Should every proxy look dead at once, the resolver assumes the probe is wrong rather than the fleet and keeps linking to all active nodes.

An edge from before `/healthz` existed fails the probe and drops out of rotation: redeploy it.

Behind Cloudflare, `/healthz` must reach the edge: exempt it from any WAF, bot or Access rule, or the probe sees Cloudflare's answer instead of nginx's and marks the node unhealthy.

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
