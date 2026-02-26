<script setup lang="ts">
import type { SidebarProps } from '@/components/ui/sidebar'

import {
  Settings2,
  VideoIcon,
} from "lucide-vue-next"
import NavMain from '@/components/NavMain.vue'
import NavUser from '@/components/NavUser.vue'
import TeamSwitcher from '@/components/TeamSwitcher.vue'
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

const data = {
  navMain: [
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
    {
      title: "Settings",
      url: "#",
      icon: Settings2,
      items: [
        {
          title: "API Keys",
          url: "/settings/api-keys",
        },
        {
          title: "SSH Keys",
          url: "/settings/ssh-keys",
        },
        {
          title: "Nodes",
          url: "/nodes",
        },
      ],
    },
  ],
}
</script>

<template>
  <Sidebar v-bind="props">
    <SidebarHeader>
      <TeamSwitcher />
    </SidebarHeader>
    <SidebarContent>
      <NavMain :items="data.navMain" />
    </SidebarContent>
    <SidebarFooter>
      <NavUser v-if="authStore.user" :user="authStore.user" />
    </SidebarFooter>
    <SidebarRail />
  </Sidebar>
</template>
