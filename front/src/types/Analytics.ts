export interface AnalyticsSummary {
  totalBytes: number
  totalRequests: number
  uniqueVideos: number
  uniqueIps: number
}

export interface BandwidthOverTime {
  date: string
  bytes: number
  requests: number
}

export interface TopIp {
  ip: string
  bytes: number
  requests: number
}

export interface TopVideo {
  video: string
  extid: string
  bytes: number
  requests: number
  uniqueIps: number
}

export interface BandwidthByVideo {
  date: string
  video: string
  bytes: number
}

export interface AnalyticsData {
  nodeCount: number
  summary: AnalyticsSummary
  bandwidthOverTime: BandwidthOverTime[]
  topIps: TopIp[]
  topVideos: TopVideo[]
  bandwidthByVideo: BandwidthByVideo[]
}
