# Authentication

NukeVideo uses [Laravel Sanctum](https://laravel.com/docs/sanctum) for API authentication. All protected endpoints require a Bearer token.

## Register

Create a new user account.

```
POST /register
```

**Request Body:**

```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "your_password",
  "password_confirmation": "your_password"
}
```

## Login

Authenticate and receive a session cookie.

```
POST /login
```

**Request Body:**

```json
{
  "email": "john@example.com",
  "password": "your_password"
}
```

## Logout

Invalidate the current session.

```
POST /logout
```

## API Tokens

For programmatic access, create API tokens from the dashboard or via the API.

### List Tokens

```
GET /api/tokens
```

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

**Response:**

```json
{
  "token": "1|abc123..."
}
```

### Delete Token

```
DELETE /api/tokens/{id}
```

## Using Tokens

Include the token in the `Authorization` header:

```
Authorization: Bearer 1|abc123...
```

Every endpoint that works on project data — videos, templates, streams, uploads, usage, analytics,
activity log — resolves inside **one** project, and refuses the request (`400`) without one. A user
token names the project with the `X-Project-Ulid` header on each request:

```
Authorization: Bearer 1|abc123...
X-Project-Ulid: 01HX...
```

## Project API Keys

A project API key **is** the project, the way a service account is its own identity: it does not act
on behalf of you, it acts as the project. So it needs no `X-Project-Ulid` header, and it cannot leave
its project — a key of project A cannot read, update or delete a video of project B, nor upload into
it, even though you own both.

What it can reach: videos, templates, streams, uploads and the project's activity log. What it cannot:
anything that spans the account or the instance — `/me`, `/profile`, `/projects`, `/tokens`, `/usage`,
`/analytics` and every admin endpoint answer `403`, even when the project's owner is an admin. Those
stay for the dashboard and for user tokens.

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
    "apiKey": {
      "id": 7,
      "name": "My project API key",
      "lastUsedAt": null,
      "createdAt": "2026-07-13T00:00:00+00:00",
      "token": "7|abc123..."
    }
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
  "id": 1,
  "ulid": "01HX...",
  "name": "John Doe",
  "email": "john@example.com",
  "is_admin": false
}
```

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

### Update Password

```
PUT /api/profile/password
```

**Request Body:**

```json
{
  "current_password": "old_password",
  "password": "new_password",
  "password_confirmation": "new_password"
}
```

## Authorization

Some endpoints require admin privileges. These are marked with **Admin** in the API reference. Non-admin users will receive a `403 Forbidden` response.
