<script lang="ts">

import 'vue-sonner/style.css'
import { Toaster } from '@/components/ui/sonner'

export const iframeHeight = "800px"
export const description
  = "A simple sidebar with navigation grouped by section."
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppSidebar from "@/components/AppSidebar.vue"
import {
  SidebarInset,
  SidebarProvider,
  SidebarTrigger,
} from "@/components/ui/sidebar"
import ModeToggle from "./components/ModeToggle.vue"
import Separator from "./components/ui/separator/Separator.vue"

const route = useRoute()
const isGuestRoute = computed(() => route.meta.guest === true)
</script>

<template>
  <template v-if="isGuestRoute">
    <RouterView />
  </template>
  <template v-else>
    <SidebarProvider>
      <AppSidebar />
      <SidebarInset>
        <header class="flex justify-between h-16 shrink-0 items-center gap-2 border-b px-4">
          <div class="flex items-center">
            <SidebarTrigger class="-ml-1" />
            <Separator orientation="vertical" class="mx-2 data-[orientation=vertical]:h-4" />
            <h1 class="text-base font-medium">
              Videos
            </h1>
          </div>
          <ModeToggle />
        </header>
        <RouterView />
      </SidebarInset>
    </SidebarProvider>
  </template>
  <Toaster />
</template>
