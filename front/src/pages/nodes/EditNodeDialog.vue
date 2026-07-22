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
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { ref } from 'vue'
import NodeService from '@/services/NodeService'
type Node = App.Data.NodeData
import { ValidationException } from '@/exceptions/ValidationException'

const emit = defineEmits<{ updated: [node: Node] }>()

const dialogOpen = ref(false)
const loading = ref(false)
const errors = ref<Record<string, string[]>>({})

type EditableNode = Omit<Node, 'accel'> & { user: string; storageEndpoint: string; accel: string }
const node = ref<EditableNode>({} as EditableNode)

const show = (initialNode: Node) => {
  const raw: Node = JSON.parse(JSON.stringify(initialNode))
  node.value = { ...raw, user: raw.user ?? '', storageEndpoint: raw.storageEndpoint ?? '', accel: raw.accel ?? 'none' }
  errors.value = {}
  dialogOpen.value = true
}

const handleUpdate = async () => {
  errors.value = {}
  loading.value = true

  try {
    const updated = await NodeService.updateNode(node.value.id, {
      ...node.value,
      accel: node.value.accel === 'none' ? null : node.value.accel,
    })
    dialogOpen.value = false
    emit('updated', updated)
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    }
  } finally {
    loading.value = false
  }
}

defineExpose({ show })
</script>

<template>
  <Dialog v-model:open="dialogOpen">
    <DialogContent class="max-w-2xl max-h-[90vh] overflow-y-auto">
      <DialogHeader>
        <DialogTitle>Edit Node</DialogTitle>
        <DialogDescription>Update node configuration.</DialogDescription>
      </DialogHeader>
      <form @submit.prevent="handleUpdate" class="grid gap-4">
        <div class="grid gap-2">
          <Label for="edit_node_name">Name</Label>
          <Input id="edit_node_name" v-model="node.name" placeholder="e.g. worker-01" required />
          <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name[0] }}</p>
        </div>
        <div class="grid gap-2">
          <Label for="edit_node_ip">IP</Label>
          <Input id="edit_node_ip" v-model="node.ipAddress" placeholder="e.g. 10.0.0.0" required />
          <p v-if="errors.ipAddress" class="text-sm text-destructive">{{ errors.ipAddress }}</p>
        </div>
        <div class="grid gap-2">
          <Label for="edit_node_user">User</Label>
          <Input id="edit_node_user" v-model="node.user" placeholder="e.g. root" required />
          <p v-if="errors.user" class="text-sm text-destructive">{{ errors.user[0] }}</p>
        </div>
        <div class="flex items-center justify-between">
          <Label for="edit_node_active">Active</Label>
          <Switch id="edit_node_active" v-model="node.isActive" @update:checked="node.isActive = $event" />
        </div>
        <div v-if="node.type === 'worker'" class="grid gap-2">
          <Label for="edit_node_accel">GPU Acceleration</Label>
          <Select v-model="node.accel">
            <SelectTrigger>
              <SelectValue placeholder="None (CPU only)" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="none">None (CPU only)</SelectItem>
              <SelectItem value="intel">Intel (Quick Sync)</SelectItem>
              <SelectItem value="nvidia">NVIDIA (NVENC)</SelectItem>
            </SelectContent>
          </Select>
          <p class="text-xs text-muted-foreground">Renditions using a GPU codec are routed to nodes with matching hardware. Redeploy after changing.</p>
          <p v-if="errors.accel" class="text-sm text-destructive">{{ errors.accel[0] }}</p>
        </div>
        <div v-if="node.type === 'worker'" class="flex items-center justify-between">
          <div>
            <Label for="edit_node_storage">Storage Server (S3)</Label>
            <p class="text-xs text-muted-foreground">This worker hosts the shared chunk store (RustFS). Only one node can be the storage server.</p>
          </div>
          <Switch id="edit_node_storage" v-model="node.isStorageServer" @update:checked="node.isStorageServer = $event" />
        </div>
        <div v-if="node.type === 'worker' && node.isStorageServer" class="grid gap-2">
          <Label for="edit_node_storage_endpoint">Storage Endpoint</Label>
          <Input id="edit_node_storage_endpoint" v-model="node.storageEndpoint" placeholder="e.g. http://10.0.0.5:9000" />
          <p class="text-xs text-muted-foreground">Full endpoint (local or public) the other nodes use to reach this node's chunk store.</p>
          <p v-if="errors.storageEndpoint" class="text-sm text-destructive">{{ errors.storageEndpoint[0] }}</p>
        </div>
        <p v-if="errors.isStorageServer" class="text-sm text-destructive">{{ errors.isStorageServer[0] }}</p>

        <div class="grid gap-2">
          <Label for="edit_node_env">Environment Overrides</Label>
          <textarea
            id="edit_node_env"
            v-model="node.env"
            rows="6"
            class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring font-mono"
          />
          <p class="text-xs text-muted-foreground">One KEY=VALUE per line. Overrides global node environment.</p>
          <p v-if="errors.env" class="text-sm text-destructive">{{ errors.env[0] }}</p>
        </div>

        <DialogFooter>
          <Button type="submit" :disabled="loading">
            {{ loading ? 'Saving...' : 'Save Changes' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
