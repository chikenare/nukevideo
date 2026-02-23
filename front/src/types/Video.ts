import type { OutputFormat } from './Template'

export enum VideoStatus {
  pending = 'pending',
  running = 'running',
  completed = 'completed',
  failed = 'failed',
  downloading = 'downloading',
  uploading = 'uploading'
}
type Video = {
  id: number
  ulid: string
  name: string
  duration: number
  status: VideoStatus
  size?: number
  thumbnailUrl: string | null
  storyboardUrl: string
  creatorId: string | null
  externalId: string | null
  outputFormat: OutputFormat
  createdAt: string

  streams: Stream[]
}

type UpdateVideoDto = {
  name: string
}


enum StreamType {
  video = 'video',
  audio = 'audio',
  subtitle = 'subtitle',
  original = 'original',
  download = 'download'
}

type Stream = {
  id: number
  ulid: string
  name: string | null
  type: StreamType
  status: VideoStatus
  size: number
  progress: number
  width: number | null
  height: number | null
  language: string | null
  channels: number | null
  errorLog: string | null
  startedAt: string | null
  completedAt: string | null
  createdAt: string | null
}

type UpdateStreamDto = Pick<Stream, 'name'>

export type { Video, UpdateVideoDto, Stream, UpdateStreamDto, StreamType }
