# Templates

Templates define encoding configurations for videos. See the [Templates guide](/guide/templates) for more details.

## List Templates

```
GET /api/templates
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `enabled` | boolean | Only templates in that state. Omit it to list all of them, retired included. |

Pass `enabled=true` when building an upload picker: those are the templates the upload endpoints
still accept.

**Response:**

```json
{
  "data": [
    {
      "id": 1,
      "ulid": "01HX...",
      "name": "720p + 1080p HLS",
      "query": { ... },
      "enabled": true,
      "created_at": "2025-01-15T10:30:00Z"
    }
  ]
}
```

Templates come back in the order stored for the project (see [Reorder](#reorder-templates)), not
newest first.

## Get Template

```
GET /api/templates/{id}
```

## Create Template

```
POST /api/templates
```

**Request Body:**

```json
{
  "name": "My Template",
  "query": { ... }
}
```

## Update Template

```
PUT /api/templates/{id}
```

**Request Body:**

```json
{
  "name": "Updated Template",
  "query": { ... }
}
```

### Enable / Disable

A disabled template stays in the list and keeps serving the videos already encoded with it, but new
uploads may no longer select it — the upload endpoints reject it. This is how a template that videos
reference (and therefore cannot be deleted) is retired.

```
PATCH /api/templates/{id}
```

```json
{ "enabled": false }
```

## Duplicate Template

Create an independent copy, named `<name> (copy)`, with the same query and retention flags:

```
POST /api/templates/{id}/duplicate
```

## Reorder Templates

Store the order templates are listed in for this project. Send the **complete** list of ULIDs in the
order you want; every ULID must belong to the calling project, or the request is rejected with a
`422` and nothing is written.

```
POST /api/templates/reorder
```

**Request Body:**

```json
{
  "ulids": ["01HX...", "01HY...", "01HZ..."]
}
```

**Response:** the full template list in the new order.

## Delete Template

```
DELETE /api/templates/{id}
```

Refused with a `422` while any video references the template. Disable it instead.

## Presets

### List Presets

Get built-in encoding presets:

```
GET /api/template-presets
```

### Adopt Preset

Create a template from a preset:

```
POST /api/template-presets/{slug}/adopt
```

## Encoding Configuration

Get available encoding options (codecs, resolutions, profiles):

```
GET /api/templates-config
```
