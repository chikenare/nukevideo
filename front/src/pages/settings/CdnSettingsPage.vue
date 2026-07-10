<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Spinner } from '@/components/ui/spinner'
import { ApiException } from '@/exceptions/ApiException'
import { ValidationException } from '@/exceptions/ValidationException'
import CdnSettingsService from '@/services/CdnSettingsService'

const form = ref<App.Data.CdnSettingsData | null>(null)
const loading = ref(true)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

async function fetchSettings() {
  loading.value = true
  try {
    form.value = await CdnSettingsService.get()
  } catch (error) {
    if (error instanceof ApiException) {
      toast.error(error.message)
    }
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  if (!form.value) return
  errors.value = {}
  saving.value = true
  try {
    const payload =
      form.value.provider === 'self_hosted'
        ? { provider: form.value.provider, selfHosted: form.value.selfHosted }
        : { provider: form.value.provider, bunny: form.value.bunny }
    form.value = await CdnSettingsService.update(payload)
    toast.success('CDN settings saved')
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    } else if (error instanceof ApiException) {
      toast.error(error.message)
    }
  } finally {
    saving.value = false
  }
}

onMounted(fetchSettings)
</script>

<template>
  <div class="flex flex-col gap-6 p-4 max-w-2xl">
    <div>
      <h1 class="text-2xl font-bold">CDN Settings</h1>
      <p class="text-muted-foreground">Configure the active CDN provider and its signing keys.</p>
    </div>

    <div v-if="loading" class="flex justify-center py-10">
      <Spinner />
    </div>

    <form v-else-if="form" @submit.prevent="handleSave" class="flex flex-col gap-6">
      <Card>
        <CardHeader>
          <CardTitle>Provider</CardTitle>
          <CardDescription>Which CDN serves playback URLs.</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="grid gap-2">
            <Label for="cdn_provider">Active provider</Label>
            <Select v-model="form.provider">
              <SelectTrigger id="cdn_provider">
                <SelectValue placeholder="Select provider" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="self_hosted">Self-hosted</SelectItem>
                <SelectItem value="bunny">Bunny</SelectItem>
              </SelectContent>
            </Select>
            <p v-if="errors.provider" class="text-sm text-destructive">{{ errors.provider[0] }}</p>
          </div>
        </CardContent>
      </Card>

      <Card v-if="form.provider === 'self_hosted'">
        <CardHeader>
          <CardTitle>Self-hosted</CardTitle>
          <CardDescription>Akamai token signing and edge nginx tuning for our own nodes.</CardDescription>
        </CardHeader>
        <CardContent class="grid gap-4">
          <div class="grid gap-2">
            <Label for="sh_token_secret">Token secret</Label>
            <Input id="sh_token_secret" v-model="form.selfHosted.tokenSecret" class="font-mono" />
            <p v-if="errors['selfHosted.tokenSecret']" class="text-sm text-destructive">{{ errors['selfHosted.tokenSecret'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="sh_token_name">Token query name</Label>
            <Input id="sh_token_name" v-model="form.selfHosted.tokenName" />
            <p v-if="errors['selfHosted.tokenName']" class="text-sm text-destructive">{{ errors['selfHosted.tokenName'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="sh_token_window">Token window (seconds)</Label>
            <Input id="sh_token_window" type="number" v-model.number="form.selfHosted.tokenWindow" />
            <p v-if="errors['selfHosted.tokenWindow']" class="text-sm text-destructive">{{ errors['selfHosted.tokenWindow'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="sh_secure_expires">Segment token expiry</Label>
            <Input id="sh_secure_expires" v-model="form.selfHosted.secureTokenExpires" placeholder="e.g. 100d" />
            <p v-if="errors['selfHosted.secureTokenExpires']" class="text-sm text-destructive">{{ errors['selfHosted.secureTokenExpires'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="sh_secure_query_expires">Segment query-token expiry</Label>
            <Input id="sh_secure_query_expires" v-model="form.selfHosted.secureTokenQueryExpires" placeholder="e.g. 1h" />
            <p v-if="errors['selfHosted.secureTokenQueryExpires']" class="text-sm text-destructive">{{ errors['selfHosted.secureTokenQueryExpires'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="sh_cache_max_size">Edge cache max size</Label>
            <Input id="sh_cache_max_size" v-model="form.selfHosted.cacheMaxSize" placeholder="e.g. 10g" />
            <p v-if="errors['selfHosted.cacheMaxSize']" class="text-sm text-destructive">{{ errors['selfHosted.cacheMaxSize'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="sh_cache_inactive">Edge cache inactive</Label>
            <Input id="sh_cache_inactive" v-model="form.selfHosted.cacheInactive" placeholder="e.g. 1h" />
            <p v-if="errors['selfHosted.cacheInactive']" class="text-sm text-destructive">{{ errors['selfHosted.cacheInactive'][0] }}</p>
          </div>
        </CardContent>
      </Card>

      <Card v-if="form.provider === 'bunny'">
        <CardHeader>
          <CardTitle>Bunny</CardTitle>
          <CardDescription>Token authentication for a Bunny pull zone.</CardDescription>
        </CardHeader>
        <CardContent class="grid gap-4">
          <div class="grid gap-2">
            <Label for="bunny_host">Pull zone host</Label>
            <Input id="bunny_host" v-model="form.bunny.host" placeholder="e.g. myzone.b-cdn.net" />
            <p v-if="errors['bunny.host']" class="text-sm text-destructive">{{ errors['bunny.host'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="bunny_token_key">Token key</Label>
            <Input id="bunny_token_key" v-model="form.bunny.tokenKey" class="font-mono" />
            <p v-if="errors['bunny.tokenKey']" class="text-sm text-destructive">{{ errors['bunny.tokenKey'][0] }}</p>
          </div>
          <div class="grid gap-2">
            <Label for="bunny_token_window">Token window (seconds)</Label>
            <Input id="bunny_token_window" type="number" v-model.number="form.bunny.tokenWindow" />
            <p v-if="errors['bunny.tokenWindow']" class="text-sm text-destructive">{{ errors['bunny.tokenWindow'][0] }}</p>
          </div>
        </CardContent>
      </Card>

      <div class="flex justify-end">
        <Button type="submit" :disabled="saving">{{ saving ? 'Saving...' : 'Save Changes' }}</Button>
      </div>
    </form>
  </div>
</template>
