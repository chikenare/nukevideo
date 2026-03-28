import type { AnalyticsData } from '@/types/Analytics'
import apiClient from './api'

class AnalyticsService {
  private readonly BASE_PATH = '/analytics'

  constructor(private api = apiClient) {}

  async index(from: string, to: string): Promise<AnalyticsData> {
    const res = await this.api.get(this.BASE_PATH, {
      params: { from, to },
    })
    return res.data.data
  }
}

export default new AnalyticsService()
