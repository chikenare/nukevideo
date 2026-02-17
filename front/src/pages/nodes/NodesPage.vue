<script setup lang="ts">
import Badge from '@/components/ui/badge/Badge.vue';
import Spinner from '@/components/ui/spinner/Spinner.vue';
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { ref, onMounted } from 'vue';
import { Edit, MoreVertical, Rocket, Trash2 } from 'lucide-vue-next'
import NodeService from '@/services/NodeService';
import type { Node, NodesResponse } from '@/types/Node';
import CreateNodeDialog from './components/CreateNodeDialog.vue';
import EditNodeDialog from './components/EditNodeDialog.vue';

const nodesData = ref<NodesResponse>({ nodes: [], summary: { totalCapacity: 0, availableSlots: 0 } });
const loading = ref(true);
const editingNode = ref<Node | null>(null);

const fetchNodes = async () => {
  try {
    loading.value = true;
    nodesData.value = await NodeService.getNodes();
  } catch (error) {
    console.error('Error fetching nodes:', error);
  } finally {
    loading.value = false;
  }
};

const handleEdit = (node: Node) => {
  editingNode.value = { ...node };
};

const handleDeploy = async (node: Node) => {
  try {
    const result = await NodeService.deployNode(node.id);
    if (result.success) {
      fetchNodes();
    }
  } catch (error) {
    console.error('Deploy failed:', error);
  }
};

const handleDelete = async (node: Node) => {
  try {
    await NodeService.deleteNode(node.id);
    fetchNodes();
  } catch (error) {
    console.error('Error deleting node:', error);
  }
};

const getTypeVariant = (type: string) => {
  switch (type) {
    case 'worker': return 'default';
    case 'proxy': return 'secondary';
    default: return 'default';
  }
};

const formatLocation = (node: Node): string => {
  const parts = [node.city, node.country].filter(Boolean);
  return parts.length > 0 ? parts.join(', ') : '-';
};

onMounted(() => {
  fetchNodes();
});
</script>

<template>
  <div class="flex flex-col gap-4 p-4">
    <!-- Header -->
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold">Nodes</h1>
        <p class="text-muted-foreground">Manage your processing nodes</p>
      </div>
      <CreateNodeDialog @created="fetchNodes" />
    </div>

    <!-- Summary -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="p-4 border rounded-lg bg-card">
        <h3 class="text-sm font-medium text-muted-foreground">Total Capacity</h3>
        <p class="text-2xl font-bold mt-1">{{ nodesData.summary.totalCapacity }}</p>
      </div>
      <div class="p-4 border rounded-lg bg-card">
        <h3 class="text-sm font-medium text-muted-foreground">Available Slots</h3>
        <p class="text-2xl font-bold mt-1">{{ nodesData.summary.availableSlots }}</p>
      </div>
    </div>

    <!-- Table -->
    <div class="overflow-hidden rounded-lg border">
      <Table>
        <TableHeader class="bg-muted sticky top-0 z-10">
          <TableRow>
            <TableHead>Name</TableHead>
            <TableHead>Host</TableHead>
            <TableHead>Type</TableHead>
            <TableHead>Location</TableHead>
            <TableHead>Status</TableHead>
            <TableHead>Max Workers</TableHead>
            <TableHead>Last Seen</TableHead>
            <TableHead class="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-if="loading">
            <TableCell colspan="8" class="text-center">
              <Spinner />
              Loading nodes...
            </TableCell>
          </TableRow>
          <TableRow v-else-if="nodesData.nodes.length === 0">
            <TableCell colspan="8" class="text-center text-muted-foreground py-8">
              <div class="flex flex-col items-center gap-2">
                <p>No nodes found</p>
                <CreateNodeDialog @created="fetchNodes" />
              </div>
            </TableCell>
          </TableRow>
          <TableRow v-else v-for="node in nodesData.nodes" :key="node.id">
            <TableCell class="font-medium">{{ node.name }}</TableCell>
            <TableCell>{{ node.host || '-' }}</TableCell>
            <TableCell>
              <Badge :variant="getTypeVariant(node.type)">
                {{ node.type }}
              </Badge>
            </TableCell>
            <TableCell>{{ formatLocation(node) }}</TableCell>
            <TableCell>
              <Badge :variant="node.isActive ? 'default' : 'outline'">
                {{ node.isActive ? 'Active' : 'Inactive' }}
              </Badge>
            </TableCell>
            <TableCell>{{ node.maxWorkers }}</TableCell>
            <TableCell>{{ node.lastSeenAt || '-' }}</TableCell>
            <TableCell class="text-right">
              <DropdownMenu>
                <DropdownMenuTrigger as-child>
                  <Button variant="ghost" size="icon">
                    <MoreVertical :size="16" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem @click="handleEdit(node)">
                    <Edit :size="16" class="mr-2" />
                    Edit
                  </DropdownMenuItem>
                  <DropdownMenuItem @click="handleDeploy(node)">
                    <Rocket :size="16" class="mr-2" />
                    Deploy
                  </DropdownMenuItem>
                  <DropdownMenuItem class="text-destructive" @click="handleDelete(node)">
                    <Trash2 :size="16" class="mr-2" />
                    Delete
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <!-- Edit Dialog -->
    <EditNodeDialog :node="editingNode" @update:node="editingNode = $event" @updated="fetchNodes" />
  </div>
</template>
