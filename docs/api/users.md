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

Get analytics data:

```
GET /api/analytics
```

Returns bandwidth and usage metrics from ClickHouse.
