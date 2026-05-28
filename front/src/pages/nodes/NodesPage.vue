<script setup lang="ts">
import Spinner from '@/components/ui/spinner/Spinner.vue'
import { Button } from '@/components/ui/button'
import { ref, onMounted, onUnmounted } from 'vue'
import NodeService from '@/services/NodeService'
import type { Node, NodeMetrics, NodesResponse } from '@/types/Node'
import CreateNodeDialog from './CreateNodeDialog.vue'
import NodeTable from './NodeTable.vue'
import NodeEnvironmentDialog from './NodeEnvironmentDialog.vue'
import { Settings } from 'lucide-vue-next'

const nodesData = ref<NodesResponse>({ nodes: [], summary: { totalCapacity: 0, availableSlots: 0 } })
const loading = ref(true)
const metricsMap = ref<Record<string, NodeMetrics>>({})

let pollInterval: ReturnType<typeof setInterval> | null = null
let metricsInterval: ReturnType<typeof setInterval> | null = null

const mergeMetrics = () => {
  for (const node of nodesData.value.nodes) {
    node.metrics = metricsMap.value[node.id] ?? null
  }
}

const fetchNodes = async () => {
  try {
    nodesData.value = await NodeService.getNodes()
    mergeMetrics()
  } catch (error) {
    console.error('Error fetching nodes:', error)
  } finally {
    loading.value = false
  }
}

const fetchMetrics = async () => {
  try {
    metricsMap.value = await NodeService.getAllMetrics()
    mergeMetrics()
  } catch (error) {
    console.error('Error fetching metrics:', error)
  }
}

const envDialogRef = ref<InstanceType<typeof NodeEnvironmentDialog>>()

const openEnvDialog = () => {
  envDialogRef.value?.show()
}

const onNodeUpdated = (updated: Node) => {
  const idx = nodesData.value.nodes.findIndex(n => n.id === updated.id)
  if (idx !== -1) {
    nodesData.value.nodes[idx] = updated
  }
}

onMounted(async () => {
  await fetchNodes()
  fetchMetrics()
  pollInterval = setInterval(fetchNodes, 10000)
  metricsInterval = setInterval(fetchMetrics, 60000)
})

onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval)
  if (metricsInterval) clearInterval(metricsInterval)
})
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold">Nodes</h1>
        <p class="text-muted-foreground">{{ nodesData.nodes.length }} nodes</p>
      </div>
      <div class="flex items-center gap-2">
        <Button variant="outline" size="sm" @click="openEnvDialog()">
          <Settings class="h-4 w-4 mr-1" />
          Environment
        </Button>
        <CreateNodeDialog @created="fetchNodes" />
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <Spinner />
    </div>

    <div v-else-if="nodesData.nodes.length === 0" class="text-center text-muted-foreground py-12">
      No nodes found. Click "Add Node" to get started.
    </div>

    <NodeTable v-else :nodes="nodesData.nodes" @updated="onNodeUpdated" @deleted="fetchNodes" />

    <NodeEnvironmentDialog ref="envDialogRef" />
  </div>
</template>
