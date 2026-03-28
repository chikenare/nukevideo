import type { Pagination } from '@/types/Pagination'
import type { ActivityLog } from '@/types/ActivityLog'
import apiClient from './api'

class ActivityLogService {
  private readonly BASE_PATH = '/activity-log'

  constructor(private api = apiClient) {}

  async index(page = 1): Promise<Pagination<ActivityLog>> {
    const res = await this.api.get(this.BASE_PATH, { params: { page } })
    return res.data
  }
}

export default new ActivityLogService()
