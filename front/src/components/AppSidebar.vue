<script setup lang="ts">
import type { SidebarProps } from '@/components/ui/sidebar'

import {
  House,
  Shield,
  VideoIcon,
} from "@lucide/vue"
import NavMain from '@/components/NavMain.vue'
import NavUser from '@/components/NavUser.vue'
import NavVersion from '@/components/NavVersion.vue'
import ProjectSwitcher from '@/components/ProjectSwitcher.vue'
import { useAuthStore } from '@/stores/auth'

import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarRail,
} from '@/components/ui/sidebar'

const props = withDefaults(defineProps<SidebarProps>(), {
  collapsible: "icon",
})

const authStore = useAuthStore()

const navMain = [
  {
    title: "Home",
    url: "/",
    icon: House,
  },
  {
    title: "Videos",
    url: "#",
    icon: VideoIcon,
    items: [
      {
        title: "Videos",
        url: "/videos",
      },
      {
        title: "Templates",
        url: "/templates",
      },
    ],
  },
  ...(authStore.isAdmin ? [{
    title: "Admin",
    url: "#",
    icon: Shield,
    items: [
      {
        title: "Users",
        url: "/users",
      },
      {
        title: "Nodes",
        url: "/nodes",
      },
      {
        title: "SSH Keys",
        url: "/settings/ssh-keys",
      },
      {
        title: "App Settings",
        url: "/settings/app",
      },
      {
        title: "CDN Settings",
        url: "/settings/cdn",
      },
    ],
  }] : []),
]
</script>

<template>
  <Sidebar v-bind="props">
    <SidebarHeader>
      <ProjectSwitcher />
    </SidebarHeader>
    <SidebarContent>
      <NavMain :items="navMain" />
    </SidebarContent>
    <SidebarFooter>
      <NavUser v-if="authStore.user" :user="authStore.user" />
      <NavVersion v-if="authStore.isAdmin" />
    </SidebarFooter>
    <SidebarRail />
  </Sidebar>
</template>
