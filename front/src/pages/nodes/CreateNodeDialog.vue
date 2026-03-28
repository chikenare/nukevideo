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
import type { NodeInstance } from '@/types/Node'
import { ValidationException } from '@/exceptions/ValidationException'
import { Plus, Trash2 } from 'lucide-vue-next'

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
  instances: [] as NodeInstance[],
})

onMounted(async () => {
  sshKeys.value = await SshKeyService.getAll()
})

const addInstance = () => {
  newNode.value.instances.push({
    nanoCpus: 1000000000,
    memoryBytes: 536870912,
    workload: newNode.value.type === 'worker' ? 'medium' : null,
  })
}

const removeInstance = (index: number) => {
  newNode.value.instances.splice(index, 1)
}

const formatCpus = (nanoCpus: number) => {
  return (nanoCpus / 1000000000).toFixed(1)
}

const formatMemory = (bytes: number) => {
  return (bytes / (1024 * 1024)).toFixed(0)
}

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
      ...(newNode.value.instances.length > 0 ? { instances: newNode.value.instances } : {}),
    })
    newNode.value = { name: '', user: '', ipAddress: '', hostname: '', type: 'worker', sshKeyId: undefined, instances: [] }
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
    <DialogContent class="max-w-2xl">
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

        <!-- Instances -->
        <div class="grid gap-3">
          <div class="flex items-center justify-between">
            <Label>Instances</Label>
            <Button type="button" variant="outline" size="sm" @click="addInstance">
              <Plus class="h-3 w-3 mr-1" />
              Add
            </Button>
          </div>

          <div v-if="newNode.instances.length === 0"
            class="text-sm text-muted-foreground text-center py-3 border border-dashed rounded-md">
            No instances configured
          </div>

          <div v-for="(instance, index) in newNode.instances" :key="index"
            class="flex items-end gap-2 p-3 border rounded-md">
            <div class="grid gap-1 flex-1">
              <Label class="text-xs">CPUs</Label>
              <Input type="number" step="0.1" min="0.1" :model-value="formatCpus(instance.nanoCpus)"
                @update:model-value="instance.nanoCpus = Math.round(parseFloat(String($event || '0')) * 1000000000)"
                placeholder="1.0" />
            </div>
            <div class="grid gap-1 flex-1">
              <Label class="text-xs">Memory (MB)</Label>
              <Input type="number" min="64" :model-value="formatMemory(instance.memoryBytes)"
                @update:model-value="instance.memoryBytes = parseInt(String($event || '0')) * 1024 * 1024"
                placeholder="512" />
            </div>
            <div v-if="newNode.type === 'worker'" class="grid gap-1 flex-1">
              <Label class="text-xs">Workload</Label>
              <Select v-model="(instance as any).workload">
                <SelectTrigger>
                  <SelectValue placeholder="Select" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="light">Light</SelectItem>
                  <SelectItem value="medium">Medium</SelectItem>
                  <SelectItem value="heavy">Heavy</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <Button type="button" variant="ghost" size="icon" class="shrink-0" @click="removeInstance(index)">
              <Trash2 class="h-4 w-4 text-destructive" />
            </Button>
          </div>

          <p v-if="errors.instances" class="text-sm text-destructive">{{ errors.instances[0] }}</p>
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
