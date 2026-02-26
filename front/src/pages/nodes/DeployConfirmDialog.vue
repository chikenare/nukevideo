<script setup lang="ts">
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { ref } from 'vue'
import type { Node } from '@/types/Node'

const emit = defineEmits<{ confirm: [node: Node] }>()

const open = ref(false)
const node = ref<Node | null>(null)

const show = (targetNode: Node) => {
  node.value = targetNode
  open.value = true
}

const handleConfirm = () => {
  if (node.value) {
    emit('confirm', node.value)
  }
  open.value = false
}

defineExpose({ show })
</script>

<template>
  <AlertDialog v-model:open="open">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Deploy to {{ node?.name }}?</AlertDialogTitle>
        <AlertDialogDescription>
          This will deploy to <strong>{{ node?.ipAddress }}</strong> ({{ node?.type }}).
          The process will build and start the Docker containers on this node.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>Cancel</AlertDialogCancel>
        <AlertDialogAction @click="handleConfirm">Deploy</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
