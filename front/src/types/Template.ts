// Server response structure
import type { OutputFormat } from './Output'

export type Template = {
  ulid: string
  name: string
  query: {
    outputs: TemplateOutput[]
  }
  createdAt: string
}

export type TemplateOutput = {
  format: OutputFormat
  variants: TemplateVariant[]
  audio: AudioConfig
}

export type TemplateVariant = Record<string, unknown>

export type AudioChannelEntry = {
  channels: string
  audioBitrate: string
}

export type AudioConfig = {
  audioCodec?: string
  channels: AudioChannelEntry[]
  [key: string]: unknown
}

export type CreateTemplateDto = Omit<Template, 'ulid' | 'createdAt'>
export type UpdateTemplateDto = Omit<Template, 'ulid' | 'createdAt'>
