// Server response structure
export type Template = {
  ulid: string
  name: string
  keepProcessedFiles: boolean
  query: {
    outputs: TemplateOutput[]
  }
  commands: string[]
  createdAt: string
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

export type CreateTemplateDto = Omit<Template, 'ulid' | 'createdAt'>
export type UpdateTemplateDto = Omit<Template, 'ulid' | 'createdAt'>

export type TemplatePreset = {
  slug: string
  name: string
  description: string
  category: string
  query: {
    outputs: TemplateOutput[]
  }
}
