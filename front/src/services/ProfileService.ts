import apiClient from './api'

class ProfileService {
  async updateProfile(payload: App.Data.Profile.UpdateProfileData): Promise<App.Data.UserData> {
    const res = await apiClient.put('/profile', payload)
    return res.data.data
  }

  async updatePassword(payload: App.Data.Profile.UpdatePasswordData & { passwordConfirmation: string }): Promise<void> {
    await apiClient.put('/profile/password', payload)
  }
}

export default new ProfileService()
