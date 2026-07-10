import apiClient from './api'

class AnalyticsService {
  private readonly BASE_PATH = '/analytics'

  constructor(private api = apiClient) {}

  async index(from: string, to: string): Promise<App.Data.Analytics.AnalyticsData> {
    const res = await this.api.get(this.BASE_PATH, {
      params: { from, to },
    })
    return res.data.data
  }

  async queueStatus(): Promise<Record<string, number>> {
    const res = await this.api.get(`${this.BASE_PATH}/queue`)
    return res.data.data
  }
}

export default new AnalyticsService()
