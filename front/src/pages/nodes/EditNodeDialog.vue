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

const emit = defineEmits<{ updated: [node: Node] }>()

const dialogOpen = ref(false)
const loading = ref(false)
const errors = ref<Record<string, string[]>>({})

const node = ref<Node>({} as Node)

const show = (initialNode: Node) => {
  node.value = JSON.parse(JSON.stringify(initialNode))
  errors.value = {}
  dialogOpen.value = true
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
        <div v-if="node.type === 'proxy'" class="flex items-center justify-between">
          <div>
            <Label for="edit_node_cdn">CDN Mode</Label>
            <p class="text-xs text-muted-foreground">Disables local nginx cache. Use when a CDN handles caching.</p>
          </div>
          <Switch id="edit_node_cdn" v-model="node.cdnMode" @update:checked="node.cdnMode = $event" />
        </div>
        <div v-if="node.type === 'worker'" class="flex items-center justify-between">
          <Label for="edit_node_gpu">GPU (NVIDIA)</Label>
          <Switch id="edit_node_gpu" v-model="node.hasGpu" @update:checked="node.hasGpu = $event" />
        </div>

        <div v-if="node.type === 'worker'" class="grid gap-2">
          <Label for="edit_node_workers">Workers</Label>
          <Input id="edit_node_workers" type="number" min="1" max="20" v-model.number="node.workers" />
          <p v-if="errors.workers" class="text-sm text-destructive">{{ errors.workers[0] }}</p>
        </div>

        <div class="grid gap-2">
          <Label for="edit_node_env">Environment Overrides</Label>
          <textarea
            id="edit_node_env"
            v-model="node.env"
            rows="6"
            class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring font-mono"
            placeholder="VOD_CACHE_MAX_SIZE=20g&#10;VOD_CACHE_INACTIVE=2h"
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
