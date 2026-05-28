<script setup lang="ts">
import Badge from '@/components/ui/badge/Badge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import TableCell from '@/components/ui/table/TableCell.vue';
import TableRow from '@/components/ui/table/TableRow.vue';
import { ApiException } from '@/exceptions/ApiException';
import StreamService from '@/services/StreamService';
import type { Stream } from '@/types/Video';
import { Edit, MoreVertical, Trash2 } from 'lucide-vue-next';
import prettyBytes from 'pretty-bytes';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

const { stream } = defineProps<{ stream: Stream }>()

const emit = defineEmits(['onDeleted', 'onUpdated'])

const isEditDialogOpen = ref(false)
const editName = ref('')

const formatDate = (dateString: string | null): string => {
  if (!dateString) return 'N/A'
  return new Date(dateString).toLocaleString()
}

const handleEdit = () => {
  editName.value = stream.name ?? ''
  isEditDialogOpen.value = true
}

const handleUpdate = async () => {
  try {
    const res = await StreamService.update(stream.ulid, { name: editName.value })
    toast.success(res.data.message)

    isEditDialogOpen.value = false
    emit('onUpdated')
  } catch (e) {
    if (e instanceof ApiException) {
      toast.error(e.message)
    }
    console.error(e)
  }
}

const handleDelete = async (stream: Stream) => {
  if (!confirm(`Delete stream ${stream.name}`)) return

  try {
    const res = await StreamService.destroy(stream.ulid)
    toast.info(res.data.message)

    emit('onDeleted')
  } catch (e) {
    if (e instanceof ApiException) {
      toast.error(e.message)
    }
    console.error(e)
  }
}
const getStreamName = (): string => {
  if (stream.type == 'video') {
    return `${stream.height ? stream.height : '---'}`
  }
  return `${stream.name} ${stream.language ? `(${stream.language})` : ''}`.trim()
}
</script>

<template>
  <TableRow :key="stream.id" :class="{ 'bg-destructive/5': stream.errorLog }">
    <TableCell class="font-medium">
      <div class="space-y-1">
        <div>{{ getStreamName() }}</div>
        <!-- <div class="text-xs text-muted-foreground">{{ stream.ulid }}</div> -->
      </div>
    </TableCell>
    <TableCell>
      {{ stream.type.toUpperCase() }}
    </TableCell>
    <TableCell>{{ prettyBytes(stream.size) }}</TableCell>
    <TableCell>
      <div class="flex items-center gap-2">
        <!-- Status Badge -->
        <Badge :class="{ 'bg-green-600 hover:bg-green-700 text-white': stream.status == 'completed' }">
          {{ stream.status }}
          <span v-if="stream.startedAt && !stream.completedAt">{{ stream.progress }}%</span>
        </Badge>
      </div>
    </TableCell>
    <TableCell>
      <span class="text-sm">{{ formatDate(stream.startedAt) }}</span>
    </TableCell>
    <TableCell>
      <span class="text-sm">{{ formatDate(stream.completedAt) }}</span>
    </TableCell>
    <TableCell class="text-right">
      <DropdownMenu>
        <DropdownMenuTrigger as-child>
          <Button variant="ghost" size="icon">
            <MoreVertical :size="16" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem @click="handleEdit">
            <Edit :size="16" class="mr-2" />
            Edit
          </DropdownMenuItem>
          <DropdownMenuItem @click="handleDelete(stream)" class="text-destructive">
            <Trash2 :size="16" class="mr-2" />
            Delete
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </TableCell>
  </TableRow>

  <Dialog v-model:open="isEditDialogOpen">
    <DialogContent class="sm:max-w-106.25">
      <DialogHeader>
        <DialogTitle>Edit Stream Label</DialogTitle>
      </DialogHeader>
      <div class="grid gap-4 py-4">
        <div class="grid grid-cols-4 items-center gap-4">
          <Label for="label" class="text-right">
            Label
          </Label>
          <Input id="label" v-model="editName" class="col-span-3" @keyup.enter="handleUpdate" />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" @click="isEditDialogOpen = false">
          Cancel
        </Button>
        <Button @click="handleUpdate">
          Save changes
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
