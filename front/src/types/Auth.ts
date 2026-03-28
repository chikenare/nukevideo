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
  isAdmin: boolean
  roles: string[]
  permissions: string[]
}

export type CreateUserPayload = {
  name: string
  email: string
  password: string
  isAdmin: boolean
}

export type UpdateUserPayload = {
  name?: string
  email?: string
  password?: string
  isAdmin?: boolean
}
