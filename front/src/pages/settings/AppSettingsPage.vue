<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Separator } from '@/components/ui/separator'
import { Skeleton } from '@/components/ui/skeleton'
import { UserPlus, Subtitles, CircleCheck, TriangleAlert } from 'lucide-vue-next'
import { Badge } from '@/components/ui/badge'
import type { GeneralSettings } from '@/types/Settings'
import SettingsService from '@/services/SettingsService'
import { toast } from 'vue-sonner'

const settings = ref<GeneralSettings | null>(null)
const loading = ref(true)
const saving = ref<string | null>(null)
const version = ref<{ current: string; latest: string; behind: number } | null>(null)

async function fetchSettings() {
  try {
    settings.value = await SettingsService.getAll()
  } finally {
    loading.value = false
  }
}

async function toggle(key: keyof GeneralSettings, value: boolean) {
  if (!settings.value) return

  saving.value = key
  try {
    await SettingsService.update({ [key]: value })
    toast.success('Setting updated')
  } catch {
    settings.value[key] = !value
    toast.error('Failed to update setting')
  } finally {
    saving.value = null
  }
}

async function fetchVersion() {
  try {
    version.value = await SettingsService.versionCheck()
  } catch {
    // silently ignore
  }
}

onMounted(() => {
  fetchSettings()
  fetchVersion()
})
</script>

<template>
  <div class="flex flex-col gap-6 p-4 max-w-2xl">
    <div>
      <h1 class="text-2xl font-bold">Settings</h1>
      <p class="text-muted-foreground">Manage application configuration.</p>
    </div>

    <Skeleton v-if="loading" class="h-48 w-full rounded-xl" />

    <Card v-else-if="settings">
      <CardHeader>
        <CardTitle>General</CardTitle>
        <CardDescription>Core application settings.</CardDescription>
      </CardHeader>
      <CardContent class="space-y-6">
        <div class="flex items-start gap-4">
          <div class="rounded-md border p-2 text-muted-foreground">
            <UserPlus class="h-5 w-5" />
          </div>
          <div class="flex-1 space-y-0.5">
            <Label class="text-sm font-medium">User Registration</Label>
            <p class="text-sm text-muted-foreground">Allow new users to create accounts on the platform.</p>
          </div>
          <Switch
            v-model="settings.registrationEnabled"
            :disabled="saving === 'registrationEnabled'"
            @update:model-value="(v: boolean) => toggle('registrationEnabled', v)"
          />
        </div>

        <Separator />

        <div class="flex items-start gap-4">
          <div class="rounded-md border p-2 text-muted-foreground">
            <Subtitles class="h-5 w-5" />
          </div>
          <div class="flex-1 space-y-0.5">
            <Label class="text-sm font-medium">Include Subtitles in Manifests</Label>
            <p class="text-sm text-muted-foreground">
              Embed subtitle tracks in HLS/DASH manifests. When enabled, nginx-vod-module fragments subtitles into segments generating thousands of extra requests.
            </p>
          </div>
          <Switch
            v-model="settings.includeSubtitles"
            :disabled="saving === 'includeSubtitles'"
            @update:model-value="(v: boolean) => toggle('includeSubtitles', v)"
          />
        </div>
      </CardContent>
    </Card>

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
