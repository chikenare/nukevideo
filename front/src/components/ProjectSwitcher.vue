<script setup lang="ts">
import { ChevronsUpDown, FolderKanban, Plus, Settings } from 'lucide-vue-next'
import { useRouter } from 'vue-router'
import {
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  useSidebar,
} from '@/components/ui/sidebar'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useProjectsStore } from '@/stores/projects'

const projectsStore = useProjectsStore()
const router = useRouter()
const { isMobile } = useSidebar()

const handleSwitch = (ulid: string) => {
  if (ulid === projectsStore.currentProject?.ulid) return
  projectsStore.setCurrent(ulid)
  window.location.reload()
}

const handleCreate = () => {
  router.push({ name: 'Projects', query: { create: '1' } })
}

const handleManage = () => {
  router.push({ name: 'Projects' })
}
</script>

<template>
  <SidebarMenu>
    <SidebarMenuItem>
      <DropdownMenu>
        <DropdownMenuTrigger as-child>
          <SidebarMenuButton
            size="lg"
            class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
          >
            <div
              class="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground"
            >
              <FolderKanban class="size-4" />
            </div>
            <div class="grid flex-1 text-left text-sm leading-tight">
              <span class="truncate font-medium">
                {{ projectsStore.currentProject?.name ?? 'No project' }}
              </span>
              <span class="truncate text-xs text-muted-foreground">NukeVideo</span>
            </div>
            <ChevronsUpDown class="ml-auto size-4" />
          </SidebarMenuButton>
        </DropdownMenuTrigger>
        <DropdownMenuContent
          class="w-[--reka-dropdown-menu-trigger-width] min-w-56 rounded-lg"
          :side="isMobile ? 'bottom' : 'right'"
          align="start"
          :side-offset="4"
        >
          <DropdownMenuLabel class="text-muted-foreground text-xs">
            Projects
          </DropdownMenuLabel>
          <DropdownMenuItem
            v-for="project in projectsStore.projects"
            :key="project.ulid"
            class="gap-2 p-2"
            @click="handleSwitch(project.ulid)"
          >
            <div class="flex size-6 items-center justify-center rounded-sm border">
              <FolderKanban class="size-3.5 shrink-0" />
            </div>
            <span class="truncate">{{ project.name }}</span>
            <span
              v-if="project.ulid === projectsStore.currentProject?.ulid"
              class="ml-auto text-xs text-muted-foreground"
            >
              active
            </span>
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem class="gap-2 p-2" @click="handleCreate">
            <div class="flex size-6 items-center justify-center rounded-md border bg-background">
              <Plus class="size-4" />
            </div>
            <div class="text-muted-foreground font-medium">New project</div>
          </DropdownMenuItem>
          <DropdownMenuItem class="gap-2 p-2" @click="handleManage">
            <div class="flex size-6 items-center justify-center rounded-md border bg-background">
              <Settings class="size-4" />
            </div>
            <div class="text-muted-foreground font-medium">Manage projects</div>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </SidebarMenuItem>
  </SidebarMenu>
</template>
