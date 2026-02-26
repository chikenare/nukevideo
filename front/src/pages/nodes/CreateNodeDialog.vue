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
import { ref, onMounted } from 'vue'
import NodeService from '@/services/NodeService'
import SshKeyService from '@/services/SshKeyService'
import type { SshKey } from '@/types/SshKey'
import { ValidationException } from '@/exceptions/ValidationException'
import { Plus } from 'lucide-vue-next'

const emit = defineEmits<{ created: [] }>()

const dialogOpen = ref(false)
const loading = ref(false)
const errors = ref<Record<string, string[]>>({})

const sshKeys = ref<SshKey[]>([])

const newNode = ref({
  name: '',
  ip_address: '',
  hostname: '',
  type: 'worker' as 'worker' | 'proxy',
  ssh_key_id: undefined as number | undefined,
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
      ip_address: newNode.value.ip_address,
      type: newNode.value.type,
      ...(newNode.value.type === 'proxy' && newNode.value.hostname ? { hostname: newNode.value.hostname } : {}),
      ...(newNode.value.ssh_key_id ? { ssh_key_id: newNode.value.ssh_key_id } : {}),
    })
    newNode.value = { name: '', ip_address: '', hostname: '', type: 'worker', ssh_key_id: undefined }
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
    <DialogContent>
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
          <Input id="node_ip" v-model="newNode.ip_address" placeholder="e.g. 192.168.1.100" required />
          <p v-if="errors.ip_address" class="text-sm text-destructive">{{ errors.ip_address[0] }}</p>
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
          <Select v-model="newNode.ssh_key_id">
            <SelectTrigger>
              <SelectValue placeholder="Select SSH key" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="key in sshKeys" :key="key.id" :value="key.id">{{ key.name }}</SelectItem>
            </SelectContent>
          </Select>
          <p v-if="errors.ssh_key_id" class="text-sm text-destructive">{{ errors.ssh_key_id[0] }}</p>
        </div>
        <div v-if="newNode.type === 'proxy'" class="grid gap-2">
          <Label for="node_hostname">Hostname</Label>
          <Input id="node_hostname" v-model="newNode.hostname" placeholder="e.g. cdn.example.com" required />
          <p v-if="errors.hostname" class="text-sm text-destructive">{{ errors.hostname[0] }}</p>
        </div>
        <DialogFooter>
          <Button type="submit" :disabled="loading">
            {{ loading ? 'Creating...' : 'Create Node' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
