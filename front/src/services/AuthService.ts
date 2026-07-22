import apiClient from './api'

export default {
  async login(payload: App.Data.Auth.LoginData) {
    await apiClient.get('/csrf-cookie')
    const { data } = await apiClient.post('/login', payload)
    return data
  },

  async getUser(): Promise<App.Data.UserData> {
    const res = await apiClient.get('/me')
    return res.data.data
  },

  async logout() {
    await apiClient.post('/logout')
  },
}
