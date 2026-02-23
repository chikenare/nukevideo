export type NodeType = 'worker' | 'proxy'

export type NodeMetrics = {
  cpu_percent: number
  memory_usage: number
  memory_limit: number
  disk_read: number
  disk_write: number
  network_rx: number
  network_tx: number
}

export type Node = {
  id: number
  name: string
  type: NodeType
  baseUrl: string
  isActive: boolean
  status: string
  location: string
  uptime: string | null
  metrics: NodeMetrics | null
  lastSeenAt: string | null
}

export type NodesSummary = {
  totalCapacity: number
  availableSlots: number
}

export type NodesResponse = {
  nodes: Node[]
  summary: NodesSummary
}

export type CreateNodePayload = {
  name: string
  type: NodeType
  baseUrl: string
}

export type UpdateNodePayload = {
  name?: string
  type?: NodeType
  baseUrl?: string
  is_active?: boolean
  location?: string
}
