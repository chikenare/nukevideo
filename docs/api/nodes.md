# Nodes

Manage worker and proxy nodes. All node endpoints require **admin** privileges.

See the [Nodes guide](/guide/nodes) for architecture details.

## List Nodes

```
GET /api/nodes
```

## Get Node

```
GET /api/nodes/{id}
```

## Create Node

```
POST /api/nodes
```

**Request Body:**

```json
{
  "name": "worker-us-east-1",
  "ip_address": "203.0.113.10",
  "user": "deploy",
  "type": "worker",
  "hostname": "worker-1.example.com"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Display name |
| `ip_address` | string | Yes | Server IP address |
| `user` | string | Yes | SSH username |
| `type` | string | Yes | `worker` or `proxy` |
| `hostname` | string | No | Server hostname (required for proxy nodes) |
| `workers` | integer | No | Number of parallel workers (1–20 for workers, 1 for proxy) |

## Update Node

```
PUT /api/nodes/{id}
```

## Delete Node

Deletes the node and removes its Docker containers from the remote server.

```
DELETE /api/nodes/{id}
```

Answers `409` while the node has an operation queued or running: a deploy finishing after the
delete would recreate the container.

## List Containers

Get Docker containers running on a node.

```
GET /api/nodes/{id}/containers
```

## Pending Jobs

Get queue statistics for a node.

```
GET /api/nodes/{id}/pending-jobs
```

**Response:**

```json
{
  "pending": 5,
  "reserved": 2,
  "total": 7
}
```

## Operations

Deploy, start and stop run in the background: each call answers `202` at once with the operation
it queued, and `409` when the node already has one queued or running. An operation is an
activity-log entry (`logName: "node"`) whose `properties.status` goes `queued` → `running` →
`succeeded`, `failed` or `cancelled`.

### Deploy a Node

```
POST /api/nodes/{id}/deploy
```

```json
{
  "force": false,
  "disks": ["/dev/sdb"]
}
```

`force` skips the drain: a worker's running jobs are killed instead of finished, and redeliver
about 31 minutes later. It does nothing on a proxy. `disks` is required for a production proxy,
`[]` included (see [Cache disks](/guide/nodes#cache-disks)). A successful deploy activates the node.

**Response** `202`:

```json
{
  "data": {
    "id": 42,
    "logName": "node",
    "description": "Deploy worker-01",
    "subjectType": "App\\Models\\Node",
    "subjectId": 1,
    "causerType": "App\\Models\\User",
    "causerId": 1,
    "event": "node_deploy",
    "properties": { "action": "deploy", "force": false, "disks": null, "batch": null, "status": "queued" },
    "createdAt": "2026-09-30T12:00:00+00:00",
    "updatedAt": "2026-09-30T12:00:00+00:00"
  }
}
```

### Deploy Several Nodes

```
POST /api/nodes/deploy
```

```json
{
  "nodes": [1, 2, 3],
  "force": false
}
```

Workers deploy in parallel. Proxies deploy one at a time, and the first one that fails cancels the
rest. A fleet deploy never formats a disk. Busy and stopped nodes are skipped: a stopped node stays
stopped until it is started or deployed on its own. **Response** `202`:
`{ "data": [/* one operation per node */], "skipped": [3] }`. The operations share a
`properties.batch`.

### Start a Node

```
POST /api/nodes/{id}/start
```

Starts the containers of a node that was deployed and stopped, and activates it.

### Stop a Node

```
POST /api/nodes/{id}/stop
```

```json
{ "force": false }
```

Deactivates the node first, so it takes no new work, then stops its containers. A worker's running
jobs are finished first (up to 11 minutes) unless `force` is set.

### List Operations

```
GET /api/node-operations?node={id}
```

Newest first, 20 per page, `node` optional. Admins also see these in the activity log
(`GET /api/activity-log`), as `node_deploy`, `node_start` and `node_stop` events.

### Read an Operation's Output

```
GET /api/node-operations/{operation}/lines?after={n}
```

```json
{ "lines": ["=== Worker image ===", "..."], "next": 57, "status": "running" }
```

Poll with `after` set to the previous `next` until `status` is no longer `queued` or `running`.
Output is kept for seven days.

## SSH Key

The panel connects to every node with one SSH key, kept in the application settings. Install its
public half in `~/.ssh/authorized_keys` of the SSH user on each node.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/app-settings` | The public key and its fingerprint (`null` before a key exists) |
| `PUT` | `/api/app-settings/ssh-key` | Generate a key, or import one |
| `POST` | `/api/app-settings/ssh-key/rotate` | Swap the fleet to a new key |

### Set the SSH Key

```
PUT /api/app-settings/ssh-key
```

**Request Body:**

```json
{ "privateKey": "-----BEGIN OPENSSH PRIVATE KEY-----\n..." }
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `privateKey` | string | No | The private half of a pair you already have. **Omit it (or send `{}`) to have the server generate an Ed25519 pair.** The public key is always derived from the private one, never accepted |

This replaces the current key **without touching the nodes**: use it for the first key, or when
the new public key already reached the nodes by other means (cloud-init, a provisioning tool).
The response is the `GET` shape, with the new `sshPublicKey` to install.

### Rotate the SSH Key

```
POST /api/app-settings/ssh-key/rotate
```

Generates a new key and swaps the fleet to it in the only order that cannot lock the panel out:
the new public key is installed on every node **with the current key**, the new private key is
verified to log in, and only then does the panel switch — after which the old public line is
removed from the nodes. If any **active** node refuses, nothing is switched. An inactive node is
tried but cannot block the rotation, since it may be gone for good.

**Response:**

```json
{
  "data": {
    "rotated": true,
    "nodes": [
      { "id": 1, "name": "worker-1", "ok": true, "error": null },
      { "id": 2, "name": "edge-1", "ok": false, "error": "Connection refused" }
    ]
  }
}
```

::: warning
The private key is stored encrypted and never returned by any endpoint.
:::
