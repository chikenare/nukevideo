<script setup lang="ts">
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
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
import { EllipsisVertical, Pencil, Wrench, Trash2 } from '@lucide/vue'
import SetupDialog from './SetupDialog.vue'
import EditNodeDialog from './EditNodeDialog.vue'

const props = defineProps<{ nodes: Node[] }>()
const emit = defineEmits<{ updated: [node: Node]; deleted: [nodeId: number] }>()

const setupDialog = ref<InstanceType<typeof SetupDialog> | null>(null)
const editDialog = ref<InstanceType<typeof EditNodeDialog> | null>(null)

const deleteNode = async (node: Node) => {
  if (!confirm(`Are you sure you want to delete node "${node.name}"?`)) return
  try {
    await NodeService.deleteNode(node.id)
    emit('deleted', node.id)
  } catch (error) {
    console.error('Error deleting node:', error)
  }
}

</script>

<template>
  <div class="overflow-hidden rounded-lg border">
    <Table>
      <TableHeader class="bg-muted">
        <TableRow>
          <TableHead>Node</TableHead>
          <TableHead class="text-right">Last Seen</TableHead>
          <TableHead class="w-12"></TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-for="node in props.nodes" :key="node.id">
          <TableCell>
            <div class="flex items-center gap-2">
              <span class="inline-block h-2 w-2 shrink-0 rounded-full"
                :class="node.isActive ? 'bg-emerald-500' : 'bg-muted-foreground/40'"
                :title="node.isActive ? 'Active' : 'Inactive'" />
              <div class="flex flex-col">
                <span class="font-medium">{{ node.name }}</span>
                <span class="text-xs text-muted-foreground">{{ node.ipAddress }}</span>
              </div>
            </div>
          </TableCell>


          <TableCell class="text-right text-xs text-muted-foreground">
            {{ node.lastSeenAt || '-' }}
          </TableCell>

          <TableCell>
            <DropdownMenu>
              <DropdownMenuTrigger as-child>
                <Button variant="ghost" size="icon">
                  <EllipsisVertical class="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem @click="editDialog?.show(node)">
                  <Pencil class="mr-2 h-4 w-4" />
                  Edit
                </DropdownMenuItem>
                <DropdownMenuItem @click="setupDialog?.show(node)">
                  <Wrench class="mr-2 h-4 w-4" />
                  Setup
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem class="text-destructive" @click="deleteNode(node)">
                  <Trash2 class="mr-2 h-4 w-4" />
                  Delete
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
    <SetupDialog ref="setupDialog" @node-updated="(node) => emit('updated', node)" />
    <EditNodeDialog ref="editDialog" @updated="(node) => emit('updated', node)" />
  </div>
</template>
