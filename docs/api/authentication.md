# Authentication

NukeVideo uses [Laravel Sanctum](https://laravel.com/docs/sanctum) for API authentication. The
admin panel authenticates with a session cookie; everything else sends a Bearer token — a personal
API token or a project API key.

There is no self-registration endpoint: accounts are created by an administrator through
[`POST /api/users`](/api/users#create-user).

## Login

Authenticate and receive a session cookie. This is the panel's flow: fetch the CSRF cookie first
(`GET /api/csrf-cookie`), then send the `X-XSRF-TOKEN` header with the login request.

```
POST /api/login
```

**Request Body:**

```json
{
  "email": "john@example.com",
  "password": "your_password"
}
```

Wrong credentials respond `422` with the error on `email`. Login is rate limited to **10 attempts
per minute per IP address** (`429` beyond that) — the only rate limit in the API.

## Logout

Invalidate the current session.

```
POST /api/logout
```

## API Tokens

For programmatic access, create API tokens from the dashboard or via the API.

Personal tokens act as you, across all of your projects, and never expire — revoke one you no
longer need.

### List Tokens

```
GET /api/tokens
```

Returns `{ "data": [ ... ] }` with the token objects shown below, newest first, without the
plain-text `token`.

### Create Token

```
POST /api/tokens
```

**Request Body:**

```json
{
  "name": "My API Token"
}
```

**Response** (`201`):

```json
{
  "data": {
    "id": 1,
    "name": "My API Token",
    "abilities": ["*"],
    "lastUsedAt": null,
    "createdAt": "2026-07-13T00:00:00.000000Z",
    "expiresAt": null,
    "token": "1|abc123..."
  },
  "message": "API token created successfully."
}
```

The plain-text `token` is only returned here, once.

### Delete Token

```
DELETE /api/tokens/{id}
```

## Using Tokens

Include the token in the `Authorization` header:

```
Authorization: Bearer 1|abc123...
```

Every endpoint that works on project data — videos, templates, streams, uploads, activity log —
resolves inside **one** project, and refuses the request (`400`) without one. A user token names the
project with the `X-Project-Ulid` header on each request (a project that is not yours responds
`404`):

```
Authorization: Bearer 1|abc123...
X-Project-Ulid: 01HX...
```

## Project API Keys

A project API key **is** the project, the way a service account is its own identity: it does not act
on behalf of you, it acts as the project. So it needs no `X-Project-Ulid` header, and it cannot leave
its project — a key of project A cannot read, update or delete a video of project B, nor upload into
it, even though you own both.

What it can reach: videos, templates, streams, uploads and the project's activity log, plus the
read-only [analytics](/api/analytics) endpoints (all but the admin-only per-node report) and
[`/usage`](/api/analytics#usage) — because reading those numbers back is what an integrating backend
holds a key for. Most analytics reads narrow to the key's project, but not all of them: the
[batch by tracking id](/api/analytics#batch-bandwidth-by-tracking-id) read, the queue counts and
the encoding figures are **instance-wide**, and `/usage` resolves to the account that owns the
project, so they can show more than the calling project's own traffic. Keep the key server-side.

What it cannot reach: anything that manages the account or the instance — `/me`, `/profile`,
`/projects`, `/tokens` and every admin endpoint answer `403`, even when the project's owner is an
admin. Those stay for the dashboard and for user tokens.

Generate or rotate it from the dashboard (Projects → ⋮ → Regenerate API key) or via the API:

```
POST /api/projects/{ulid}/api-key
```

Regenerating revokes the project's previous key. The plain-text key is only returned once, in
`data.apiKey.token`:

```json
{
  "data": {
    "ulid": "01HX...",
    "name": "My project",
    "settings": null,
    "apiKey": {
      "id": 7,
      "name": "My project API key",
      "abilities": ["*"],
      "lastUsedAt": null,
      "createdAt": "2026-07-13T00:00:00.000000Z",
      "expiresAt": null,
      "token": "7|abc123..."
    },
    "createdAt": "2026-07-01T00:00:00+00:00",
    "updatedAt": "2026-07-01T00:00:00+00:00"
  },
  "message": "API key regenerated successfully"
}
```

Sending `X-Project-Ulid` for a different project than the key's returns `403`.

## Current User

Get the authenticated user's information:

```
GET /api/me
```

**Response:**

```json
{
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "isAdmin": false,
    "projects": [{ "ulid": "01HX...", "name": "My project", "settings": null, "apiKey": null, "createdAt": "...", "updatedAt": "..." }]
  }
}
```

`projects` lists every project you own, each with its API key's metadata (never the plain-text
key).

## Profile

### Update Profile

```
PUT /api/profile
```

**Request Body:**

```json
{
  "name": "John Updated",
  "email": "john.new@example.com"
}
```

Both fields are required. Responds with the updated user in `data`.

### Update Password

```
PUT /api/profile/password
```

**Request Body:**

```json
{
  "currentPassword": "old_password",
  "password": "new_password",
  "passwordConfirmation": "new_password"
}
```

The new password needs at least 8 characters. A wrong `currentPassword` responds `422`.

## Authorization

Some endpoints require admin privileges. These are marked with **Admin** in the API reference. Non-admin users will receive a `403 Forbidden` response.

Admin covers what operates the instance: nodes and their operations, SSH keys, the node
environment, CDN settings, user management, the version check and the
[per-node delivery](/api/analytics#per-node-delivery) report. The other metrics endpoints —
[`/analytics`](/api/analytics) and its batch reads, [`/metrics`](/api/analytics#metrics-query) and
[`/usage`](/api/analytics#usage) — are **not** admin, and are readable with any authenticated token,
including a project API key.
