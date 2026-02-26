<script setup lang="ts">
import Badge from '@/components/ui/badge/Badge.vue'
import { Button } from '@/components/ui/button'
import { Progress } from '@/components/ui/progress'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ref } from 'vue'
import NodeService from '@/services/NodeService'
import type { Node } from '@/types/Node'
import { RefreshCw, Rocket } from 'lucide-vue-next'
import DeployConfirmDialog from './DeployConfirmDialog.vue'

const props = defineProps<{ nodes: Node[] }>()
const emit = defineEmits<{ deploy: [node: Node]; updated: [node: Node] }>()

const deployDialog = ref<InstanceType<typeof DeployConfirmDialog> | null>(null)

const refreshingNodeId = ref<number | null>(null)

const refreshMetrics = async (nodeId: number) => {
  refreshingNodeId.value = nodeId
  try {
    const updated = await NodeService.getMetrics(nodeId)
    emit('updated', updated)
  } catch (error) {
    console.error('Error refreshing metrics:', error)
  } finally {
    refreshingNodeId.value = null
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
  if (!node.metrics || !node.metrics.memory_total) return 0
  return Math.round((node.metrics.memory_usage / node.metrics.memory_total) * 100)
}

const diskPercent = (node: Node): number => {
  if (!node.metrics || !node.metrics.disk_total) return 0
  return Math.round((node.metrics.disk_usage / node.metrics.disk_total) * 100)
}

const statusVariant = (status: string) => {
  if (status === 'running') return 'default'
  if (status === 'exited') return 'destructive'
  return 'outline'
}
</script>

<template>
  <div class="overflow-hidden rounded-lg border">
    <Table>
      <TableHeader class="bg-muted">
        <TableRow>
          <TableHead>Node</TableHead>
          <TableHead>Status</TableHead>
          <TableHead class="w-[180px]">CPU</TableHead>
          <TableHead class="w-[180px]">Memory</TableHead>
          <TableHead class="w-[180px]">Disk</TableHead>
          <TableHead>Network</TableHead>
          <TableHead class="text-right">Last Seen</TableHead>
          <TableHead class="w-12"></TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-for="node in props.nodes" :key="node.id">
          <TableCell>
            <div class="flex flex-col">
              <span class="font-medium">{{ node.name }}</span>
              <span class="text-xs text-muted-foreground">{{ node.ipAddress }}</span>
            </div>
          </TableCell>

          <TableCell>
            <div class="flex flex-col gap-1">
              <Badge :variant="statusVariant(node.status)" class="w-fit text-xs">
                {{ node.status || 'unknown' }}
              </Badge>
              <span v-if="node.uptime" class="text-xs text-muted-foreground">{{ node.uptime }}</span>
            </div>
          </TableCell>

          <TableCell>
            <template v-if="node.metrics">
              <div class="flex items-center gap-2">
                <Progress :model-value="node.metrics.cpu_percent" class="h-1.5 flex-1" />
                <span class="text-xs font-medium w-10 text-right">{{ node.metrics.cpu_percent }}%</span>
              </div>
              <span class="text-xs text-muted-foreground">
                Load: {{ node.metrics.load_average.join(', ') }}
              </span>
            </template>
            <span v-else class="text-xs text-muted-foreground">-</span>
          </TableCell>

          <TableCell>
            <template v-if="node.metrics">
              <div class="flex items-center gap-2">
                <Progress :model-value="memoryPercent(node)" class="h-1.5 flex-1" />
                <span class="text-xs font-medium w-10 text-right">{{ memoryPercent(node) }}%</span>
              </div>
              <span class="text-xs text-muted-foreground">{{ formatBytes(node.metrics.memory_usage) }} / {{
                formatBytes(node.metrics.memory_total) }}</span>
            </template>
            <span v-else class="text-xs text-muted-foreground">-</span>
          </TableCell>

          <TableCell>
            <template v-if="node.metrics">
              <div class="flex items-center gap-2">
                <Progress :model-value="diskPercent(node)" class="h-1.5 flex-1" />
                <span class="text-xs font-medium w-10 text-right">{{ diskPercent(node) }}%</span>
              </div>
              <span class="text-xs text-muted-foreground">{{ formatBytes(node.metrics.disk_usage) }} / {{
                formatBytes(node.metrics.disk_total) }}</span>
            </template>
            <span v-else class="text-xs text-muted-foreground">-</span>
          </TableCell>

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

          <TableCell class="text-right text-xs text-muted-foreground">
            {{ node.lastSeenAt || '-' }}
          </TableCell>

          <TableCell>
            <div class="flex gap-1">
              <Button variant="ghost" size="icon" @click="deployDialog?.show(node)" title="Deploy">
                <Rocket class="h-4 w-4" />
              </Button>
              <Button variant="ghost" size="icon" @click="refreshMetrics(node.id)"
                :disabled="refreshingNodeId === node.id">
                <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': refreshingNodeId === node.id }" />
              </Button>
            </div>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
    <DeployConfirmDialog ref="deployDialog" @confirm="(node) => emit('deploy', node)" />
  </div>
</template>
