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

    async getCacheDisks(id: number): Promise<{ preselect: boolean; disks: App.Data.CacheDiskData[] }> {
        const res = await this.api.get(`${this.BASE_PATH}/${id}/cache-disks`)
        return res.data.data
    }

    async deploy(id: number, body: { force?: boolean; disks?: string[] } = {}): Promise<App.Data.ActivityLogData> {
        const res = await this.api.post(`${this.BASE_PATH}/${id}/deploy`, body)
        return res.data.data
    }

    async start(id: number): Promise<App.Data.ActivityLogData> {
        const res = await this.api.post(`${this.BASE_PATH}/${id}/start`)
        return res.data.data
    }

    async stop(id: number, force = false): Promise<App.Data.ActivityLogData> {
        const res = await this.api.post(`${this.BASE_PATH}/${id}/stop`, { force })
        return res.data.data
    }

    async deployMany(payload: App.Data.Node.DeployNodesData): Promise<{ data: App.Data.ActivityLogData[]; skipped: number[] }> {
        const res = await this.api.post(`${this.BASE_PATH}/deploy`, payload)
        return res.data
    }

    async getOperations(filter: { node?: number } = {}): Promise<App.Data.ActivityLogData[]> {
        const res = await this.api.get('/node-operations', { params: filter })
        return res.data.data
    }

    async getOperationLines(id: number, after: number): Promise<{ lines: string[]; next: number; status: string }> {
        const res = await this.api.get(`/node-operations/${id}/lines`, { params: { after } })
        return res.data
    }

    async generateBootstrapToken(id: number): Promise<{ command: string }> {
        const res = await this.api.post(`${this.BASE_PATH}/${id}/bootstrap-token`)
        return res.data
    }

    async runValidation(id: number): Promise<App.Data.ValidationCheckData[]> {
        const res = await this.api.post(`${this.BASE_PATH}/${id}/validate`)
        return res.data.checks
    }

    async getEnvironment(): Promise<{ environment: string; chunkStoreAddress: string }> {
        const res = await this.api.get('/node-environment')
        return res.data.data
    }

    async updateEnvironment(payload: App.Data.Node.UpdateNodeEnvironmentData): Promise<{ environment: string; chunkStoreAddress: string }> {
        const res = await this.api.patch('/node-environment', payload)
        return res.data.data
    }

}

export default new NodeService()
