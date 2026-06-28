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
  original = 'original',
}

type Stream = {
  id: number
  ulid: string
  name: string | null
  type: StreamType
  size: number
  width: number | null
  height: number | null
  language: string | null
  channels: number | null
  meta: Record<string, unknown> | null
  inputParams: Record<string, unknown> | null
  errorLog: string | null
  createdAt: string | null
}

type UpdateStreamDto = Pick<Stream, 'name'> & Partial<Pick<Stream, 'language'>>

export type { Video, UpdateVideoDto, Stream, UpdateStreamDto, StreamType }
