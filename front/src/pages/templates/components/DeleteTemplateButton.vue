<script setup lang="ts">
import { ref } from 'vue'
import { DropdownMenuItem } from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Trash2 } from 'lucide-vue-next'
import TemplateService from '@/services/TemplateService'
import type { Template } from '@/types/Template'
import { toast } from 'vue-sonner'
import { ApiException } from '@/exceptions/ApiException'

interface Props {
  template: Template
}

const props = defineProps<Props>()
const emit = defineEmits<{
  deleted: []
}>()

const showDeleteDialog = ref(false)
const isDeleting = ref(false)

const handleDeleteClick = () => {
  showDeleteDialog.value = true
}

const handleConfirmDelete = async () => {
  try {
    isDeleting.value = true
    const res = await TemplateService.destroy(props.template.ulid)
    toast.success(res.data.message)
    showDeleteDialog.value = false
    emit('deleted')
  } catch (error) {
    if (error instanceof ApiException) {
      toast.error(error.message)
    }
    console.error('Error deleting template:', error)
  } finally {
    isDeleting.value = false
  }
}
</script>

<template>
  <div>
    <DropdownMenuItem @select.prevent="handleDeleteClick" class="text-destructive focus:text-destructive">
      <Trash2 :size="16" class="mr-2" />
      Delete
    </DropdownMenuItem>

    <Dialog v-model:open="showDeleteDialog">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete Template</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete the template "{{ template.name }}"? This action cannot be
            undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="showDeleteDialog = false" :disabled="isDeleting">
            Cancel
          </Button>
          <Button variant="destructive" @click="handleConfirmDelete" :disabled="isDeleting">
            {{ isDeleting ? 'Deleting...' : 'Delete' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
