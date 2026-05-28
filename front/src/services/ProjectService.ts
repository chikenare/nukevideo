import apiClient from './api'
import type { Project, CreateProjectDto, UpdateProjectDto } from '@/types/Project'

class ProjectService {
  private readonly BASE_PATH = '/projects'

  constructor(private api = apiClient) { }

  async index(): Promise<Project[]> {
    const res = await this.api.get(this.BASE_PATH)
    return res.data.data
  }

  async show(ulid: string): Promise<Project> {
    const res = await this.api.get(`${this.BASE_PATH}/${ulid}`)
    return res.data.data
  }

  async store(data: CreateProjectDto): Promise<Project> {
    const res = await this.api.post(this.BASE_PATH, data)
    return res.data
  }

  async update(ulid: string, data: UpdateProjectDto) {
    return this.api.put(`${this.BASE_PATH}/${ulid}`, data)
  }

  async destroy(ulid: string) {
    return this.api.delete(`${this.BASE_PATH}/${ulid}`)
  }
}

export default new ProjectService()
