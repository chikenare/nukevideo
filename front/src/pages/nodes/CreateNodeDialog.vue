<script setup lang="ts">
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { ref, onMounted } from 'vue'
import NodeService from '@/services/NodeService'
import SshKeyService from '@/services/SshKeyService'
import type { SshKey } from '@/types/SshKey'
import { ValidationException } from '@/exceptions/ValidationException'
import { Plus } from '@lucide/vue'

const emit = defineEmits<{ created: [] }>()

const dialogOpen = ref(false)
const loading = ref(false)
const errors = ref<Record<string, string[]>>({})

const sshKeys = ref<SshKey[]>([])

const newNode = ref({
  user: 'root',
  name: '',
  ipAddress: '',
  hostname: '',
  type: 'worker' as 'worker' | 'proxy',
  sshKeyId: undefined as number | undefined,
  cdnMode: false,
  isStorageServer: false,
  storageEndpoint: '',
})

onMounted(async () => {
  sshKeys.value = await SshKeyService.getAll()
})

const handleCreate = async () => {
  errors.value = {}
  loading.value = true

  try {
    await NodeService.createNode({
      name: newNode.value.name,
      user: newNode.value.user,
      ipAddress: newNode.value.ipAddress,
      type: newNode.value.type,
      ...(newNode.value.type === 'proxy' && newNode.value.hostname ? { hostname: newNode.value.hostname } : {}),
      ...(newNode.value.sshKeyId ? { sshKeyId: newNode.value.sshKeyId } : {}),
      ...(newNode.value.cdnMode ? { cdnMode: true } : {}),
      ...(newNode.value.type === 'worker' && newNode.value.isStorageServer ? { isStorageServer: true } : {}),
      ...(newNode.value.type === 'worker' && newNode.value.isStorageServer && newNode.value.storageEndpoint ? { storageEndpoint: newNode.value.storageEndpoint } : {}),
    })
    newNode.value = { name: '', user: '', ipAddress: '', hostname: '', type: 'worker', sshKeyId: undefined, cdnMode: false, isStorageServer: false, storageEndpoint: '' }
    dialogOpen.value = false
    emit('created')
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="dialogOpen">
    <DialogTrigger as-child>
      <Button>
        <Plus class="h-4 w-4 mr-2" />
        Add Node
      </Button>
    </DialogTrigger>
    <DialogContent class="max-w-2xl max-h-[90vh] overflow-y-auto">
      <DialogHeader>
        <DialogTitle>Add Node</DialogTitle>
        <DialogDescription>Add a new node by providing its SSH connection details.</DialogDescription>
      </DialogHeader>
      <form @submit.prevent="handleCreate" class="grid gap-4">
        <div class="grid gap-2">
          <Label for="node_name">Name</Label>
          <Input id="node_name" v-model="newNode.name" placeholder="e.g. worker-01" required />
          <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name[0] }}</p>
        </div>
        <div class="grid gap-2">
          <Label for="node_ip">IP Address</Label>
          <Input id="node_ip" v-model="newNode.ipAddress" placeholder="e.g. 192.168.1.100" required />
          <p v-if="errors.ipAddress" class="text-sm text-destructive">{{ errors.ipAddress[0] }}</p>
        </div>
        <div class="grid gap-2">
          <Label for="node_type">Type</Label>
          <Select v-model="newNode.type">
            <SelectTrigger>
              <SelectValue placeholder="Select type" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="worker">Worker</SelectItem>
              <SelectItem value="proxy">Proxy</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="grid gap-2">
          <Label for="node_ssh_key">SSH Key</Label>
          <Select v-model="newNode.sshKeyId">
            <SelectTrigger>
              <SelectValue placeholder="Select SSH key" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="key in sshKeys" :key="key.id" :value="key.id">{{ key.name }}</SelectItem>
            </SelectContent>
          </Select>
          <p v-if="errors.sshKeyId" class="text-sm text-destructive">{{ errors.sshKeyId[0] }}</p>
        </div>
        <div class="grid gap-2">
          <Label for="node_ip">User</Label>
          <Input id="node_ip" v-model="newNode.user" placeholder="e.g. 192.168.1.100" required />
          <p v-if="errors.user" class="text-sm text-destructive">{{ errors.user }}</p>
        </div>
        <div v-if="newNode.type === 'proxy'" class="grid gap-2">
          <Label for="node_hostname">Hostname</Label>
          <Input id="node_hostname" v-model="newNode.hostname" placeholder="e.g. cdn.example.com" required />
          <p v-if="errors.hostname" class="text-sm text-destructive">{{ errors.hostname[0] }}</p>
        </div>
        <div v-if="newNode.type === 'proxy'" class="flex items-center justify-between">
          <div>
            <Label for="node_cdn">CDN Mode</Label>
            <p class="text-xs text-muted-foreground">Disables local nginx cache. Use when a CDN handles caching.</p>
          </div>
          <Switch id="node_cdn" v-model="newNode.cdnMode" @update:checked="newNode.cdnMode = $event" />
        </div>
        <div v-if="newNode.type === 'worker'" class="flex items-center justify-between">
          <div>
            <Label for="node_storage">Storage Server (S3)</Label>
            <p class="text-xs text-muted-foreground">This worker hosts the shared chunk store (RustFS). Only one node can be the storage server.</p>
          </div>
          <Switch id="node_storage" v-model="newNode.isStorageServer" @update:checked="newNode.isStorageServer = $event" />
        </div>
        <div v-if="newNode.type === 'worker' && newNode.isStorageServer" class="grid gap-2">
          <Label for="node_storage_endpoint">Storage Endpoint</Label>
          <Input id="node_storage_endpoint" v-model="newNode.storageEndpoint" placeholder="e.g. http://10.0.0.5:9000" />
          <p class="text-xs text-muted-foreground">Full endpoint (local or public) the other nodes use to reach this node's chunk store.</p>
          <p v-if="errors.storageEndpoint" class="text-sm text-destructive">{{ errors.storageEndpoint[0] }}</p>
        </div>
        <p v-if="errors.isStorageServer" class="text-sm text-destructive">{{ errors.isStorageServer[0] }}</p>

        <DialogFooter>
          <Button type="submit" :disabled="loading">
            {{ loading ? 'Creating...' : 'Create Node' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
