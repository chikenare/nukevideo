import apiClient from './api'

class ApiTokenService {
  async getTokens(): Promise<App.Data.ApiTokenData[]> {
    const res = await apiClient.get('/tokens')
    return res.data.data
  }

  async createToken(name: string): Promise<App.Data.ApiTokenData & { token: string }> {
    const res = await apiClient.post('/tokens', { name })
    return res.data.data
  }

  async deleteToken(id: number): Promise<void> {
    await apiClient.delete(`/tokens/${id}`)
  }
}

export default new ApiTokenService()
