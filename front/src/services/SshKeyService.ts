import apiClient from './api'

class SshKeyService {
  private readonly BASE_PATH = '/ssh-keys'

  constructor(private api = apiClient) {}

  async getAll(): Promise<App.Data.SshKeyData[]> {
    const res = await this.api.get(this.BASE_PATH)
    return res.data.data
  }

  async create(payload: App.Data.SshKey.StoreSshKeyData): Promise<App.Data.SshKeyData> {
    const res = await this.api.post(this.BASE_PATH, payload)
    return res.data.data
  }

  async delete(id: number): Promise<void> {
    await this.api.delete(`${this.BASE_PATH}/${id}`)
  }
}

export default new SshKeyService()
