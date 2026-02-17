export type FileUpload = {
  title: string
  creatorId?: string
  externalId?: string
  file: File
  thumbnail?: string
  progress: number
  status: 'pending' | 'success' | 'error' | 'paused'
  logError?: string
  uppyFileId?: string
}
