import type { LoginPayload, RegisterPayload, User } from '@/types/Auth'
import apiClient from './api'
import axios from 'axios'

const httpClient = axios.create({
  baseURL: import.meta.env.VITE_URL_APP_BASE,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
    'Accept': 'application/json',
  }
})

export default {
  async login(payload: LoginPayload) {

    await httpClient.get('/sanctum/csrf-cookie')
    const { data } = await httpClient.post('/login', payload)
    return data
  },

  async register(payload: RegisterPayload) {
    const { data } = await httpClient.post('/register', payload)
    return data
  },

  async getUser(): Promise<User> {
    const res = await apiClient.get('/me')
    return res.data.data
  },

  async logout() {
    await httpClient.post('/logout')
  },
}
