export type ApiToken = {
  id: number
  name: string
  abilities: string[]
  lastUsedAt: string | null
  createdAt: string
  expiresAt: string | null
}

export type NewApiToken = ApiToken & {
  token: string
}
