<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { CircleCheck, TriangleAlert } from '@lucide/vue'
import { Badge } from '@/components/ui/badge'
import SettingsService from '@/services/SettingsService'

const version = ref<{ current: string; latest: string; behind: number } | null>(null)

async function fetchVersion() {
  try {
    version.value = await SettingsService.versionCheck()
  } catch {
    // silently ignore
  }
}

onMounted(fetchVersion)
</script>

<template>
  <div class="flex flex-col gap-6 p-4 max-w-2xl">
    <div>
      <h1 class="text-2xl font-bold">Settings</h1>
      <p class="text-muted-foreground">Manage application configuration.</p>
    </div>

    <Card v-if="version">
      <CardHeader>
        <CardTitle>Version</CardTitle>
        <CardDescription>Current application version information.</CardDescription>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <component
              :is="version.behind > 0 ? TriangleAlert : CircleCheck"
              class="h-5 w-5"
              :class="version.behind > 0 ? 'text-amber-500' : 'text-green-500'"
            />
            <span class="text-sm font-medium">{{ version.current }}</span>
          </div>
          <Badge v-if="version.behind > 0" variant="outline" class="text-amber-500 border-amber-500">
            {{ version.behind }} version{{ version.behind > 1 ? 's' : '' }} behind
          </Badge>
          <Badge v-else variant="outline" class="text-green-500 border-green-500">
            Up to date
          </Badge>
        </div>
        <p v-if="version.behind > 0" class="text-sm text-muted-foreground">
          Latest available: <span class="font-medium">{{ version.latest }}</span>
        </p>
      </CardContent>
    </Card>
  </div>
</template>
