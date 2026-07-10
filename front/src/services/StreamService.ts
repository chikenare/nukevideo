import apiClient from './api'

class StreamService {
  private readonly BASE_PATH = '/streams'

  constructor(private api = apiClient) { }

  async destroy(ulid: string) {
    return this.api.delete(`${this.BASE_PATH}/${ulid}`)
  }
}

export default new StreamService()
