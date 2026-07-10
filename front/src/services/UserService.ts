import apiClient from './api'

class UserService {
    private readonly BASE_PATH = '/users'

    constructor(private api = apiClient) { }

    async getUsers(): Promise<App.Data.UserData[]> {
        const res = await this.api.get(this.BASE_PATH)
        return res.data.data
    }

    async createUser(payload: App.Data.User.StoreUserData): Promise<App.Data.UserData> {
        const res = await this.api.post(this.BASE_PATH, payload)
        return res.data.data
    }

    async updateUser(id: number, payload: App.Data.User.UpdateUserData): Promise<App.Data.UserData> {
        const res = await this.api.put(`${this.BASE_PATH}/${id}`, payload)
        return res.data.data
    }

    async deleteUser(id: number): Promise<void> {
        await this.api.delete(`${this.BASE_PATH}/${id}`)
    }
}

export default new UserService()
