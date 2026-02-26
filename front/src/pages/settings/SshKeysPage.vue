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
import type { SshKey } from '@/types/SshKey'
import { ValidationException } from '@/exceptions/ValidationException'
import { Plus, Copy, Trash2 } from 'lucide-vue-next'

const keys = ref<SshKey[]>([])
const loading = ref(true)
const dialogOpen = ref(false)
const createLoading = ref(false)
const errors = ref<Record<string, string[]>>({})

const form = ref({
  name: '',
  public_key: '',
  private_key: '',
})

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
    await SshKeyService.create(form.value)
    form.value = { name: '', public_key: '', private_key: '' }
    dialogOpen.value = false
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

function copyPublicKey(key: string) {
  navigator.clipboard.writeText(key)
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

      <Dialog v-model:open="dialogOpen">
        <DialogTrigger as-child>
          <Button>
            <Plus class="h-4 w-4 mr-2" />
            Add Key
          </Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Add SSH Key</DialogTitle>
            <DialogDescription>Paste your SSH key pair to use for node connections.</DialogDescription>
          </DialogHeader>
          <form @submit.prevent="handleCreate" class="grid gap-4">
            <div class="grid gap-2">
              <Label for="key_name">Name</Label>
              <Input id="key_name" v-model="form.name" placeholder="e.g. production-key" required />
              <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name[0] }}</p>
            </div>
            <div class="grid gap-2">
              <Label for="key_public">Public Key</Label>
              <textarea
                id="key_public"
                v-model="form.public_key"
                placeholder="ssh-ed25519 AAAA..."
                required
                rows="3"
                class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
              <p v-if="errors.public_key" class="text-sm text-destructive">{{ errors.public_key[0] }}</p>
            </div>
            <div class="grid gap-2">
              <Label for="key_private">Private Key</Label>
              <textarea
                id="key_private"
                v-model="form.private_key"
                placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                required
                rows="5"
                class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
              <p v-if="errors.private_key" class="text-sm text-destructive">{{ errors.private_key[0] }}</p>
            </div>
            <DialogFooter>
              <Button type="submit" :disabled="createLoading">
                {{ createLoading ? 'Adding...' : 'Add Key' }}
              </Button>
            </DialogFooter>
          </form>
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
                <Button variant="ghost" size="icon" class="h-6 w-6" @click="copyPublicKey(key.publicKey)">
                  <Copy class="h-3 w-3" />
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
