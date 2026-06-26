export type NodeType = 'worker' | 'proxy'

export type ServiceStatus = {
  name: string
  running: number
  desired: number | null
  state: 'running' | 'degraded' | 'down'
}

export type Node = {
  id: number
  name: string
  user: string
  ipAddress: string
  type: NodeType
  hostname?: string
  isActive: boolean
  cdnMode: boolean
  isStorageServer: boolean
  storageEndpoint?: string
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

export type BootstrapToken = {
  command: string
}

export type CreateNodePayload = {
  name: string
  user: string
  ipAddress: string
  type: NodeType
  hostname?: string
  sshKeyId?: number
  cdnMode?: boolean
  isStorageServer?: boolean
  storageEndpoint?: string
}