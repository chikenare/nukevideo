export type NodeType = 'worker' | 'proxy'

export type NodeMetrics = {
  cpu_percent: number
  memory_usage: number
  memory_total: number
  disk_usage: number
  disk_total: number
  load_average: [number, number, number]
  network_rx: number
  network_tx: number
}

export type Node = {
  id: number
  name: string
  ipAddress: string
  type: NodeType
  hostname?: string
  isActive: boolean
  status: string
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
  ip_address: string
  type: NodeType
}

export type UpdateNodePayload = {
  name?: string
  type?: NodeType
  hostname?: string
  is_active?: boolean
}
