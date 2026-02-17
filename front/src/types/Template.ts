// Server response structure - flat parameters
export type OutputFormat = 'hls' | 'mp4' | 'mkv'

export type Template = {
  ulid: string
  name: string
  query: {
    output_format: OutputFormat
    variants: TemplateVariant[]
  }
  createdAt: string
}

export type TemplateVariant = Record<string, unknown>

export type CreateTemplateDto = Omit<Template, 'ulid' | 'createdAt'>
export type UpdateTemplateDto = Omit<Template, 'ulid' | 'createdAt'>
