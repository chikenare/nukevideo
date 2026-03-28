import type { NodesResponse, Node, CreateNodePayload, DockerContainer } from '@/types/Node'
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

    async updateNode(id: number, payload: Partial<Node>): Promise<Node> {
        const res = await this.api.put(`${this.BASE_PATH}/${id}`, payload)
        return res.data.data
    }

    async deleteNode(id: number): Promise<void> {
        await this.api.delete(`${this.BASE_PATH}/${id}`)
    }

    async getMetrics(id: number): Promise<Node> {
        const res = await this.api.get(`${this.BASE_PATH}/${id}/metrics`)
        return res.data.data
    }

    async getContainers(id: number): Promise<DockerContainer[]> {
        const res = await this.api.get(`${this.BASE_PATH}/${id}/containers`)
        return res.data.containers
    }

    async getPendingJobs(id: number): Promise<{ total: number; totalPending: number; totalReserved: number }> {
        const res = await this.api.get(`${this.BASE_PATH}/${id}/pending-jobs`)
        return res.data
    }

    async getDeploySteps(id: number): Promise<{ key: string; label: string }[]> {
        const res = await this.api.get(`${this.BASE_PATH}/${id}/deploy/steps`)
        return res.data.steps
    }

    async deployStep(id: number, step: string): Promise<void> {
        await this.api.post(`${this.BASE_PATH}/${id}/deploy?step=${step}`)
    }
}

export default new NodeService()
