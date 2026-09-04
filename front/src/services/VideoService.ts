import type { Pagination } from '@/types/Pagination'
import apiClient from './api'

export type VideoSort = 'created_at' | 'name' | 'size' | 'duration' | 'status'

export type VideoIndexParams = {
  page?: number
  search?: string
  /** One status, or several comma-separated. */
  status?: string
  sort?: VideoSort
  direction?: 'asc' | 'desc'
}

class VideoService {
  private readonly BASE_PATH = '/videos'

  constructor(private api = apiClient) { }

  async index(params?: VideoIndexParams): Promise<Pagination<App.Data.VideoData>> {
    const res = await this.api.get(this.BASE_PATH, { params })
    return res.data
  }

  async show(ulid: string): Promise<App.Data.VideoData> {
    const res = await this.api.get(`${this.BASE_PATH}/${ulid}`)

    return res.data.data
  }

  async update(ulid: string, data: App.Data.Video.UpdateVideoData) {
    return this.api.put(`${this.BASE_PATH}/${ulid}`, data)
  }

  async destroy(ulid: string): Promise<void> {
    return this.api.delete(`${this.BASE_PATH}/${ulid}`)
  }

  // Every output of the video at once. The panel plays one of them, but the mint is keyed by
  // video, so picking happens here rather than in a second round trip.
  // Partial: the generated type spells every field as nullable-but-present, and each one is
  // optional on the wire.
  async play(ulid: string, params: Partial<App.Data.Video.PlayVideoData> = {}): Promise<App.Data.VideoVodLinksData> {
    const res = await this.api.post(`${this.BASE_PATH}/${ulid}/play`, params)
    return res.data.data
  }
}

export default new VideoService()
