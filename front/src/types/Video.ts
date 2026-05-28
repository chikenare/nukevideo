export enum VideoStatus {
  pending = 'pending',
  running = 'running',
  completed = 'completed',
  failed = 'failed',
  downloading = 'downloading',
  uploading = 'uploading'
}

import type { Output } from './Output'
type Video = {
  id: number
  ulid: string
  name: string
  duration: number
  status: VideoStatus
  size?: number
  thumbnailUrl: string | null
  storyboardUrl: string
  externalUserId: string | null
  externalResourceId: string | null
  createdAt: string

  streams: Stream[]
  outputs: Output[]
}

type UpdateVideoDto = {
  name: string
}


enum StreamType {
  video = 'video',
  audio = 'audio',
  subtitle = 'subtitle',
  muxed = 'muxed',
  original = 'original',
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
  meta: Record<string, unknown> | null
  errorLog: string | null
  startedAt: string | null
  completedAt: string | null
  createdAt: string | null
}

type UpdateStreamDto = Pick<Stream, 'name'>

export type { Video, UpdateVideoDto, Stream, UpdateStreamDto, StreamType }
