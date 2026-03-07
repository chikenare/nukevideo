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
import { useAuthStore } from '@/stores/auth'
import AppSidebar from "@/components/AppSidebar.vue"
import {
  SidebarInset,
  SidebarProvider,
  SidebarTrigger,
} from "@/components/ui/sidebar"
import ModeToggle from "./components/ModeToggle.vue"
import Separator from "./components/ui/separator/Separator.vue"

const route = useRoute()
const authStore = useAuthStore()
const isGuestRoute = computed(() => route.meta.guest === true)
const isReady = computed(() => authStore.loaded)
</script>

<template>
  <template v-if="!isReady">
    <div class="flex h-screen w-screen items-center justify-center">
      <svg class="h-8 w-8 animate-spin text-muted-foreground" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
    </div>
  </template>
  <template v-else-if="isGuestRoute">
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
