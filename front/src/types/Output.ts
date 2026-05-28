import type { Stream } from './Video'

export type OutputFormat = 'hls' | 'dash' | 'mp4'

export type Output = {
  ulid: string
  format: OutputFormat
  streams: Stream[]
  createdAt: string
}
