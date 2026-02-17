<script setup lang="ts">
import { Video } from "lucide-vue-next"
import {
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from '@/components/ui/sidebar'

</script>

<template>
  <SidebarMenu>
    <SidebarMenuItem>
      <SidebarMenuButton size="lg"
        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground">
        <div
          class="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
          <Video class="size-4" />
        </div>
        <div class="grid flex-1 text-left text-sm leading-tight">
          <span class="truncate font-medium">
            NukeVideo
          </span>
          <!-- <span class="truncate text-xs">{{ activeTeam.plan }}</span> -->
        </div>
      </SidebarMenuButton>
    </SidebarMenuItem>
  </SidebarMenu>
</template>
