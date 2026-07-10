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
  async login(payload: App.Data.Auth.LoginData) {

    await httpClient.get('/sanctum/csrf-cookie')
    const { data } = await httpClient.post('/login', payload)
    return data
  },

  async getUser(): Promise<App.Data.UserData> {
    const res = await apiClient.get('/me')
    return res.data.data
  },

  async logout() {
    await httpClient.post('/logout')
  },
}
