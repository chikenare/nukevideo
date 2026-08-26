import apiClient from './api'

class AppSettingsService {
  private readonly BASE_PATH = '/app-settings'

  constructor(private api = apiClient) {}

  async get(): Promise<App.Data.AppSettingsData> {
    const res = await this.api.get(this.BASE_PATH)
    return res.data.data
  }

  /** Replace the SSH key: with the private key given, or a freshly generated pair when omitted. */
  async setSshKey(payload: App.Data.AppSettings.UpdateSshKeyData): Promise<App.Data.AppSettingsData> {
    const res = await this.api.put(`${this.BASE_PATH}/ssh-key`, payload)
    return res.data.data
  }

  /** Install a new key on every node with the current one, verify it, then switch. */
  async rotateSshKey(): Promise<App.Data.AppSettings.SshKeyRotationData> {
    const res = await this.api.post(`${this.BASE_PATH}/ssh-key/rotate`)
    return res.data.data
  }
}

export default new AppSettingsService()
