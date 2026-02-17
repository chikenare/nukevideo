import type { ApiToken, NewApiToken } from '@/types/ApiToken'
import apiClient from './api'

class ApiTokenService {
  async getTokens(): Promise<ApiToken[]> {
    const res = await apiClient.get('/tokens')
    return res.data.data
  }

  async createToken(name: string): Promise<NewApiToken> {
    const res = await apiClient.post('/tokens', { name })
    return res.data.data
  }

  async deleteToken(id: number): Promise<void> {
    await apiClient.delete(`/tokens/${id}`)
  }
}

export default new ApiTokenService()
