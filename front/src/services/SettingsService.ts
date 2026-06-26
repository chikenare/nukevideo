import type { GeneralSettings, PublicSettings } from '@/types/Settings'
import apiClient from './api'

class SettingsService {
  private readonly BASE_PATH = '/settings'

  constructor(private api = apiClient) {}

  async getAll(): Promise<GeneralSettings> {
    const res = await this.api.get(this.BASE_PATH)
    return res.data.data
  }

  async update(payload: Partial<GeneralSettings>): Promise<GeneralSettings> {
    const res = await this.api.patch(this.BASE_PATH, payload)
    return res.data.data
  }

  async versionCheck(): Promise<{ current: string; latest: string; behind: number }> {
    const res = await this.api.get(`${this.BASE_PATH}/version`)
    return res.data.data
  }

  async getPublic(): Promise<PublicSettings> {
    const res = await this.api.get(`${this.BASE_PATH}/public`)
    return res.data.data
  }
}

export default new SettingsService()
