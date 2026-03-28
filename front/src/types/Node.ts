export type NodeType = 'worker' | 'proxy'
export type Workload = 'light' | 'medium' | 'heavy'

export type NodeInstance = {
  nanoCpus: number
  memoryBytes: number
  workload?: Workload | null
}

export type NodeMetrics = {
  cpu: {
    load1: number
    load5: number
    load15: number
  }
  memory: {
    total: number
    used: number
    percent: number
  }
  disk: {
    readBytes: number
    writtenBytes: number
  }
  network: {
    rxBytes: number
    txBytes: number
  }
}

export type ServiceStatus = {
  name: string
  running: number
  desired: number | null
  state: 'running' | 'degraded' | 'down'
}

export type DockerContainer = {
  Names: string
  State: string
  Status: string
  Image: string
  ID: string
}

export type Node = {
  id: number
  name: string
  user: string
  ipAddress: string
  type: NodeType
  instances: NodeInstance[] | null
  hostname?: string
  isActive: boolean
  metrics: NodeMetrics | null
  services: ServiceStatus[]
  sshKeyId: number | null
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
  ipAddress: string
  type: NodeType
  hostname?: string
  sshKeyId?: number
  instances?: NodeInstance[]
}