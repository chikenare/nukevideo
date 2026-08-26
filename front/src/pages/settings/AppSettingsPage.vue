<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import AppSettingsService from '@/services/AppSettingsService'
import { ValidationException } from '@/exceptions/ValidationException'
import { Copy, Check, RefreshCw, KeyRound, Upload } from '@lucide/vue'

type Settings = App.Data.AppSettingsData
type Rotation = App.Data.AppSettings.SshKeyRotationData

const settings = ref<Settings | null>(null)
const loading = ref(true)
const busy = ref(false)
const copied = ref(false)
const errors = ref<Record<string, string[]>>({})

const importing = ref(false)
const privateKey = ref('')
const rotation = ref<Rotation | null>(null)

async function fetchSettings() {
  try {
    loading.value = true
    settings.value = await AppSettingsService.get()
  } finally {
    loading.value = false
  }
}

async function generate() {
  busy.value = true
  errors.value = {}
  rotation.value = null
  try {
    settings.value = await AppSettingsService.setSshKey({ privateKey: null })
  } finally {
    busy.value = false
  }
}

async function importKey() {
  busy.value = true
  errors.value = {}
  rotation.value = null
  try {
    settings.value = await AppSettingsService.setSshKey({ privateKey: privateKey.value })
    privateKey.value = ''
    importing.value = false
  } catch (error) {
    if (error instanceof ValidationException) errors.value = error.errors
  } finally {
    busy.value = false
  }
}

async function rotate() {
  busy.value = true
  rotation.value = null
  try {
    rotation.value = await AppSettingsService.rotateSshKey()
    settings.value = await AppSettingsService.get()
  } finally {
    busy.value = false
  }
}

async function copyPublicKey() {
  if (!settings.value?.sshPublicKey) return
  await navigator.clipboard.writeText(settings.value.sshPublicKey)
  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}

onMounted(fetchSettings)
</script>

<template>
  <div class="flex flex-col gap-6 p-4 max-w-2xl">
    <div>
      <h1 class="text-2xl font-bold">Settings</h1>
      <p class="text-muted-foreground">Manage application configuration.</p>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <Spinner />
    </div>

    <section v-else class="flex flex-col gap-4 rounded-lg border p-4">
      <div>
        <h2 class="text-lg font-semibold">SSH key</h2>
        <p class="text-sm text-muted-foreground">
          The key the panel connects to every node with. Add its public half to
          <code>~/.ssh/authorized_keys</code> of the SSH user on each node. The private half stays here,
          encrypted, and is never shown.
        </p>
      </div>

      <template v-if="settings?.sshPublicKey">
        <div class="grid gap-2">
          <Label>Public key</Label>
          <textarea
            readonly
            rows="3"
            :value="settings.sshPublicKey"
            class="flex w-full rounded-md border border-input bg-muted px-3 py-2 text-xs font-mono"
            @focus="($event.target as HTMLTextAreaElement).select()"
          />
          <p class="text-xs text-muted-foreground">Fingerprint <code>{{ settings.sshFingerprint }}</code></p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Button type="button" variant="outline" size="sm" @click="copyPublicKey">
            <Check v-if="copied" class="h-4 w-4 mr-2" />
            <Copy v-else class="h-4 w-4 mr-2" />
            {{ copied ? 'Copied' : 'Copy public key' }}
          </Button>
          <AlertDialog>
            <AlertDialogTrigger as-child>
              <Button type="button" variant="outline" size="sm" :disabled="busy">
                <RefreshCw class="h-4 w-4 mr-2" :class="{ 'animate-spin': busy }" />
                Rotate on all nodes
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Rotate the SSH key?</AlertDialogTitle>
                <AlertDialogDescription>
                  A new key is generated and installed on every node using the current one; the panel
                  switches only once every active node accepts it, then removes the old public key from
                  the nodes. If any active node cannot be reached nothing changes and it is listed below.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction @click="rotate">Rotate</AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
          <Button type="button" variant="ghost" size="sm" :disabled="busy" @click="importing = !importing">
            <Upload class="h-4 w-4 mr-2" />
            Replace by importing
          </Button>
        </div>

        <div v-if="rotation" class="rounded-md border p-3 text-sm" :class="rotation.rotated ? 'border-green-500/40' : 'border-destructive/40'">
          <p class="font-medium">
            {{ rotation.rotated ? 'Key rotated. Every node now authenticates with the new key.' : 'Not rotated: an active node did not accept the new key. The current key stays in place.' }}
          </p>
          <ul v-if="rotation.nodes.length" class="mt-2 grid gap-1">
            <li v-for="node in rotation.nodes" :key="node.id" class="flex gap-2">
              <span :class="node.ok ? 'text-green-600' : 'text-destructive'">{{ node.ok ? '✓' : '✗' }}</span>
              <span>{{ node.name }}</span>
              <span v-if="node.error" class="text-muted-foreground truncate">— {{ node.error }}</span>
            </li>
          </ul>
          <p v-else class="mt-2 text-muted-foreground">No nodes yet, so there was nothing to install on.</p>
        </div>
      </template>

      <template v-else>
        <p class="text-sm">No key yet. Generate one here, or import the private half of a pair you already have.</p>
        <div class="flex gap-2">
          <Button type="button" size="sm" :disabled="busy" @click="generate">
            <KeyRound class="h-4 w-4 mr-2" />
            {{ busy ? 'Generating...' : 'Generate key' }}
          </Button>
          <Button type="button" variant="outline" size="sm" :disabled="busy" @click="importing = !importing">
            <Upload class="h-4 w-4 mr-2" />
            Import
          </Button>
        </div>
      </template>

      <form v-if="importing" class="grid gap-2 border-t pt-4" @submit.prevent="importKey">
        <Label for="ssh_private_key">Private key</Label>
        <textarea
          id="ssh_private_key"
          v-model="privateKey"
          placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
          required
          rows="5"
          class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
        <p class="text-xs text-muted-foreground">
          The public key is derived from it. This replaces the current key without touching the nodes:
          use it for a first key, or when the new public key is already on them. To swap keys on a
          running fleet, rotate instead.
        </p>
        <p v-if="errors.privateKey" class="text-sm text-destructive">{{ errors.privateKey[0] }}</p>
        <div class="flex gap-2">
          <Button type="submit" size="sm" :disabled="busy">{{ busy ? 'Importing...' : 'Import key' }}</Button>
          <Button type="button" variant="ghost" size="sm" @click="importing = false">Cancel</Button>
        </div>
      </form>
    </section>
  </div>
</template>
