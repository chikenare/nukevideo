import type { Stream, VideoStatus } from './Video'

export type OutputFormat = 'hls' | 'dash'

export type Output = {
  ulid: string
  format: OutputFormat
  status: VideoStatus
  progress: number
  streams: Stream[]
  createdAt: string
}
