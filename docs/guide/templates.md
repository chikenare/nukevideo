# Templates

Templates define how videos are encoded. They specify which streams to create (video, audio, muxed), their codecs, resolutions, bitrates, and other FFmpeg parameters.

## Overview

Every video in NukeVideo is processed according to a template. Templates are reusable — create one template and apply it to multiple videos.

A template contains a JSON `query` that describes the encoding configuration for all output streams.

## Template Structure

A template has these fields:

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Display name for the template |
| `query` | JSON | Encoding configuration |
| `enabled` | bool | Whether new uploads may select it (default `true`) |

The `query` field is a JSON object that defines the streams and output formats for the video.

## Presets

NukeVideo includes built-in presets for common use cases. You can adopt a preset to quickly create a template without manually configuring the encoding parameters.

```
GET /api/template-presets
```

To adopt a preset:

```
POST /api/template-presets/{slug}/adopt
```

This creates a new template in your account based on the preset configuration.

## Usage

### Create a Template

```
POST /api/templates
Content-Type: application/json

{
  "name": "720p + 1080p HLS",
  "query": { ... }
}
```

### Retire a Template

A template that videos were encoded with cannot be deleted — those videos reference it. Disable it
instead: it stops being offered to new uploads, and the API rejects it if one names it anyway.
`GET /api/templates` keeps listing it — pass `?enabled=true` to get only the selectable ones.
Existing videos are untouched, and it can be re-enabled at any time.

```
PATCH /api/templates/{id}
Content-Type: application/json

{ "enabled": false }
```

### Duplicate a Template

Fork a working encoding profile instead of rebuilding it output by output. The copy is named
`<name> (copy)` and is fully independent of the original.

```
POST /api/templates/{id}/duplicate
```

### Order the List

`GET /api/templates` returns templates in the order you arranged them (drag and drop in the panel),
per project. To store a new order, send the **full** list of ULIDs — the API renumbers from it:

```
POST /api/templates/reorder
Content-Type: application/json

{ "ulids": ["01HX...", "01HY...", "01HZ..."] }
```

### Apply to a Video

When creating or updating a video, assign a template by its ID. The template determines which streams will be created during processing.

### Template Configuration

You can retrieve the available encoding configuration options:

```
GET /api/templates-config
```

This returns the available codecs, presets, and parameters that can be used in template queries.

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/templates` | List all templates (`?enabled=true` for the selectable ones) |
| `POST` | `/api/templates` | Create a template |
| `GET` | `/api/templates/{id}` | Get a template |
| `PUT` | `/api/templates/{id}` | Update a template |
| `DELETE` | `/api/templates/{id}` | Delete a template |
| `POST` | `/api/templates/{id}/duplicate` | Duplicate a template |
| `POST` | `/api/templates/reorder` | Store the display order |
| `GET` | `/api/template-presets` | List available presets |
| `POST` | `/api/template-presets/{slug}/adopt` | Adopt a preset |
| `GET` | `/api/templates-config` | Get encoding config options |
