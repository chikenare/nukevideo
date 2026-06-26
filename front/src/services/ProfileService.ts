import type { User } from '@/types/Auth'
import apiClient from './api'

class ProfileService {
  async updateProfile(payload: { name: string; email: string }): Promise<User> {
    const res = await apiClient.put('/profile', payload)
    return res.data.data
  }

  async updatePassword(payload: {
    current_password: string
    password: string
    password_confirmation: string
  }): Promise<void> {
    await apiClient.put('/profile/password', payload)
  }
}

export default new ProfileService()
