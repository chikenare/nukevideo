export type LoginPayload = {
  email: string
  password: string
}

export type RegisterPayload = {
  name: string
  email: string
  password: string
}

import type { Project } from './Project'

export type User = {
  id: number
  name: string
  email: string
  isAdmin: boolean
  roles: string[]
  permissions: string[]
  projects?: Project[]
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
