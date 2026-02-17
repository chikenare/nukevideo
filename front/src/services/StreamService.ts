import type { UpdateStreamDto } from '@/types/Video'
import apiClient from './api'

class StreamService {
  private readonly BASE_PATH = '/streams'

  constructor(private api = apiClient) { }

  async update(ulid: string, data: UpdateStreamDto) {
    return this.api.put(`${this.BASE_PATH}/${ulid}`, data)
  }

  async destroy(ulid: string) {
    return this.api.delete(`${this.BASE_PATH}/${ulid}`)
  }
}

export default new StreamService()
