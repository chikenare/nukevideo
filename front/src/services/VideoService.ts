import type { Pagination } from '@/types/Pagination'
import apiClient from './api'
import type { UpdateVideoDto, Video } from '@/types/Video'

class VideoService {
  private readonly BASE_PATH = '/videos'

  constructor(private api = apiClient) { }

  async index(): Promise<Pagination<Video>> {
    const res = await this.api.get(this.BASE_PATH)
    return res.data
  }

  async show(ulid: string): Promise<Video> {
    const res = await this.api.get(`${this.BASE_PATH}/${ulid}`)

    return res.data.data
  }

  async update(ulid: string, data: UpdateVideoDto) {
    return this.api.put(`${this.BASE_PATH}/${ulid}`, data)
  }

  async destroy(ulid: string): Promise<void> {
    return this.api.delete(`${this.BASE_PATH}/${ulid}`)
  }

  async getVideoLink(ulid: string): Promise<{ url: string, type: string }> {
    const res = await this.api.get(`/vod/link/${ulid}`)
    return res.data
  }
}

export default new VideoService()
