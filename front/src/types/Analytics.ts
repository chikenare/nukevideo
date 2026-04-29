export interface AnalyticsCard {
  label: string
  value: number
  format: 'bytes' | 'number' | 'seconds'
}

export interface BandwidthOverTime {
  date: string
  bytes: number
  sessions: number
}

export interface TopIp {
  ip: string
  bytes: number
  sessions: number
}

export interface TopVideo {
  video: string
  externalResourceId: string
  bytes: number
  sessions: number
  uniqueIps: number
}

export interface BandwidthByVideo {
  date: string
  video: string
  bytes: number
}

export interface EncodingOverTime {
  date: string
  device: string
  seconds: number
}

export interface TopExternalUser {
  externalUserId: string
  bytes: number
}

export interface AnalyticsData {
  cards: AnalyticsCard[]
  bandwidthOverTime: BandwidthOverTime[]
  topIps: TopIp[]
  topVideos: TopVideo[]
  topExternalUsers: TopExternalUser[]
  bandwidthByVideo: BandwidthByVideo[]
  encodingOverTime: EncodingOverTime[]
}
