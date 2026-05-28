export type NodeType = 'worker' | 'proxy'

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
  workers: number
  hostname?: string
  isActive: boolean
  hasGpu: boolean
  cdnMode: boolean
  metrics: NodeMetrics | null
  services: ServiceStatus[]
  sshKeyId: number | null
  env: string | null
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

export type ValidationCheck = {
  key: string
  label: string
  status: 'ok' | 'warning' | 'error'
  output: string
}

export type CreateNodePayload = {
  name: string
  user: string
  ipAddress: string
  type: NodeType
  hostname?: string
  sshKeyId?: number
  hasGpu?: boolean
  cdnMode?: boolean
  workers?: number
}