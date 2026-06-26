# Templates

Templates define how videos are encoded. They specify which streams to create (video, audio, muxed), their codecs, resolutions, bitrates, and other FFmpeg parameters.

## Overview

Every video in NukeVideo is processed according to a template. Templates are reusable — create one template and apply it to multiple videos.

A template contains a JSON `query` that describes the encoding configuration for all output streams.

## Template Structure

A template has two fields:

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Display name for the template |
| `query` | JSON | Encoding configuration |

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
| `GET` | `/api/templates` | List all templates |
| `POST` | `/api/templates` | Create a template |
| `GET` | `/api/templates/{id}` | Get a template |
| `PUT` | `/api/templates/{id}` | Update a template |
| `DELETE` | `/api/templates/{id}` | Delete a template |
| `GET` | `/api/template-presets` | List available presets |
| `POST` | `/api/template-presets/{slug}/adopt` | Adopt a preset |
| `GET` | `/api/templates-config` | Get encoding config options |
