import apiClient from './api'

class NodeService {
    private readonly BASE_PATH = '/nodes'

    constructor(private api = apiClient) { }

    async getNodes(): Promise<{ nodes: App.Data.NodeData[] }> {
        const res = await this.api.get(this.BASE_PATH)
        return res.data.data
    }

    async getNode(id: number): Promise<App.Data.NodeData> {
        const res = await this.api.get(`${this.BASE_PATH}/${id}`)
        return res.data.data
    }

    async createNode(payload: App.Data.Node.StoreNodeData): Promise<App.Data.NodeData> {
        const res = await this.api.post(this.BASE_PATH, payload)
        return res.data.data
    }

    async updateNode(id: number, payload: App.Data.Node.UpdateNodeData): Promise<App.Data.NodeData> {
        const res = await this.api.put(`${this.BASE_PATH}/${id}`, payload)
        return res.data.data
    }

    async deleteNode(id: number): Promise<void> {
        await this.api.delete(`${this.BASE_PATH}/${id}`)
    }

    async getPendingJobs(id: number): Promise<{ total: number; totalPending: number; totalReserved: number }> {
        const res = await this.api.get(`${this.BASE_PATH}/${id}/pending-jobs`)
        return res.data
    }

    async runDeploy(id: number, onMessage: (event: { type: string; data: string }) => void): Promise<void> {
        return this.streamSSE(`${this.BASE_PATH}/${id}/deploy`, onMessage)
    }

    private async streamSSE(path: string, onMessage: (event: { type: string; data: string }) => void): Promise<void> {
        const baseURL = import.meta.env.VITE_URL_API || '/api'
        const csrfToken = document.cookie
            .split('; ')
            .find(row => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1]

        const res = await fetch(`${baseURL}${path}`, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Accept': 'text/event-stream',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrfToken ? { 'X-XSRF-TOKEN': decodeURIComponent(csrfToken) } : {}),
            },
        })

        if (!res.ok) {
            throw new Error(`Request failed with status ${res.status}`)
        }

        const reader = res.body!.getReader()
        const decoder = new TextDecoder()
        let buffer = ''

        while (true) {
            const { done, value } = await reader.read()
            if (done) break

            buffer += decoder.decode(value, { stream: true })
            const lines = buffer.split('\n')
            buffer = lines.pop() || ''

            for (const line of lines) {
                if (line.startsWith('data: ')) {
                    try {
                        const event = JSON.parse(line.slice(6))
                        onMessage(event)
                    } catch {
                        // skip malformed events
                    }
                }
            }
        }
    }

    async generateBootstrapToken(id: number): Promise<{ command: string }> {
        const res = await this.api.post(`${this.BASE_PATH}/${id}/bootstrap-token`)
        return res.data
    }

    async runValidation(id: number): Promise<App.Data.ValidationCheckData[]> {
        const res = await this.api.post(`${this.BASE_PATH}/${id}/validate`)
        return res.data.checks
    }

    async getEnvironment(): Promise<{ environment: string }> {
        const res = await this.api.get('/node-environment')
        return res.data.data
    }

    async updateEnvironment(environment: string): Promise<{ environment: string }> {
        const res = await this.api.patch('/node-environment', { environment })
        return res.data.data
    }

}

export default new NodeService()
