import apiClient from './api'

class StreamService {
  private readonly BASE_PATH = '/streams'

  constructor(private api = apiClient) { }

  async update(ulid: string, data: App.Data.Stream.UpdateStreamData): Promise<App.Data.StreamData> {
    const res = await this.api.put(`${this.BASE_PATH}/${ulid}`, data)
    return res.data.data
  }

  async destroy(ulid: string) {
    return this.api.delete(`${this.BASE_PATH}/${ulid}`)
  }
}

export default new StreamService()
