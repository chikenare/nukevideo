import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import AuthService from '@/services/AuthService'
import { useProjectsStore } from '@/stores/projects'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<App.Data.UserData | null>(null)
  const loaded = ref(false)

  const isAuthenticated = computed(() => !!user.value)
  const isAdmin = computed(() => user.value?.isAdmin ?? false)
  function hydrateProjects() {
    const projects = user.value?.projects
    if (projects && projects.length > 0) {
      useProjectsStore().hydrate(projects)
    }
  }

  async function fetchUser() {
    try {
      user.value = await AuthService.getUser()
      hydrateProjects()
    } catch {
      user.value = null
    } finally {
      loaded.value = true
    }
  }

  function setUser(newUser: App.Data.UserData) {
    user.value = newUser
    loaded.value = true
    hydrateProjects()
  }

  async function clearUser() {
    await AuthService.logout()
    user.value = null
    useProjectsStore().reset()
  }

  return { user, loaded, isAuthenticated, isAdmin, fetchUser, setUser, clearUser }
})
