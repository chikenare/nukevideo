<script setup lang="ts">
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import { ref } from 'vue'
import type { Node, DockerContainer } from '@/types/Node'
import NodeService from '@/services/NodeService'

const open = ref(false)
const node = ref<Node | null>(null)
const containers = ref<DockerContainer[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const show = async (targetNode: Node) => {
  node.value = targetNode
  containers.value = []
  loading.value = true
  error.value = null
  open.value = true

  try {
    containers.value = await NodeService.getContainers(targetNode.id)
  } catch (err: any) {
    error.value = err.response?.data?.message || err.message || 'Failed to fetch containers'
  } finally {
    loading.value = false
  }
}

const shortName = (name: string): string => {
  return name.replace(/^nukevideo_/, '')
}

const stateColor = (state: string): string => {
  switch (state) {
    case 'running': return 'text-emerald-500'
    case 'exited': return 'text-red-500'
    case 'restarting': return 'text-yellow-500'
    default: return 'text-muted-foreground'
  }
}

defineExpose({ show })
</script>

<template>
  <AlertDialog v-model:open="open">
    <AlertDialogContent class="max-w-lg">
      <AlertDialogHeader>
        <AlertDialogTitle>Containers - {{ node?.name }}</AlertDialogTitle>
      </AlertDialogHeader>

      <div v-if="loading" class="flex items-center justify-center py-6">
        <Spinner />
      </div>

      <div v-else-if="error" class="text-sm text-red-500 py-4">{{ error }}</div>

      <div v-else-if="containers.length === 0" class="text-sm text-muted-foreground py-4">
        No containers found.
      </div>

      <div v-else class="flex flex-col gap-2 py-2 max-h-80 overflow-y-auto">
        <div
          v-for="container in containers"
          :key="container.ID"
          class="flex items-center justify-between rounded-md border px-3 py-2"
        >
          <div class="flex flex-col gap-0.5">
            <span class="text-sm font-medium">{{ shortName(container.Names) }}</span>
            <span class="text-xs text-muted-foreground">{{ container.Image }}</span>
          </div>
          <div class="flex flex-col items-end gap-0.5">
            <span class="text-xs font-medium" :class="stateColor(container.State)">
              {{ container.State }}
            </span>
            <span class="text-xs text-muted-foreground">{{ container.Status }}</span>
          </div>
        </div>
      </div>

      <AlertDialogFooter>
        <AlertDialogCancel>Close</AlertDialogCancel>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
