export type ProjectSettings = {
  webhookUrl?: string | null
  webhookSecret?: string | null
}

export type Project = {
  ulid: string
  name: string
  settings: ProjectSettings | null
  createdAt: string
  updatedAt: string
}

export type CreateProjectDto = {
  name: string
  settings?: ProjectSettings
}

export type UpdateProjectDto = {
  name?: string
  settings?: ProjectSettings
}
