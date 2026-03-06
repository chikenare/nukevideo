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
import { Switch } from '@/components/ui/switch'
import { ref } from 'vue'
import NodeService from '@/services/NodeService'
import type { Node } from '@/types/Node'
import { ValidationException } from '@/exceptions/ValidationException'

const emit = defineEmits<{ updated: [node: Node] }>()

const dialogOpen = ref(false)
const loading = ref(false)
const errors = ref<Record<string, string[]>>({})

const nodeId = ref<number>(0)
const name = ref('')
const isActive = ref(true)

const show = (node: Node) => {
  nodeId.value = node.id
  name.value = node.name
  isActive.value = node.isActive
  errors.value = {}
  dialogOpen.value = true
}

const handleUpdate = async () => {
  errors.value = {}
  loading.value = true

  try {
    const updated = await NodeService.updateNode(nodeId.value, {
      name: name.value,
      is_active: isActive.value,
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
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Edit Node</DialogTitle>
        <DialogDescription>Update node name and status.</DialogDescription>
      </DialogHeader>
      <form @submit.prevent="handleUpdate" class="grid gap-4">
        <div class="grid gap-2">
          <Label for="edit_node_name">Name</Label>
          <Input id="edit_node_name" v-model="name" placeholder="e.g. worker-01" required />
          <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name[0] }}</p>
        </div>
        <div class="flex items-center justify-between">
          <Label for="edit_node_active">Active</Label>
          <Switch id="edit_node_active" v-model="isActive" @update:checked="isActive = $event" />
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
