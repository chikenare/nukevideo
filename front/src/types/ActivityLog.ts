type ActivityLog = {
  id: number
  logName: string
  description: string
  subjectType: string | null
  subjectId: number | null
  causerType: string | null
  causerId: number | null
  event: string | null
  properties: Record<string, unknown>
  createdAt: string
  updatedAt: string
}

export type { ActivityLog }
