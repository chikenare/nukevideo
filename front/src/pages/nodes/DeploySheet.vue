<script setup lang="ts">
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet'
import { ref, nextTick, onUnmounted } from 'vue'
import echo from '@/lib/echo'
import NodeService from '@/services/NodeService'
import type { Node } from '@/types/Node'

const open = ref(false)
const node = ref<Node | null>(null)
const logs = ref<string[]>([])
const logContainer = ref<HTMLElement | null>(null)

const scrollToBottom = () => {
  nextTick(() => {
    if (logContainer.value) {
      logContainer.value.scrollTop = logContainer.value.scrollHeight
    }
  })
}

const start = async (targetNode: Node) => {
  node.value = targetNode
  logs.value = []
  open.value = true

  echo.channel(`node.${targetNode.id}`).listen('NodeOutput', (e: { output: string }) => {
    logs.value.push(e.output)
    scrollToBottom()
  })

  try {
    await NodeService.deploy(targetNode.id)
    logs.value.push('>>> Deploy initiated, waiting for output...\n')
    scrollToBottom()
  } catch {
    logs.value.push('>>> Failed to start deploy.\n')
  }
}

const close = () => {
  if (node.value) {
    echo.leave(`node.${node.value.id}.deploy`)
  }
  open.value = false
  node.value = null
  logs.value = []
}

onUnmounted(() => {
  if (node.value) {
    echo.leave(`node.${node.value.id}.deploy`)
  }
})

defineExpose({ start })
</script>

<template>
  <Sheet :open="open" @update:open="(v: boolean) => { if (!v) close() }">
    <SheetContent side="bottom" class="h-[50vh] flex flex-col">
      <SheetHeader>
        <SheetTitle>Deploy: {{ node?.name }}</SheetTitle>
        <SheetDescription>{{ node?.ipAddress }} &middot; {{ node?.type }}</SheetDescription>
      </SheetHeader>
      <div ref="logContainer"
        class="flex-1 overflow-y-auto rounded-md bg-zinc-950 p-4 font-mono text-xs text-green-400">
        <pre v-if="logs.length === 0" class="text-zinc-500">Waiting for output...</pre>
        <pre v-for="(line, i) in logs" :key="i">{{ line }}</pre>
      </div>
    </SheetContent>
  </Sheet>
</template>
