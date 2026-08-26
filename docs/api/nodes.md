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

## Deployment

### Get Deploy Steps

```
GET /api/nodes/{id}/deploy/steps
```

Returns the list of available deployment steps for the node.

### Execute Deploy Step

```
POST /api/nodes/{id}/deploy
```

**Request Body:**

```json
{
  "step": "step_name"
}
```

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
