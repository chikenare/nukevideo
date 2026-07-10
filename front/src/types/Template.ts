// Shape of the template `query` JSON column (untyped array on the backend).
export type TemplateQuery = {
  outputs: TemplateOutput[]
}

export type TemplateOutput = {
  video_codec: string
  variants: TemplateVariant[]
  audio: AudioConfig
}

export type TemplateVariant = Record<string, unknown>

export type AudioChannelEntry = {
  channels: string
  audio_bitrate: string
}

export type AudioConfig = {
  audio_codec?: string
  channels: AudioChannelEntry[]
  [key: string]: unknown
}
