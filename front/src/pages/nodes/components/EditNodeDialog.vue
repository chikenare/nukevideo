<script setup lang="ts">
import { ref, watch } from 'vue'
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
import NodeService from '@/services/NodeService'
import type { Node, NodeType } from '@/types/Node'

const props = defineProps<{ node: Node | null }>()
const emit = defineEmits<{ updated: []; 'update:node': [value: null] }>()

const open = ref(false)
const loading = ref(false)
const deploying = ref(false)
const error = ref('')
const deployOutput = ref('')

const form = ref({
  name: '',
  type: 'worker' as NodeType,
  host: '',
  max_workers: 3,
  is_active: true,
  latitude: null as number | null,
  longitude: null as number | null,
  country: '',
  city: '',
})

watch(() => props.node, (node) => {
  if (node) {
    form.value = {
      name: node.name,
      type: node.type,
      host: node.host || '',
      max_workers: node.maxWorkers,
      is_active: node.isActive,
      latitude: node.latitude,
      longitude: node.longitude,
      country: node.country || '',
      city: node.city || '',
    }
    error.value = ''
    deployOutput.value = ''
    open.value = true
  }
})

watch(open, (val) => {
  if (!val) {
    emit('update:node', null)
  }
})

const handleSubmit = async () => {
  if (!props.node) return
  error.value = ''
  loading.value = true
  try {
    await NodeService.updateNode(props.node.id, {
      name: form.value.name,
      type: form.value.type,
      host: form.value.host || undefined,
      max_workers: form.value.max_workers,
      is_active: form.value.is_active,
      latitude: form.value.latitude || null,
      longitude: form.value.longitude || null,
      country: form.value.country || null,
      city: form.value.city || null,
    })
    open.value = false
    emit('updated')
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Failed to update node'
  } finally {
    loading.value = false
  }
}

const handleDeploy = async () => {
  if (!props.node) return
  deploying.value = true
  deployOutput.value = ''
  error.value = ''
  try {
    const result = await NodeService.deployNode(props.node.id)
    deployOutput.value = result.output
    if (!result.success) {
      error.value = result.message
    }
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Deploy failed'
  } finally {
    deploying.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-[500px] max-h-[90vh] overflow-y-auto">
      <DialogHeader>
        <DialogTitle>Edit Node</DialogTitle>
        <DialogDescription>Update node configuration and location.</DialogDescription>
      </DialogHeader>

      <form @submit.prevent="handleSubmit" class="flex flex-col gap-4">
        <div class="grid grid-cols-2 gap-4">
          <div class="flex flex-col gap-2">
            <Label for="edit-name">Name</Label>
            <Input id="edit-name" v-model="form.name" required />
          </div>

          <div class="flex flex-col gap-2">
            <Label for="edit-type">Type</Label>
            <Select v-model="form.type">
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="worker">Worker</SelectItem>
                <SelectItem value="stream">Stream</SelectItem>
                <SelectItem value="download">Download</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div class="flex flex-col gap-2">
            <Label for="edit-host">Host</Label>
            <Input id="edit-host" v-model="form.host" placeholder="192.168.1.100" />
          </div>

          <div class="flex flex-col gap-2">
            <Label for="edit-max-workers">Max Workers</Label>
            <Input id="edit-max-workers" v-model.number="form.max_workers" type="number" min="1" max="100" />
          </div>
        </div>

        <div class="flex items-center gap-2">
          <input id="edit-active" type="checkbox" v-model="form.is_active" class="rounded border-input" />
          <Label for="edit-active">Active</Label>
        </div>

        <div class="border-t pt-4">
          <h4 class="text-sm font-medium mb-3">Location</h4>
          <div class="grid grid-cols-2 gap-4">
            <div class="flex flex-col gap-2">
              <Label for="edit-country">Country</Label>
              <Input id="edit-country" v-model="form.country" placeholder="US" />
            </div>
            <div class="flex flex-col gap-2">
              <Label for="edit-city">City</Label>
              <Input id="edit-city" v-model="form.city" placeholder="New York" />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-4 mt-3">
            <div class="flex flex-col gap-2">
              <Label for="edit-lat">Latitude</Label>
              <Input id="edit-lat" v-model.number="form.latitude" type="number" step="0.0000001" min="-90" max="90" placeholder="40.7128" />
            </div>
            <div class="flex flex-col gap-2">
              <Label for="edit-lng">Longitude</Label>
              <Input id="edit-lng" v-model.number="form.longitude" type="number" step="0.0000001" min="-180" max="180" placeholder="-74.0060" />
            </div>
          </div>
        </div>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

        <div v-if="deployOutput" class="border rounded p-3 bg-muted">
          <h4 class="text-sm font-medium mb-2">Deploy Output</h4>
          <pre class="text-xs whitespace-pre-wrap max-h-40 overflow-y-auto">{{ deployOutput }}</pre>
        </div>

        <DialogFooter class="gap-2">
          <Button type="button" variant="outline" @click="handleDeploy" :disabled="deploying || loading">
            {{ deploying ? 'Deploying...' : 'Deploy' }}
          </Button>
          <Button type="submit" :disabled="loading || deploying">
            {{ loading ? 'Saving...' : 'Save Changes' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
