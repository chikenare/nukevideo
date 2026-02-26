import type { SshKey, CreateSshKeyPayload } from '@/types/SshKey'
import apiClient from './api'

class SshKeyService {
  private readonly BASE_PATH = '/ssh-keys'

  constructor(private api = apiClient) {}

  async getAll(): Promise<SshKey[]> {
    const res = await this.api.get(this.BASE_PATH)
    return res.data.data
  }

  async create(payload: CreateSshKeyPayload): Promise<SshKey> {
    const res = await this.api.post(this.BASE_PATH, payload)
    return res.data.data
  }

  async delete(id: number): Promise<void> {
    await this.api.delete(`${this.BASE_PATH}/${id}`)
  }
}

export default new SshKeyService()
