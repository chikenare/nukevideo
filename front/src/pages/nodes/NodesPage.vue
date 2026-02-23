<script setup lang="ts">
import Badge from '@/components/ui/badge/Badge.vue'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import { Progress } from '@/components/ui/progress'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ref, onMounted, onUnmounted } from 'vue'
import NodeService from '@/services/NodeService'
import type { Node, NodesResponse } from '@/types/Node'

const nodesData = ref<NodesResponse>({ nodes: [], summary: { totalCapacity: 0, availableSlots: 0 } })
const loading = ref(true)
let pollInterval: ReturnType<typeof setInterval> | null = null

const fetchNodes = async () => {
  try {
    nodesData.value = await NodeService.getNodes()
  } catch (error) {
    console.error('Error fetching nodes:', error)
  } finally {
    loading.value = false
  }
}

const formatBytes = (bytes: number): string => {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`
}

const memoryPercent = (node: Node): number => {
  if (!node.metrics || !node.metrics.memory_limit) return 0
  return Math.round((node.metrics.memory_usage / node.metrics.memory_limit) * 100)
}

const statusVariant = (status: string) => {
  if (status === 'running') return 'default'
  if (status === 'exited') return 'destructive'
  return 'outline'
}

onMounted(() => {
  fetchNodes()
  pollInterval = setInterval(fetchNodes, 30000)
})

onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval)
})
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <div>
      <h1 class="text-2xl font-bold">Nodes</h1>
      <p class="text-muted-foreground">{{ nodesData.nodes.length }} nodes</p>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <Spinner />
    </div>

    <div v-else-if="nodesData.nodes.length === 0" class="text-center text-muted-foreground py-12">
      No nodes found. Run <code class="bg-muted px-1.5 py-0.5 rounded text-sm">php artisan node:sync</code> to sync.
    </div>

    <div v-else class="overflow-hidden rounded-lg border">
      <Table>
        <TableHeader class="bg-muted">
          <TableRow>
            <TableHead>Node</TableHead>
            <TableHead>Status</TableHead>
            <TableHead class="w-[180px]">CPU</TableHead>
            <TableHead class="w-[180px]">Memory</TableHead>
            <TableHead>Disk I/O</TableHead>
            <TableHead>Network</TableHead>
            <TableHead class="text-right">Last Seen</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="node in nodesData.nodes" :key="node.id">
            <!-- Node info -->
            <TableCell>
              <div class="flex flex-col">
                <span class="font-medium">{{ node.name }}</span>
                <a :href="node.baseUrl" target="_blank" class="text-xs text-muted-foreground">{{ node.location }}</a>
              </div>
            </TableCell>

            <!-- Status -->
            <TableCell>
              <div class="flex flex-col gap-1">
                <Badge :variant="statusVariant(node.status)" class="w-fit text-xs">
                  {{ node.status }}
                </Badge>
                <span v-if="node.uptime" class="text-xs text-muted-foreground">{{ node.uptime }}</span>
              </div>
            </TableCell>

            <!-- CPU -->
            <TableCell>
              <template v-if="node.metrics">
                <div class="flex items-center gap-2">
                  <Progress :model-value="node.metrics.cpu_percent" class="h-1.5 flex-1" />
                  <span class="text-xs font-medium w-10 text-right">{{ node.metrics.cpu_percent }}%</span>
                </div>
              </template>
              <span v-else class="text-xs text-muted-foreground">-</span>
            </TableCell>

            <!-- Memory -->
            <TableCell>
              <template v-if="node.metrics">
                <div class="flex items-center gap-2">
                  <Progress :model-value="memoryPercent(node)" class="h-1.5 flex-1" />
                  <span class="text-xs font-medium w-10 text-right">{{ memoryPercent(node) }}%</span>
                </div>
                <span class="text-xs text-muted-foreground">{{ formatBytes(node.metrics.memory_usage) }} / {{
                  formatBytes(node.metrics.memory_limit) }}</span>
              </template>
              <span v-else class="text-xs text-muted-foreground">-</span>
            </TableCell>

            <!-- Disk -->
            <TableCell>
              <template v-if="node.metrics">
                <div class="text-xs">
                  <span class="text-muted-foreground">R</span> {{ formatBytes(node.metrics.disk_read) }}
                  <span class="text-muted-foreground mx-0.5">/</span>
                  <span class="text-muted-foreground">W</span> {{ formatBytes(node.metrics.disk_write) }}
                </div>
              </template>
              <span v-else class="text-xs text-muted-foreground">-</span>
            </TableCell>

            <!-- Network -->
            <TableCell>
              <template v-if="node.metrics">
                <div class="text-xs">
                  <span class="text-muted-foreground">&darr;</span> {{ formatBytes(node.metrics.network_rx) }}
                  <span class="text-muted-foreground mx-0.5">/</span>
                  <span class="text-muted-foreground">&uarr;</span> {{ formatBytes(node.metrics.network_tx) }}
                </div>
              </template>
              <span v-else class="text-xs text-muted-foreground">-</span>
            </TableCell>

            <!-- Last Seen -->
            <TableCell class="text-right text-xs text-muted-foreground">
              {{ node.lastSeenAt || '-' }}
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>
  </div>
</template>
