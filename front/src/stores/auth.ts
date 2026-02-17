import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import type { User } from '@/types/Auth'
import AuthService from '@/services/AuthService'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loaded = ref(false)

  const isAuthenticated = computed(() => !!user.value)
  const roles = computed(() => user.value?.roles ?? [])
  const permissions = computed(() => user.value?.permissions ?? [])

  function hasRole(role: string): boolean {
    return roles.value.includes(role)
  }

  function hasPermission(permission: string): boolean {
    return permissions.value.includes(permission)
  }

  async function fetchUser() {
    try {
      user.value = await AuthService.getUser()
    } catch {
      user.value = null
    } finally {
      loaded.value = true
    }
  }

  function setUser(newUser: User) {
    user.value = newUser
    loaded.value = true
  }

  async function clearUser() {
    await AuthService.logout()
    user.value = null
  }

  return { user, loaded, isAuthenticated, roles, permissions, hasRole, hasPermission, fetchUser, setUser, clearUser }
})
