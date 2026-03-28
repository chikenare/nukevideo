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
