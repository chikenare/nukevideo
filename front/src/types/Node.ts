export type NodeType = 'worker' | 'proxy'

export type Node = {
  id: number
  name: string
  type: NodeType
  host: string
  isActive: boolean
  maxWorkers: number
  currentLoad: number
  availableCapacity: number
  latitude: number | null
  longitude: number | null
  country: string | null
  city: string | null
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
  host: string
  max_workers: number
}

export type UpdateNodePayload = {
  name?: string
  type?: NodeType
  host?: string
  max_workers?: number
  is_active?: boolean
  latitude?: number | null
  longitude?: number | null
  country?: string | null
  city?: string | null
}
