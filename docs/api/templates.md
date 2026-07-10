# Templates

Templates define encoding configurations for videos. See the [Templates guide](/guide/templates) for more details.

## List Templates

```
GET /api/templates
```

**Response:**

```json
{
  "data": [
    {
      "id": 1,
      "ulid": "01HX...",
      "name": "720p + 1080p HLS",
      "query": { ... },
      "created_at": "2025-01-15T10:30:00Z"
    }
  ]
}
```

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

## Delete Template

```
DELETE /api/templates/{id}
```

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
