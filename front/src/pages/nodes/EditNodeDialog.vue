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
import type { Node } from '@/types/Node'
import { ValidationException } from '@/exceptions/ValidationException'
import { Plus, Trash2 } from 'lucide-vue-next'

const emit = defineEmits<{ updated: [node: Node] }>()

const dialogOpen = ref(false)
const loading = ref(false)
const errors = ref<Record<string, string[]>>({})

const node = ref<Node>({} as Node)

const show = (initialNode: Node) => {
  node.value = JSON.parse(JSON.stringify(initialNode))
  if (!node.value.instances) node.value.instances = []
  errors.value = {}
  dialogOpen.value = true
}

const addInstance = () => {
  if (!node.value.instances) node.value.instances = []
  node.value.instances.push({
    nanoCpus: 1000000000,
    memoryBytes: 536870912,
    workload: node.value.type === 'worker' ? 'medium' : null,
  })
}

const removeInstance = (index: number) => {
  if (!node.value.instances) return
  node.value.instances.splice(index, 1)
}

const formatCpus = (nanoCpus: number) => {
  return (nanoCpus / 1000000000).toFixed(1)
}

const formatMemory = (bytes: number) => {
  return (bytes / (1024 * 1024)).toFixed(0)
}

const handleUpdate = async () => {
  errors.value = {}
  loading.value = true

  try {
    const updated = await NodeService.updateNode(node.value.id, node.value)
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
    <DialogContent class="max-w-2xl">
      <DialogHeader>
        <DialogTitle>Edit Node</DialogTitle>
        <DialogDescription>Update node configuration and instances.</DialogDescription>
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

        <!-- Instances -->
        <div class="grid gap-3">
          <div class="flex items-center justify-between">
            <Label>Instances</Label>
            <Button type="button" variant="outline" size="sm" @click="addInstance">
              <Plus class="h-3 w-3 mr-1" />
              Add
            </Button>
          </div>

          <div v-if="!node.instances || node.instances.length === 0"
            class="text-sm text-muted-foreground text-center py-3 border border-dashed rounded-md">
            No instances configured
          </div>

          <div v-for="(instance, index) in node.instances" :key="index"
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
            <div v-if="node.type === 'worker'" class="grid gap-1 flex-1">
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
            {{ loading ? 'Saving...' : 'Save Changes' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
