# Templates

Templates define how videos are encoded. They specify which outputs to produce, and for each one its
video codec, its ladder of renditions (resolutions, quality and bitrate settings), and its audio.

## Overview

Every video in NukeVideo is processed according to a template. Templates are reusable — create one template and apply it to multiple videos.

A template contains a JSON `query` that describes the encoding configuration for all outputs.

## Template Structure

A template has these fields:

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Display name for the template |
| `query` | JSON | Encoding configuration |
| `enabled` | bool | Whether new uploads may select it (default `true`) |
| `keepProcessedFiles` | bool | Keep the raw video and audio renditions in S3 next to the CMAF package, so they can be [downloaded](/api/streams#download-a-track) (default `true`). Off, only the package is stored. |
| `keepOriginal` | bool | Keep the uploaded source after a successful encode, archived under the video's prefix (default `false`). Off, it is deleted once the video completes. |

The `query` field is a JSON object with an `outputs` array. Each output becomes one CMAF package
(one set of HLS/DASH manifests) and takes:

| Key | Description |
|-----|-------------|
| `video_codec` | The encoder for every rendition of this output (`libx264`, `libx265`, `libsvtav1`, `h264_qsv`, `hevc_qsv`, `av1_qsv`, `h264_nvenc`, `hevc_nvenc`, `av1_nvenc`). Different codecs go in different outputs. |
| `variants` | The rendition ladder: one object per rendition with `width`, `height` and the codec's parameters (`crf`, `preset`, `maxrate`, `target_vmaf`, …). |
| `audio` | `audio_codec` (`aac`, `libfdk_aac` or `libopus`), shared audio parameters, and a `channels` ladder of `{ "channels": "2", "audio_bitrate": "128k" }` entries. |

```json
{
  "outputs": [
    {
      "video_codec": "libx264",
      "variants": [
        { "width": 1920, "height": 1080, "crf": 23, "preset": "medium", "maxrate": "5000k", "bufsize": "10000k" },
        { "width": 1280, "height": 720, "crf": 23, "preset": "medium", "maxrate": "2500k", "bufsize": "5000k" }
      ],
      "audio": {
        "audio_codec": "aac",
        "channels": [{ "channels": "2", "audio_bitrate": "128k" }]
      }
    }
  ]
}
```

The template is a ceiling, not a promise: renditions are never upscaled past the source, and audio
is never upmixed past the source track's channels. Whether an output is served as HLS, DASH or both
follows from its codecs — H.264, H.265, AV1 and AAC package for both, Opus for DASH only.

The parameters each codec accepts, with their validation rules, come from
[`GET /api/templates-config`](#template-configuration). Keys in `query` are snake_case, since they
are stored as written.

## Presets

NukeVideo includes built-in presets for common use cases. You can adopt a preset to quickly create a template without manually configuring the encoding parameters.

| Slug | Name |
|------|------|
| `hls-h264-multi` | HLS (H.264) — 1080p/720p/480p, stereo AAC |
| `hls-hevc-4k` | HLS 4K Premium (H.265) — up to 2160p, 5.1 and stereo AAC |
| `dash-av1-efficient` | DASH AV1 — SVT-AV1, Opus |
| `dash-av1-qsv` | DASH AV1 (Intel QSV) — hardware AV1, Opus |
| `hls-h264-mobile` | HLS Mobile-First (H.264) — 720p and below |

```
GET /api/template-presets
```

To adopt a preset:

```
POST /api/template-presets/{slug}/adopt
```

This creates a new template in the current project based on the preset configuration.

## Usage

### Create a Template

```
POST /api/templates
Content-Type: application/json

{
  "name": "720p + 1080p",
  "query": { "outputs": [ ... ] }
}
```

### Retire a Template

A template that videos were encoded with cannot be deleted — those videos reference it. Disable it
instead: it stops being offered to new uploads, and the API rejects it if one names it anyway.
`GET /api/templates` keeps listing it — pass `?enabled=true` to get only the selectable ones.
Existing videos are untouched, and it can be re-enabled at any time.

```
PATCH /api/templates/{ulid}
Content-Type: application/json

{ "enabled": false }
```

### Duplicate a Template

Fork a working encoding profile instead of rebuilding it output by output. The copy is named
`<name> (copy)` and is fully independent of the original.

```
POST /api/templates/{ulid}/duplicate
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

The template is chosen at upload time, by passing its ULID as `metadata.template` when the upload is
created (see [Upload Metadata](/api/videos#upload-metadata)). It must be an enabled template of the
same project, and it cannot be changed afterwards.

### Template Configuration

You can retrieve the available encoding configuration options:

```
GET /api/templates-config
```

This returns the codec catalogue (`codecs`) and every encoding parameter (`parameters`) with its
input type, options, validation rules and the codecs it is `availableFor`.

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/templates` | List all templates (`?enabled=true` for the selectable ones) |
| `POST` | `/api/templates` | Create a template |
| `GET` | `/api/templates/{ulid}` | Get a template |
| `PUT`/`PATCH` | `/api/templates/{ulid}` | Update a template |
| `DELETE` | `/api/templates/{ulid}` | Delete a template |
| `POST` | `/api/templates/{ulid}/duplicate` | Duplicate a template |
| `POST` | `/api/templates/reorder` | Store the display order |
| `GET` | `/api/template-presets` | List available presets |
| `POST` | `/api/template-presets/{slug}/adopt` | Adopt a preset |
| `GET` | `/api/templates-config` | Get encoding config options |
