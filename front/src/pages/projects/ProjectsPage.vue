<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Button } from '@/components/ui/button'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Badge } from '@/components/ui/badge'
import { Check, Copy, Edit, MoreVertical, Plus } from 'lucide-vue-next'
import { useProjectsStore } from '@/stores/projects'
import type { Project } from '@/types/Project'
import { toast } from 'vue-sonner'
import ProjectFormDialog from './components/ProjectFormDialog.vue'
import DeleteProjectButton from './components/DeleteProjectButton.vue'

const projectsStore = useProjectsStore()
const route = useRoute()
const router = useRouter()

const loading = ref(false)
const dialogOpen = ref(false)
const editingProject = ref<Project | null>(null)

const projects = computed(() => projectsStore.projects)

const formatDate = (dateString?: string) => {
  if (!dateString) return 'N/A'
  return new Date(dateString).toLocaleDateString()
}

const openCreate = () => {
  editingProject.value = null
  dialogOpen.value = true
}

const openEdit = (project: Project) => {
  editingProject.value = project
  dialogOpen.value = true
}

const handleSetActive = (project: Project) => {
  if (project.ulid === projectsStore.currentProject?.ulid) return
  projectsStore.setCurrent(project.ulid)
  window.location.reload()
}

const copyId = async (project: Project) => {
  await navigator.clipboard.writeText(project.ulid)
  toast.success('Project ID copied')
}

const load = async () => {
  try {
    loading.value = true
    await projectsStore.fetchProjects()
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()
  if (route.query.create === '1') {
    openCreate()
    router.replace({ query: {} })
  }
})
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold">Projects</h1>
        <p class="text-muted-foreground">Organize videos, templates and API keys per project</p>
      </div>
      <Button @click="openCreate">
        <Plus :size="16" class="mr-2" />
        New project
      </Button>
    </div>

    <div class="overflow-hidden rounded-lg border">
      <Table>
        <TableHeader class="bg-muted sticky top-0 z-10">
          <TableRow>
            <TableHead>Name</TableHead>
            <TableHead>Created</TableHead>
            <TableHead class="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-if="loading">
            <TableCell colspan="3" class="text-center">
              <Spinner />
              Loading projects...
            </TableCell>
          </TableRow>
          <TableRow v-else-if="projects.length === 0">
            <TableCell colspan="3" class="text-center text-muted-foreground py-8">
              <div class="flex flex-col items-center gap-2">
                <p>No projects found</p>
                <Button variant="outline" size="sm" @click="openCreate">
                  <Plus :size="14" class="mr-2" />
                  Create your first project
                </Button>
              </div>
            </TableCell>
          </TableRow>
          <TableRow v-else v-for="project in projects" :key="project.ulid" class="hover:bg-muted/50">
            <TableCell class="font-medium">
              <div class="flex items-center gap-2">
                {{ project.name }}
                <Badge
                  v-if="project.ulid === projectsStore.currentProject?.ulid"
                  variant="secondary"
                  class="text-xs"
                >
                  Active
                </Badge>
              </div>
            </TableCell>
            <TableCell>{{ formatDate(project.createdAt) }}</TableCell>
            <TableCell class="text-right">
              <DropdownMenu>
                <DropdownMenuTrigger as-child>
                  <Button variant="ghost" size="icon">
                    <MoreVertical :size="16" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem
                    v-if="project.ulid !== projectsStore.currentProject?.ulid"
                    @click="handleSetActive(project)"
                  >
                    <Check :size="16" class="mr-2" />
                    Set active
                  </DropdownMenuItem>
                  <DropdownMenuItem @click="openEdit(project)">
                    <Edit :size="16" class="mr-2" />
                    Edit
                  </DropdownMenuItem>
                  <DropdownMenuItem @click="copyId(project)">
                    <Copy :size="16" class="mr-2" />
                    Copy ID
                  </DropdownMenuItem>
                  <DeleteProjectButton v-if="projects.length > 1" :project="project" />
                </DropdownMenuContent>
              </DropdownMenu>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <ProjectFormDialog v-model:open="dialogOpen" :project="editingProject" />
  </div>
</template>
