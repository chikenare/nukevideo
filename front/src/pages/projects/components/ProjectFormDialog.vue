<script setup lang="ts">
import { computed, ref, watch } from 'vue'
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
import ProjectService from '@/services/ProjectService'
import { useProjectsStore } from '@/stores/projects'
import type { Project } from '@/types/Project'
import { toast } from 'vue-sonner'
import { ApiException } from '@/exceptions/ApiException'
import { ValidationException } from '@/exceptions/ValidationException'

interface Props {
  open: boolean
  project?: Project | null
}

const props = defineProps<Props>()
const emit = defineEmits<{
  'update:open': [value: boolean]
  saved: []
}>()

const projectsStore = useProjectsStore()
const name = ref('')
const webhookUrl = ref('')
const webhookSecret = ref('')
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const isEdit = computed(() => !!props.project)

watch(
  () => [props.open, props.project],
  () => {
    if (props.open) {
      name.value = props.project?.name ?? ''
      webhookUrl.value = props.project?.settings?.webhookUrl ?? ''
      webhookSecret.value = props.project?.settings?.webhookSecret ?? ''
      errors.value = {}
    }
  },
  { immediate: true }
)

const buildPayload = () => ({
  name: name.value,
  settings: {
    webhookUrl: webhookUrl.value.trim() || null,
    webhookSecret: webhookSecret.value.trim() || null,
  },
})

const handleSubmit = async () => {
  try {
    saving.value = true
    errors.value = {}

    if (isEdit.value) {
      const res = await ProjectService.update(props.project!.ulid, buildPayload())
      projectsStore.upsert(res.data.data)
      toast.success('Project updated')
    } else {
      const project = await ProjectService.store(buildPayload())
      projectsStore.upsert(project)
      projectsStore.setCurrent(project.ulid)
      toast.success('Project created')
    }

    emit('saved')
    emit('update:open', false)
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    } else if (error instanceof ApiException) {
      toast.error(error.message)
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>{{ isEdit ? 'Edit project' : 'New project' }}</DialogTitle>
        <DialogDescription>
          {{ isEdit ? 'Rename this project and configure its settings.' : 'Create a new project to organize videos, templates and API keys.' }}
        </DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="handleSubmit">
        <div class="flex flex-col gap-2">
          <Label for="project-name">Name</Label>
          <Input id="project-name" v-model="name" autofocus :disabled="saving" required />
          <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name[0] }}</p>
        </div>

        <div class="flex flex-col gap-2">
          <Label for="project-webhook-url">Webhook URL</Label>
          <Input
            id="project-webhook-url"
            v-model="webhookUrl"
            type="url"
            placeholder="https://example.com/webhook"
            :disabled="saving"
          />
          <p class="text-xs text-muted-foreground">
            Called when videos in this project finish processing. Leave empty to disable.
          </p>
          <p v-if="errors['settings.webhookUrl']" class="text-sm text-destructive">
            {{ errors['settings.webhookUrl'][0] }}
          </p>
        </div>

        <div v-if="webhookUrl.trim()" class="flex flex-col gap-2">
          <Label for="project-webhook-secret">Webhook secret</Label>
          <Input
            id="project-webhook-secret"
            v-model="webhookSecret"
            type="text"
            placeholder="Optional"
            :disabled="saving"
          />
          <p class="text-xs text-muted-foreground">
            Sent as <code class="font-mono">Authorization: Bearer &lt;secret&gt;</code> when calling your webhook. Leave empty to skip authorization.
          </p>
          <p v-if="errors['settings.webhookSecret']" class="text-sm text-destructive">
            {{ errors['settings.webhookSecret'][0] }}
          </p>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" :disabled="saving" @click="emit('update:open', false)">
            Cancel
          </Button>
          <Button type="submit" :disabled="saving || !name.trim()">
            {{ saving ? 'Saving...' : isEdit ? 'Save' : 'Create' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
