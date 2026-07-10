import apiClient from './api'

class SettingsService {
  private readonly BASE_PATH = '/settings'

  constructor(private api = apiClient) {}

  async versionCheck(): Promise<{ current: string; latest: string; behind: number }> {
    const res = await this.api.get(`${this.BASE_PATH}/version`)
    return res.data.data
  }
}

export default new SettingsService()
