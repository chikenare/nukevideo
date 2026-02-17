import apiClient from './api'
import type { Template, CreateTemplateDto, UpdateTemplateDto } from '@/types/Template'

class TemplateService {
  private readonly BASE_PATH = '/templates'

  constructor(private api = apiClient) { }

  async index(): Promise<Template[]> {
    const res = await this.api.get(this.BASE_PATH)
    return res.data.data
  }

  async show(ulid: string): Promise<Template> {
    const res = await this.api.get(`${this.BASE_PATH}/${ulid}`)
    return res.data.data
  }

  async store(data: CreateTemplateDto): Promise<Template> {
    const res = await this.api.post(this.BASE_PATH, data)
    return res.data
  }

  async update(ulid: string, data: UpdateTemplateDto) {
    return this.api.put(`${this.BASE_PATH}/${ulid}`, data)
  }

  async destroy(ulid: string) {
    return this.api.delete(`${this.BASE_PATH}/${ulid}`)
  }

  async getCodecsConfig() {
    const res = await this.api.get('/templates-config')
    return res.data.data
  }
}

export default new TemplateService()
