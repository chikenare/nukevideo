# Nodes

Nodes are remote servers that handle video encoding (workers) or content delivery (proxies). NukeVideo manages nodes via SSH and deploys Docker containers to them.

## Node Types

| Type | Purpose |
|------|---------|
| `worker` | Video encoding with FFmpeg |
| `proxy` | CMAF delivery from S3 with token validation |

## Worker Nodes

Each worker node runs one container (Horizon) that pulls encoding jobs from Redis queues. Sources are split into keyframe-aligned chunks and encoded in parallel across every worker of the fleet (x264, x265, SVT-AV1 on CPU; QSV or NVENC on a node whose `accel` is `intel` or `nvidia`), then packaged into static CMAF.

### Job Distribution

Workers are not assigned videos: they pull. `videos:dispatch` (every five seconds on the API host) admits pending videos while each hardware family — CPU, Intel, NVIDIA — has fewer videos in flight than it has active worker nodes plus one, and a video's chunk jobs then go to its family's queue, drained by every worker of that family. GPU nodes do not drain the CPU queue. How many chunks a node encodes at once is sized from its CPU cores and RAM when the container starts; `VIDEO_WORKER_PROCESSES` (CPU) or `GPU_WORKER_PROCESSES` in the node's environment overrides it.

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

The edge then sizes its cache to the pool when it starts: everything but a reserve of 3 % (at least 20 GB) becomes `max_size`, the reserve becomes `min_free`, and the keys zone is sized for the number of segments the pool can hold. Nothing about the cache is configured by hand, and beyond dropping a segment nobody has played for 30 days, nothing expires by the calendar — eviction is the LRU's job.

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

**Draining.** Toggle it on a node before maintenance or before taking it out for good. New playback links go to its ring neighbours; the node goes on serving the sessions it has. Stopping a node, by contrast, takes its containers down. Playback links stay valid for the token window (an hour by default), and the segments a session fetches expire with its link: wait that long after draining before stopping it, and nobody notices.

**Health.** `nodes:probe` runs every minute on the API host (when the self-hosted CDN is the provider) and fetches `/healthz` on every active proxy with a hostname. The edge answers with a header carrying its own node id, and only that counts as alive — a 404 from Traefik, a 403 from Cloudflare or somebody else's site at the hostname all count as silence. After three consecutive failures the node is taken out of new links and marked **unhealthy** in the panel; the first good probe puts it back, as does a successful deploy or start. Failures in the first 10 minutes after a deploy or an edit of the node are not counted, which covers a certificate still being issued or DNS still settling. Sessions already on a node that dies are lost; this is for the next ones. Should every proxy look dead at once, the resolver assumes the probe is wrong rather than the fleet and keeps linking to all active nodes.

An edge from before `/healthz` existed fails the probe and drops out of rotation: redeploy it.

Behind Cloudflare, `/healthz` must reach the edge: exempt it from any WAF, bot or Access rule, or the probe sees Cloudflare's answer instead of nginx's and marks the node unhealthy.

## Managing Nodes

### Prerequisites

**The SSH user.** The deploy runs over SSH with no terminal, so whatever it needs root for
(installing Docker, enabling the service, the cache-disk setup on a proxy) has to work without a
password prompt. Either:

- connect as `root` (with `PermitRootLogin prohibit-password` and the key below in
  `/root/.ssh/authorized_keys`) — the usual choice on a server, and what production is expected
  to use; or
- a dedicated user with passwordless sudo:
  `echo 'nukevideo ALL=(ALL) NOPASSWD: ALL' > /etc/sudoers.d/nukevideo` (mode `0440`).
  Membership of the `sudo` group alone is not enough: that rule asks for a password.

The deploy adds the user to the `docker` group itself. Note that this group is root-equivalent —
anyone in it can start a privileged container over the host filesystem — so a restricted user
buys no isolation over root here; it only satisfies a "no root login" policy. The script checks
`sudo -n` first and aborts with the fix if the host would have prompted.

**The images.** A node never builds; it pulls `nukevideo-api` / `nukevideo-proxy` at the tag the
panel decides: the released version in production, `node-dev` from `DOCKER_REGISTRY` in
development. For a development node, build and push that tag from the checkout first —
`bin/push-node-dev` — then deploy; repeat after every change you want the node to run.

**The SSH key.** The panel connects to every node with one key, kept under Settings → App
(`PUT /api/app-settings/ssh-key`). Generate it there, copy the public key it shows and add it to
`~/.ssh/authorized_keys` of the SSH user on each node — the same line on every node. A pair made
elsewhere can be imported by its private half alone; the public key is derived from it. To
replace the key on a running fleet, **Rotate** installs the new key on every node with the old
one before switching, so the panel never loses access — see [Rotate the SSH Key](/api/nodes#rotate-the-ssh-key).

### Creating a Node

```
POST /api/nodes
{
  "name": "worker-us-east-1",
  "ipAddress": "203.0.113.10",
  "user": "root",
  "type": "worker"
}
```

A proxy also needs a `hostname`: it is the address playback links point at. See [Create Node](/api/nodes#create-node) for every field.

A worker that cannot be reached over SSH (behind NAT, say) can install itself instead: **Setup → Install**
in the node's menu generates a one-time `curl … | bash` command to run on the machine — see
[Bootstrap Command](/api/nodes#bootstrap-command).

### Deploying, starting and stopping

A node has three actions, in its menu on the nodes page: **Deploy**, **Start** and **Stop**. They
run in the background — close the tab and nothing stops. **Logs** (next to Add Node, or in a
node's menu) shows the output of the latest operation, of any node or of the one picked; the
history of every operation is in the **Activity Log**. A node runs one operation
at a time.

- **Deploy** installs what the node needs, pulls the image and recreates the containers. It is also
  how a node is updated. On a production worker it waits for the running jobs to finish (up to 11
  minutes; development and staging do not wait); **force** kills them instead, and they are picked
  up again about 31 minutes later. On a proxy the gap is the edge's own startup, a second or two
  that players ride out on their buffer; the deploy fails if the new edge does not answer
  `/healthz`, and Traefik is only replaced when it is not running or its image or flags changed.
  Deploying a stopped node starts it.
- **Stop** takes the node out first — no new videos, no new playback links — then stops its
  containers, gracefully or, with force, at once. The containers stay stopped across reboots.
- **Start** brings a stopped node back.

To update the fleet, select the nodes and **Deploy selected**. Stopped nodes are left out. Workers deploy all at once (encoding
waits meanwhile); proxies deploy one at a time, and the first one that fails cancels the rest, so a
broken image never takes more than one edge down. A fleet deploy never formats a disk: adding one to
a proxy's cache pool is a deploy of that node alone.

### Checking a node

**Setup → Validate** (`POST /api/nodes/{id}/validate`) runs a set of checks over SSH — Docker, the Docker
network, the node's running containers, free disk, plus a test encode on a GPU node and the cache
pool on a proxy — and reports each one as `ok` or `error` with its output. How many videos are
waiting, running or failed is `GET /api/analytics/queue`.

## API Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| `GET` | `/api/nodes` | List all nodes | Admin |
| `POST` | `/api/nodes` | Create a node | Admin |
| `GET` | `/api/nodes/{id}` | Get a node | Admin |
| `PUT`/`PATCH` | `/api/nodes/{id}` | Update a node | Admin |
| `DELETE` | `/api/nodes/{id}` | Delete a node and its containers | Admin |
| `POST` | `/api/nodes/{id}/validate` | Run the health checks | Admin |
| `GET` | `/api/nodes/{id}/cache-disks` | What a deploy would do to a proxy's disks | Admin |
| `POST` | `/api/nodes/{id}/bootstrap-token` | One-time install command (workers) | Admin |
| `POST` | `/api/nodes/{id}/deploy` | Deploy a node | Admin |
| `POST` | `/api/nodes/{id}/start` | Start a node | Admin |
| `POST` | `/api/nodes/{id}/stop` | Stop a node | Admin |
| `POST` | `/api/nodes/deploy` | Deploy several nodes | Admin |
| `GET` | `/api/node-operations` | List operations | Admin |
| `GET` | `/api/node-operations/{id}/lines` | An operation's output | Admin |
