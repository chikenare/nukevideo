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

Delivery bandwidth, viewer IPs and encoding metrics, read from ClickHouse. This is the read side of
the CDN access logs: the self-hosted edges ship them through Vector and Bunny pull zones are polled
by `bunny:ingest-logs` ([CDN & Delivery](/guide/cdn#how-the-log-ingestion-works)), and both land in
the same table.

```
GET /api/analytics?from=2026-04-01&to=2026-04-30
```

Readable with **any authenticated token** — a personal access token or a
[project API key](/api/authentication) — since reading these numbers back is what an integrating
backend holds a key for.

::: warning Instance-wide figures
Bandwidth, videos and viewer IPs are aggregated across the **whole instance**, not scoped to the
calling project: a project key sees totals that include other projects' traffic. NukeVideo is meant
to sit behind your own backend, reached server to server with a key that never leaves it — do not
proxy this endpoint to a browser or to an untrusted tenant. Use the `video` and `tracking_id` filters below
to narrow a response to something you can safely pass on.
:::

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `from` | date | Yes | Start date (`YYYY-MM-DD`), inclusive |
| `to` | date | Yes | End date (`YYYY-MM-DD`), inclusive |
| `user_id` | integer | No | Whose *upload* volume to report in `topExternalUsers` and the Upload Volume card. Does not affect bandwidth |
| `video` | string | No | Narrow every bandwidth series to one video ULID |
| `tracking_id` | string | No | Narrow every bandwidth series to one tracking id. Pass it **empty** (`?tracking_id=`) to isolate traffic that carried no id |
| `metric` | string | No | Narrow to one kind of delivery: `streaming_bytes`, `download_bytes`, `asset_bytes` or `bandwidth_bytes`. Omit for all of them |

`video` and `tracking_id` are matched against columns written from CDN access logs, so they are validated to
what those columns can hold — a 26-character ULID and up to 64 characters of `A-Z a-z 0-9 _ -`
respectively. `metric` only accepts the four delivery metrics above: the same table also stores
upload volume and encoding seconds, and reporting those as bytes would be nonsense. Anything else
responds `422`.

**Response:**

```json
{
  "data": {
    "cards": [{ "label": "Total Bandwidth", "value": 5368709120, "format": "bytes" }],
    "bandwidthOverTime": [{ "date": "2026-04-16", "bytes": 1073741824, "sessions": 42 }],
    "topIps": [{ "ip": "203.0.113.7", "bytes": 734003200, "sessions": 3 }],
    "topVideos": [{ "video": "01J...", "externalResourceId": "", "bytes": 2147483648, "sessions": 88, "uniqueIps": 71 }],
    "topExternalUsers": [{ "externalUserId": "user-123", "bytes": 52428800 }],
    "topTrackingIds": [{ "trackingId": "customer-42", "bytes": 943718400, "videos": 3, "uniqueIps": 11 }],
    "bandwidthByVideo": [{ "date": "2026-04-16", "video": "01J...", "bytes": 536870912 }],
    "encodingOverTime": [{ "date": "2026-04-16", "device": "cpu", "seconds": 1820.5 }]
  }
}
```

### Bandwidth by tracking id

`topTrackingIds` is the read side of the `tracking_id` you mint links with
([Download a Track](/api/streams#download-a-track)) — it is how an external project bills or
reports the transfers it handed to each of its own customers.

| Field | Type | Notes |
|-------|------|-------|
| `trackingId` | string | The id the link carried. **Empty** for traffic that carried none — links minted by a project key without a `tracking_id`, Bunny playback (whose token leaves no room for one), and ids that reached the log malformed. Links minted from a session or personal token without a `tracking_id` carry the authenticated user's ULID, so the panel's own traffic shows up under it. Reported rather than dropped, so the rows add up to `Total Bandwidth` |
| `bytes` | number | Bytes served |
| `videos` | integer | Distinct videos this id pulled |
| `uniqueIps` | integer | Distinct client IPs. Approximate when the CDN anonymizes log IPs |

::: tip Bytes are never lost to a bad label
A tracking id that does not survive the round trip through the CDN log costs its attribution, not
its bytes: the transfer is still counted, under the empty id. Bandwidth totals are always complete,
whatever happens to the labels on top of them.
:::

To read one customer's traffic on one video across a month:

```
GET /api/analytics?from=2026-04-01&to=2026-04-30&video=01J...&tracking_id=customer-42
```

Every bandwidth series in the response — the cards, `bandwidthOverTime`, `topIps`, `topVideos`,
`bandwidthByVideo` — is narrowed by the same filter, so the whole payload describes that one slice.
`topExternalUsers` and the encoding series come from upload metrics instead and are not affected.

::: tip
Bandwidth is only as complete as the ingestion behind it: a Bunny pull zone is polled every five
minutes over a window that trails two minutes behind, so the last few minutes of traffic are
normally not in yet.
:::

## Usage

Query usage metrics for the authenticated account. This is the primary endpoint for external projects to track per-user consumption.

```
GET /api/usage
```

Readable with a personal access token or a project API key. A project key resolves to the account
that **owns** the project, so the figures it returns are that account's across all of its projects —
not the calling project's alone.

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
| `streaming_bytes` | bytes | Delivered to players — manifests and CMAF segments |
| `download_bytes` | bytes | Delivered through [track download links](/api/streams#download-a-track) |
| `asset_bytes` | bytes | Thumbnails and storyboards |
| `bandwidth_bytes` | bytes | Delivery that predates the streaming/download split, or whose path zone could not be read |

::: warning The unit lives in the metric name
`value` is one shared column: **bytes** for every metric above except `encoding_cpu`, which is
**seconds**. Always constrain or group by `metric` — summing across all of them adds seconds to
bytes and returns a number that means nothing.
:::

Delivery metrics are attributed to `external_user_id` server-side, resolved from the video the
request was for. Nothing about the attribution travels in the URL, so a viewer cannot alter it.

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
