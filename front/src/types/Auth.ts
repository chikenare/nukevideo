export type LoginPayload = {
  email: string
  password: string
}

export type RegisterPayload = {
  name: string
  email: string
  password: string
}

export type User = {
  id: number
  name: string
  email: string
  roles: string[]
  permissions: string[]
}
