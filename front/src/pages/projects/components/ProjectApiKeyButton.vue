<script setup lang="ts">
import { computed, ref } from 'vue'
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
import { Copy, KeyRound } from '@lucide/vue'
import ProjectService from '@/services/ProjectService'
import { useProjectsStore } from '@/stores/projects'
type Project = App.Data.ProjectData
import { toast } from 'vue-sonner'
import { ApiException } from '@/exceptions/ApiException'

interface Props {
  project: Project
}

const props = defineProps<Props>()

const projectsStore = useProjectsStore()
const open = ref(false)
const generating = ref(false)
const plainKey = ref<string | null>(null)

const hasKey = computed(() => !!props.project.apiKey)

const handleConfirm = async () => {
  try {
    generating.value = true
    const project = await ProjectService.regenerateApiKey(props.project.ulid)
    projectsStore.upsert(project)
    plainKey.value = project.apiKey?.token ?? null
    toast.success('API key regenerated')
  } catch (error) {
    if (error instanceof ApiException) toast.error(error.message)
  } finally {
    generating.value = false
  }
}

const copyKey = async () => {
  if (!plainKey.value) return
  await navigator.clipboard.writeText(plainKey.value)
  toast.success('API key copied')
}

const close = () => {
  open.value = false
  plainKey.value = null
}
</script>

<template>
  <div>
    <DropdownMenuItem @select.prevent="open = true">
      <KeyRound :size="16" class="mr-2" />
      {{ hasKey ? 'Regenerate API key' : 'Generate API key' }}
    </DropdownMenuItem>

    <Dialog :open="open" @update:open="(v: boolean) => { if (!v) close() }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{{ hasKey ? 'Regenerate API key' : 'Generate API key' }}</DialogTitle>
          <DialogDescription>
            <template v-if="plainKey">
              Copy it now — it won't be shown again.
            </template>
            <template v-else-if="hasKey">
              This revokes the current key of "<strong>{{ project.name }}</strong>". Any integration
              using it will stop working until it's updated with the new one.
            </template>
            <template v-else>
              Creates an API key scoped to "<strong>{{ project.name }}</strong>". Requests
              authenticated with it target this project, so no <code
                class="font-mono">X-Project-Ulid</code> header is needed.
            </template>
          </DialogDescription>
        </DialogHeader>

        <div v-if="plainKey" class="flex items-center gap-2">
          <code class="flex-1 rounded bg-muted px-3 py-2 text-sm font-mono break-all">{{ plainKey }}</code>
          <Button variant="outline" size="icon" @click="copyKey">
            <Copy class="h-4 w-4" />
          </Button>
        </div>

        <DialogFooter>
          <template v-if="plainKey">
            <Button @click="close">Done</Button>
          </template>
          <template v-else>
            <Button variant="outline" :disabled="generating" @click="close">Cancel</Button>
            <Button :disabled="generating" @click="handleConfirm">
              {{ generating ? 'Generating...' : hasKey ? 'Regenerate' : 'Generate' }}
            </Button>
          </template>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
