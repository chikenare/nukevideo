<script setup lang="ts">
import { ref, nextTick, onUnmounted } from 'vue'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import NodeService from '@/services/NodeService'

type Node = App.Data.NodeData
type Operation = App.Data.ActivityLogData

defineProps<{ nodes: Node[] }>()

const open = ref(false)
// Undefined: every node, and so the latest operation anywhere in the fleet.
const nodeId = ref<number | undefined>()
const operation = ref<Operation | null>(null)
const lines = ref<string[]>([])
const status = ref('')
const terminal = ref<HTMLElement | null>(null)
let cursor = 0
let timer: ReturnType<typeof setInterval> | null = null
let polling = false
let loads = 0

const live = (s: string) => s === 'queued' || s === 'running'

const statusClass = (s: string) =>
  ({
    succeeded: 'text-emerald-500',
    failed: 'text-red-500',
    running: 'text-blue-500',
    cancelled: 'text-muted-foreground',
  })[s] ?? 'text-yellow-500'

const stop = () => {
  if (timer) clearInterval(timer)
  timer = null
}

// One request at a time, and a late answer for an operation no longer shown is dropped: either
// would append lines twice or into the wrong log.
const poll = async () => {
  const op = operation.value
  if (!op || polling) return
  polling = true
  const res = await NodeService.getOperationLines(op.id, cursor).finally(() => (polling = false))
  if (operation.value?.id !== op.id) return
  lines.value.push(...res.lines)
  cursor = res.next
  status.value = res.status
  if (res.lines.length) {
    nextTick(() => terminal.value && (terminal.value.scrollTop = terminal.value.scrollHeight))
  }
  if (!live(res.status)) stop()
}

// The latest operation, of one node or of any: opened from a deploy, that is the deploy. Older
// ones are in the activity log.
const load = async () => {
  stop()
  // Only the newest load may start polling: an older answer arriving last, or one arriving after
  // the dialog closed, would leave an interval nothing ever clears.
  const current = ++loads
  const target = nodeId.value
  operation.value = null
  lines.value = []
  status.value = ''
  cursor = 0

  const [latest] = await NodeService.getOperations({ node: target })
  if (!latest || current !== loads || !open.value) return
  operation.value = latest
  status.value = String(latest.properties.status)
  poll()
  timer = setInterval(poll, 1500)
}

const show = (id?: number) => {
  nodeId.value = id
  open.value = true
  load()
}

onUnmounted(stop)

defineExpose({ show })
</script>

<template>
  <Dialog v-model:open="open" @update:open="(value) => !value && stop()">
    <DialogContent class="flex max-h-[90vh] flex-col sm:max-w-5xl">
      <DialogHeader class="flex-row items-start justify-between gap-4 pr-8">
        <div class="flex flex-col gap-1.5">
          <DialogTitle>Logs</DialogTitle>
          <DialogDescription>
            <template v-if="operation">
              {{ operation.description }} · {{ new Date(operation.createdAt).toLocaleString() }}
              <span v-if="operation.properties.force"> · force</span>
              · <span :class="statusClass(status)">{{ status }}</span>
            </template>
            <template v-else>No operations yet.</template>
          </DialogDescription>
        </div>
        <select
          v-model="nodeId"
          class="rounded-md border bg-background px-2 py-1 text-sm"
          aria-label="Node"
          @change="load"
        >
          <option :value="undefined">All nodes</option>
          <option v-for="n in nodes" :key="n.id" :value="n.id">{{ n.name }}</option>
        </select>
      </DialogHeader>
      <div
        ref="terminal"
        class="h-[65vh] overflow-y-auto rounded-md border bg-zinc-950 p-3 font-mono text-xs leading-relaxed"
      >
        <div
          v-for="(line, i) in lines"
          :key="i"
          class="break-all whitespace-pre-wrap"
          :class="
            line.startsWith('===')
              ? 'font-semibold text-blue-400'
              : line.startsWith('ERROR')
                ? 'text-red-400'
                : 'text-zinc-300'
          "
          v-text="line"
        />
        <div v-if="live(status)" class="text-zinc-500">…</div>
      </div>
    </DialogContent>
  </Dialog>
</template>
