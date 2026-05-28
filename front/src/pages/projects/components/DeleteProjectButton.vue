<script setup lang="ts">
import { computed, ref, watch } from 'vue'
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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Trash2 } from 'lucide-vue-next'
import ProjectService from '@/services/ProjectService'
import { useProjectsStore } from '@/stores/projects'
import type { Project } from '@/types/Project'
import { toast } from 'vue-sonner'
import { ApiException } from '@/exceptions/ApiException'

interface Props {
  project: Project
}

const props = defineProps<Props>()
const emit = defineEmits<{
  deleted: []
}>()

const CONFIRM_WORD = 'delete'
const projectsStore = useProjectsStore()
const open = ref(false)
const deleting = ref(false)
const confirmText = ref('')

const canDelete = computed(() => confirmText.value === CONFIRM_WORD)

watch(open, (value) => {
  if (value) confirmText.value = ''
})

const handleConfirm = async () => {
  try {
    deleting.value = true
    await ProjectService.destroy(props.project.ulid)
    projectsStore.remove(props.project.ulid)
    toast.success('Project deleted')
    open.value = false
    emit('deleted')
  } catch (error) {
    if (error instanceof ApiException) {
      toast.error(error.message)
    }
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div>
    <DropdownMenuItem @select.prevent="open = true" class="text-destructive focus:text-destructive">
      <Trash2 :size="16" class="mr-2" />
      Delete
    </DropdownMenuItem>

    <Dialog v-model:open="open">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete project</DialogTitle>
          <DialogDescription>
            This will permanently delete "<strong>{{ project.name }}</strong>" along with all its
            videos, templates and API keys. This action cannot be undone.
          </DialogDescription>
        </DialogHeader>

        <div class="flex flex-col gap-2">
          <Label for="confirm-delete">
            Type <code class="font-mono text-foreground">{{ CONFIRM_WORD }}</code> to confirm
          </Label>
          <Input
            id="confirm-delete"
            v-model="confirmText"
            :disabled="deleting"
            :placeholder="CONFIRM_WORD"
            autocomplete="off"
          />
        </div>

        <DialogFooter>
          <Button variant="outline" :disabled="deleting" @click="open = false">Cancel</Button>
          <Button variant="destructive" :disabled="deleting || !canDelete" @click="handleConfirm">
            {{ deleting ? 'Deleting...' : 'Delete project' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
