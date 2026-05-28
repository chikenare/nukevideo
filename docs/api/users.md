# Users

Manage user accounts. All user management endpoints require **admin** privileges.

## List Users

```
GET /api/users
```

**Response:**

```json
{
  "data": [
    {
      "id": 1,
      "ulid": "01HX...",
      "name": "Admin",
      "email": "admin@nukevideo.local",
      "is_admin": true,
      "created_at": "2025-01-15T10:30:00Z"
    }
  ]
}
```

## Get User

```
GET /api/users/{id}
```

## Create User

```
POST /api/users
```

**Request Body:**

```json
{
  "name": "New User",
  "email": "user@example.com",
  "password": "secure_password",
  "is_admin": false
}
```

## Update User

```
PUT /api/users/{id}
```

**Request Body:**

```json
{
  "name": "Updated Name",
  "email": "updated@example.com",
  "is_admin": true
}
```

## Delete User

```
DELETE /api/users/{id}
```

::: warning
Deleting a user does not automatically delete their videos. Ensure videos are reassigned or deleted before removing a user account.
:::

## Activity Log

Get the activity log for the authenticated user:

```
GET /api/activity-log
```

This returns a history of actions performed by the user (video uploads, template changes, etc.).

## Analytics

Get analytics data (admin only):

```
GET /api/analytics?from=2026-04-01&to=2026-04-30
```

Returns bandwidth, sessions, and encoding metrics from ClickHouse.

## Usage

Query usage metrics for the authenticated user. This is the primary endpoint for external projects to track per-user consumption.

```
GET /api/usage
```

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `from` | date | Yes | Start date (`YYYY-MM-DD`) |
| `to` | date | Yes | End date (`YYYY-MM-DD`) |
| `metric` | string | No | Filter by metric name |
| `external_user_id` | string | No | Filter by external user ID |

**Available Metrics:**

| Metric | Unit | Description |
|--------|------|-------------|
| `upload_bytes` | bytes | File size of uploaded videos |
| `encoding_cpu` | seconds | CPU encoding time |
| `encoding_gpu` | seconds | GPU encoding time |

**Example Request:**

```
GET /api/usage?from=2026-04-01&to=2026-04-30&metric=upload_bytes&external_user_id=user-123
```

**Response:**

```json
{
  "data": [
    {
      "metric": "upload_bytes",
      "external_user_id": "user-123",
      "value": 52428800,
      "date": "2026-04-16"
    }
  ]
}
```

::: tip
The `external_user_id` corresponds to the `externalUserId` metadata passed during video upload. This allows external projects to track usage per-user without NukeVideo needing to manage those users directly.
:::

Usage data is stored in ClickHouse using a `SummingMergeTree` engine, which automatically aggregates values per `(user_id, metric, external_user_id, date)`. This means multiple uploads on the same day by the same user are summed into a single row for efficient querying.
