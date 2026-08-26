<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
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
import SshKeyService from '@/services/SshKeyService'
type SshKey = App.Data.SshKeyData
import { ValidationException } from '@/exceptions/ValidationException'
import { Plus, Copy, Trash2, Check } from '@lucide/vue'

const keys = ref<SshKey[]>([])
const loading = ref(true)
const dialogOpen = ref(false)
const createLoading = ref(false)
const errors = ref<Record<string, string[]>>({})

// "generate" is the default: the server mints an Ed25519 pair and the operator only has to copy
// the public half onto the nodes. "import" is for a pair made elsewhere — the private half alone;
// the public one is derived server-side so the two can never disagree.
const mode = ref<'generate' | 'import'>('generate')
const form = ref({
  name: '',
  privateKey: '',
})

// The key just created, kept on screen with its public half: this is the moment the operator
// needs it, to paste into the node's authorized_keys, and the list only shows a fingerprint.
const created = ref<SshKey | null>(null)
const copiedId = ref<number | null>(null)

async function fetchKeys() {
  try {
    loading.value = true
    keys.value = await SshKeyService.getAll()
  } catch (error) {
    console.error('Error fetching SSH keys:', error)
  } finally {
    loading.value = false
  }
}

async function handleCreate() {
  errors.value = {}
  createLoading.value = true

  try {
    created.value = await SshKeyService.create({
      name: form.value.name,
      privateKey: mode.value === 'import' ? form.value.privateKey : null,
    })
    form.value = { name: '', privateKey: '' }
    await fetchKeys()
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    }
  } finally {
    createLoading.value = false
  }
}

async function handleDelete(id: number) {
  try {
    await SshKeyService.delete(id)
    keys.value = keys.value.filter((k) => k.id !== id)
  } catch (error) {
    console.error('Error deleting SSH key:', error)
  }
}

async function copyPublicKey(key: SshKey) {
  await navigator.clipboard.writeText(key.publicKey)
  copiedId.value = key.id
  setTimeout(() => {
    if (copiedId.value === key.id) copiedId.value = null
  }, 1500)
}

function closeDialog() {
  dialogOpen.value = false
  created.value = null
  errors.value = {}
}

function formatDate(dateString: string) {
  return new Date(dateString).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

onMounted(() => {
  fetchKeys()
})
</script>

<template>
  <div class="flex flex-col gap-6 p-4 max-w-4xl">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold">SSH Keys</h1>
        <p class="text-muted-foreground">Manage SSH keys used to connect to your nodes.</p>
      </div>

      <Dialog :open="dialogOpen" @update:open="(open) => (open ? (dialogOpen = true) : closeDialog())">
        <DialogTrigger as-child>
          <Button>
            <Plus class="h-4 w-4 mr-2" />
            Add Key
          </Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-lg">
          <template v-if="created">
            <DialogHeader>
              <DialogTitle>Key "{{ created.name }}" ready</DialogTitle>
              <DialogDescription>
                Add this public key to <code>~/.ssh/authorized_keys</code> of the SSH user on every node it should connect to. The private key stays here, encrypted, and is never shown.
              </DialogDescription>
            </DialogHeader>
            <div class="grid gap-2">
              <Label>Public key</Label>
              <textarea
                readonly
                rows="4"
                :value="created.publicKey"
                class="flex w-full rounded-md border border-input bg-muted px-3 py-2 text-xs font-mono"
                @focus="($event.target as HTMLTextAreaElement).select()"
              />
              <p class="text-xs text-muted-foreground">Fingerprint <code>{{ created.fingerprint }}</code></p>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" @click="copyPublicKey(created)">
                <Check v-if="copiedId === created.id" class="h-4 w-4 mr-2" />
                <Copy v-else class="h-4 w-4 mr-2" />
                {{ copiedId === created.id ? 'Copied' : 'Copy public key' }}
              </Button>
              <Button type="button" @click="closeDialog">Done</Button>
            </DialogFooter>
          </template>
          <template v-else>
            <DialogHeader>
              <DialogTitle>Add SSH Key</DialogTitle>
              <DialogDescription>Generate a key here, or import the private half of a pair you already have.</DialogDescription>
            </DialogHeader>
            <form @submit.prevent="handleCreate" class="grid gap-4">
              <div class="grid gap-2">
                <Label for="key_name">Name</Label>
                <Input id="key_name" v-model="form.name" placeholder="e.g. production-key" required />
                <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name[0] }}</p>
              </div>
              <div class="flex gap-2">
                <Button type="button" size="sm" :variant="mode === 'generate' ? 'default' : 'outline'" @click="mode = 'generate'">Generate</Button>
                <Button type="button" size="sm" :variant="mode === 'import' ? 'default' : 'outline'" @click="mode = 'import'">Import</Button>
              </div>
              <p v-if="mode === 'generate'" class="text-xs text-muted-foreground">
                An Ed25519 pair is generated on the server. You will get the public key to install on your nodes.
              </p>
              <div v-else class="grid gap-2">
                <Label for="key_private">Private Key</Label>
                <textarea
                  id="key_private"
                  v-model="form.privateKey"
                  placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                  required
                  rows="5"
                  class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
                <p class="text-xs text-muted-foreground">The public key is derived from it; there is nothing else to paste.</p>
                <p v-if="errors.privateKey" class="text-sm text-destructive">{{ errors.privateKey[0] }}</p>
              </div>
              <DialogFooter>
                <Button type="submit" :disabled="createLoading">
                  {{ createLoading ? 'Working...' : mode === 'generate' ? 'Generate Key' : 'Import Key' }}
                </Button>
              </DialogFooter>
            </form>
          </template>
        </DialogContent>
      </Dialog>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <Spinner />
    </div>

    <div v-else-if="keys.length === 0" class="text-center text-muted-foreground py-12">
      No SSH keys yet. Click "Add Key" to get started.
    </div>

    <div v-else class="overflow-hidden rounded-lg border">
      <Table>
        <TableHeader class="bg-muted">
          <TableRow>
            <TableHead>Name</TableHead>
            <TableHead>Fingerprint</TableHead>
            <TableHead>Created</TableHead>
            <TableHead class="w-20"></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="key in keys" :key="key.id">
            <TableCell class="font-medium">{{ key.name }}</TableCell>
            <TableCell>
              <div class="flex items-center gap-1">
                <code class="text-xs text-muted-foreground">{{ key.fingerprint }}</code>
                <Button variant="ghost" size="icon" class="h-6 w-6" title="Copy public key" @click="copyPublicKey(key)">
                  <Check v-if="copiedId === key.id" class="h-3 w-3" />
                  <Copy v-else class="h-3 w-3" />
                </Button>
              </div>
            </TableCell>
            <TableCell>{{ formatDate(key.createdAt) }}</TableCell>
            <TableCell>
              <AlertDialog>
                <AlertDialogTrigger as-child>
                  <Button variant="ghost" size="icon" class="text-destructive hover:text-destructive">
                    <Trash2 class="h-4 w-4" />
                  </Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                  <AlertDialogHeader>
                    <AlertDialogTitle>Delete SSH key</AlertDialogTitle>
                    <AlertDialogDescription>
                      Are you sure you want to delete "{{ key.name }}"? Nodes using this key will no longer be able to connect.
                    </AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="handleDelete(key.id)">Delete</AlertDialogAction>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>
  </div>
</template>
