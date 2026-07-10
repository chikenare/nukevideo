import apiClient from './api'

class TemplateService {
  private readonly BASE_PATH = '/templates'

  constructor(private api = apiClient) { }

  async index(): Promise<App.Data.TemplateData[]> {
    const res = await this.api.get(this.BASE_PATH)
    return res.data.data
  }

  async show(ulid: string): Promise<App.Data.TemplateData> {
    const res = await this.api.get(`${this.BASE_PATH}/${ulid}`)
    return res.data.data
  }

  async store(data: App.Data.Template.StoreTemplateData): Promise<App.Data.TemplateData> {
    const res = await this.api.post(this.BASE_PATH, data)
    return res.data
  }

  async update(ulid: string, data: App.Data.Template.UpdateTemplateData) {
    return this.api.put(`${this.BASE_PATH}/${ulid}`, data)
  }

  async destroy(ulid: string) {
    return this.api.delete(`${this.BASE_PATH}/${ulid}`)
  }

  async getCodecsConfig() {
    const res = await this.api.get('/templates-config')
    return res.data.data
  }

  async presets(): Promise<App.Data.TemplatePresetData[]> {
    const res = await this.api.get('/template-presets')
    return res.data.data
  }

  async adoptPreset(slug: string): Promise<App.Data.TemplateData> {
    const res = await this.api.post(`/template-presets/${slug}/adopt`)
    return res.data.data
  }
}

export default new TemplateService()
