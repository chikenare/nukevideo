import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import type { Project } from '@/types/Project'
import ProjectService from '@/services/ProjectService'

const STORAGE_KEY = 'nv:current-project-ulid'

export const useProjectsStore = defineStore('projects', () => {
  const projects = ref<Project[]>([])
  const currentUlid = ref<string | null>(localStorage.getItem(STORAGE_KEY))
  const loaded = ref(false)

  const currentProject = computed<Project | null>(() => {
    if (!currentUlid.value) return projects.value[0] ?? null
    return projects.value.find(p => p.ulid === currentUlid.value) ?? projects.value[0] ?? null
  })

  function setCurrent(ulid: string) {
    currentUlid.value = ulid
    localStorage.setItem(STORAGE_KEY, ulid)
  }

  function ensureCurrent() {
    const stored = currentUlid.value
    if (!stored || !projects.value.some(p => p.ulid === stored)) {
      const first = projects.value[0]
      if (first) setCurrent(first.ulid)
    }
  }

  async function fetchProjects() {
    projects.value = await ProjectService.index()
    ensureCurrent()
    loaded.value = true
  }

  function hydrate(list: Project[]) {
    projects.value = list
    ensureCurrent()
    loaded.value = true
  }

  function upsert(project: Project) {
    const idx = projects.value.findIndex(p => p.ulid === project.ulid)
    if (idx >= 0) projects.value[idx] = project
    else projects.value.unshift(project)
  }

  function remove(ulid: string) {
    projects.value = projects.value.filter(p => p.ulid !== ulid)
    if (currentUlid.value === ulid) {
      const first = projects.value[0]
      if (first) setCurrent(first.ulid)
      else {
        currentUlid.value = null
        localStorage.removeItem(STORAGE_KEY)
      }
    }
  }

  function reset() {
    projects.value = []
    currentUlid.value = null
    loaded.value = false
    localStorage.removeItem(STORAGE_KEY)
  }

  return {
    projects,
    currentUlid,
    currentProject,
    loaded,
    fetchProjects,
    hydrate,
    setCurrent,
    upsert,
    remove,
    reset,
  }
})
