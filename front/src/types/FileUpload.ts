export type FileUpload = {
  title: string
  externalUserId?: string
  externalResourceId?: string
  file: File
  thumbnail?: string
  progress: number
  status: 'pending' | 'success' | 'error' | 'paused'
  logError?: string
  uppyFileId?: string
}
