<script setup lang="ts">
import Badge from '@/components/ui/badge/Badge.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import { Progress } from '@/components/ui/progress'
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
import { ValidationException } from '@/exceptions/ValidationException'
import { Plus, RefreshCw } from 'lucide-vue-next'

const nodesData = ref<NodesResponse>({ nodes: [], summary: { totalCapacity: 0, availableSlots: 0 } })
const loading = ref(true)
const dialogOpen = ref(false)
const createLoading = ref(false)
const refreshingNodeId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})

const newNode = ref({
  name: '',
  ip_address: '',
  type: 'worker' as 'worker' | 'proxy',
})

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

const handleCreate = async () => {
  errors.value = {}
  createLoading.value = true

  try {
    await NodeService.createNode({
      name: newNode.value.name,
      ip_address: newNode.value.ip_address,
      type: newNode.value.type,
    })
    newNode.value = { name: '', ip_address: '', type: 'worker' }
    dialogOpen.value = false
    await fetchNodes()
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    }
  } finally {
    createLoading.value = false
  }
}

const refreshMetrics = async (nodeId: number) => {
  refreshingNodeId.value = nodeId
  try {
    const updated = await NodeService.getMetrics(nodeId)
    const idx = nodesData.value.nodes.findIndex(n => n.id === nodeId)
    if (idx !== -1) {
      nodesData.value.nodes[idx] = updated
    }
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

onMounted(() => {
  fetchNodes()
  pollInterval = setInterval(fetchNodes, 10000)
})

onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval)
})
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold">Nodes</h1>
        <p class="text-muted-foreground">{{ nodesData.nodes.length }} nodes</p>
      </div>

      <Dialog v-model:open="dialogOpen">
        <DialogTrigger as-child>
          <Button>
            <Plus class="h-4 w-4 mr-2" />
            Add Node
          </Button>
        </DialogTrigger>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Node</DialogTitle>
            <DialogDescription>Add a new node by providing its SSH connection details.</DialogDescription>
          </DialogHeader>
          <form @submit.prevent="handleCreate" class="grid gap-4">
            <div class="grid gap-2">
              <Label for="node_name">Name</Label>
              <Input id="node_name" v-model="newNode.name" placeholder="e.g. worker-01" required />
              <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name[0] }}</p>
            </div>
            <div class="grid gap-2">
              <Label for="node_ip">IP Address</Label>
              <Input id="node_ip" v-model="newNode.ip_address" placeholder="e.g. 192.168.1.100" required />
              <p v-if="errors.ip_address" class="text-sm text-destructive">{{ errors.ip_address[0] }}</p>
            </div>
            <div class="grid gap-2">
              <Label for="node_type">Type</Label>
              <Select v-model="newNode.type">
                <SelectTrigger>
                  <SelectValue placeholder="Select type" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="worker">Worker</SelectItem>
                  <SelectItem value="proxy">Proxy</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <DialogFooter>
              <Button type="submit" :disabled="createLoading">
                {{ createLoading ? 'Creating...' : 'Create Node' }}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <Spinner />
    </div>

    <div v-else-if="nodesData.nodes.length === 0" class="text-center text-muted-foreground py-12">
      No nodes found. Click "Add Node" to get started.
    </div>

    <div v-else class="overflow-hidden rounded-lg border">
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
          <TableRow v-for="node in nodesData.nodes" :key="node.id">
            <!-- Node info -->
            <TableCell>
              <div class="flex flex-col">
                <span class="font-medium">{{ node.name }}</span>
                <span class="text-xs text-muted-foreground">{{ node.ipAddress }}</span>
              </div>
            </TableCell>

            <!-- Status -->
            <TableCell>
              <div class="flex flex-col gap-1">
                <Badge :variant="statusVariant(node.status)" class="w-fit text-xs">
                  {{ node.status || 'unknown' }}
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
                <span class="text-xs text-muted-foreground">
                  Load: {{ node.metrics.load_average.join(', ') }}
                </span>
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
                  formatBytes(node.metrics.memory_total) }}</span>
              </template>
              <span v-else class="text-xs text-muted-foreground">-</span>
            </TableCell>

            <!-- Disk -->
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

            <!-- Refresh -->
            <TableCell>
              <Button variant="ghost" size="icon" @click="refreshMetrics(node.id)"
                :disabled="refreshingNodeId === node.id">
                <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': refreshingNodeId === node.id }" />
              </Button>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>
  </div>
</template>
