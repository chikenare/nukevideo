import type { NodesResponse, Node, CreateNodePayload, UpdateNodePayload } from '@/types/Node'
import apiClient from './api'

class NodeService {
    private readonly BASE_PATH = '/nodes'

    constructor(private api = apiClient) { }

    async getNodes(): Promise<NodesResponse> {
        const res = await this.api.get(this.BASE_PATH)
        return res.data.data
    }

    async getNode(id: number): Promise<Node> {
        const res = await this.api.get(`${this.BASE_PATH}/${id}`)
        return res.data.data
    }

    async createNode(payload: CreateNodePayload): Promise<Node> {
        const res = await this.api.post(this.BASE_PATH, payload)
        return res.data.data
    }

    async updateNode(id: number, payload: UpdateNodePayload): Promise<Node> {
        const res = await this.api.put(`${this.BASE_PATH}/${id}`, payload)
        return res.data.data
    }

    async deleteNode(id: number): Promise<void> {
        await this.api.delete(`${this.BASE_PATH}/${id}`)
    }

    async deployNode(id: number): Promise<{ success: boolean; message: string; output: string }> {
        const res = await this.api.post(`${this.BASE_PATH}/${id}/deploy`)
        return res.data
    }
}

export default new NodeService()
