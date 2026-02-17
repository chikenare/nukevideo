<script setup lang="ts">
import { ref } from 'vue'
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
import { Plus } from 'lucide-vue-next'
import NodeService from '@/services/NodeService'
import type { NodeType } from '@/types/Node'

const emit = defineEmits<{ created: [] }>()

const open = ref(false)
const loading = ref(false)
const error = ref('')

const form = ref({
  name: '',
  type: 'worker' as NodeType,
  host: '',
  max_workers: 3,
})

const resetForm = () => {
  form.value = { name: '', type: 'worker', host: '', max_workers: 3 }
  error.value = ''
}

const handleSubmit = async () => {
  error.value = ''
  loading.value = true
  try {
    await NodeService.createNode(form.value)
    open.value = false
    resetForm()
    emit('created')
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Failed to create node'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="open" @update:open="(val) => { if (!val) resetForm() }">
    <DialogTrigger as-child>
      <Button>
        <Plus :size="16" class="mr-2" />
        Create Node
      </Button>
    </DialogTrigger>
    <DialogContent class="sm:max-w-[425px]">
      <DialogHeader>
        <DialogTitle>Create Node</DialogTitle>
        <DialogDescription>Add a new processing node to the cluster.</DialogDescription>
      </DialogHeader>

      <form @submit.prevent="handleSubmit" class="flex flex-col gap-4">
        <div class="flex flex-col gap-2">
          <Label for="name">Name</Label>
          <Input id="name" v-model="form.name" placeholder="node-us-east-1" required />
        </div>

        <div class="flex flex-col gap-2">
          <Label for="type">Type</Label>
          <Select v-model="form.type">
            <SelectTrigger>
              <SelectValue placeholder="Select type" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="worker">Worker</SelectItem>
              <SelectItem value="stream">Stream</SelectItem>
              <SelectItem value="download">Download</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="flex flex-col gap-2">
          <Label for="host">Host</Label>
          <Input id="host" v-model="form.host" placeholder="192.168.1.100 or node.example.com" required />
        </div>

        <div class="flex flex-col gap-2">
          <Label for="max_workers">Max Workers</Label>
          <Input id="max_workers" v-model.number="form.max_workers" type="number" min="1" max="100" required />
        </div>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

        <DialogFooter>
          <Button type="submit" :disabled="loading">
            {{ loading ? 'Creating...' : 'Create Node' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
