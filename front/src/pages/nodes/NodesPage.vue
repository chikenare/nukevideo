<script setup lang="ts">
import Spinner from '@/components/ui/spinner/Spinner.vue'
import { ref, onMounted, onUnmounted } from 'vue'
import NodeService from '@/services/NodeService'
import type { Node, NodesResponse } from '@/types/Node'
import CreateNodeDialog from './CreateNodeDialog.vue'
import NodeTable from './NodeTable.vue'
import DeploySheet from './DeploySheet.vue'

const nodesData = ref<NodesResponse>({ nodes: [], summary: { totalCapacity: 0, availableSlots: 0 } })
const loading = ref(true)
const deploySheet = ref<InstanceType<typeof DeploySheet> | null>(null)

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

const onNodeUpdated = (updated: Node) => {
  const idx = nodesData.value.nodes.findIndex(n => n.id === updated.id)
  if (idx !== -1) {
    nodesData.value.nodes[idx] = updated
  }
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
      <CreateNodeDialog @created="fetchNodes" />
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <Spinner />
    </div>

    <div v-else-if="nodesData.nodes.length === 0" class="text-center text-muted-foreground py-12">
      No nodes found. Click "Add Node" to get started.
    </div>

    <NodeTable v-else :nodes="nodesData.nodes" @deploy="(node) => deploySheet?.start(node)" @updated="onNodeUpdated" />

    <DeploySheet ref="deploySheet" />
  </div>
</template>
