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
  "ssh_key_id": 1,
  "hostname": "worker-1.example.com"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Display name |
| `ip_address` | string | Yes | Server IP address |
| `user` | string | Yes | SSH username |
| `type` | string | Yes | `worker` or `proxy` |
| `ssh_key_id` | integer | Yes | SSH key to use |
| `hostname` | string | No | Server hostname |

## Update Node

```
PUT /api/nodes/{id}
```

## Delete Node

Deletes the node and removes its Docker containers from the remote server.

```
DELETE /api/nodes/{id}
```

## Node Metrics

Get health metrics for all nodes.

```
GET /api/nodes/metrics
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

## SSH Keys

SSH keys are used to authenticate with remote nodes.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/ssh-keys` | List SSH keys |
| `POST` | `/api/ssh-keys` | Create SSH key |
| `GET` | `/api/ssh-keys/{id}` | Get SSH key |
| `DELETE` | `/api/ssh-keys/{id}` | Delete SSH key |

### Create SSH Key

```
POST /api/ssh-keys
```

**Request Body:**

```json
{
  "name": "Production Key",
  "public_key": "ssh-ed25519 AAAA...",
  "private_key": "-----BEGIN OPENSSH PRIVATE KEY-----\n..."
}
```

::: warning
Private keys are stored encrypted. They are never returned in API responses.
:::
