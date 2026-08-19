<script setup lang="ts">
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

import { Button } from '@/components/ui/button'
import UploadFiles from './UploadFiles.vue'
import { useUploadStore } from '@/stores/upload'
import { storeToRefs } from 'pinia'
import { computed, onMounted, ref } from 'vue'
type Template = App.Data.TemplateData
import TemplateService from '@/services/TemplateService'
import type { AcceptableValue } from 'reka-ui'

const uploadStore = useUploadStore()
const { files, selectedTemplate, isUploading } = storeToRefs(uploadStore)

const templates = ref<Template[]>([])
const dialogOpen = ref(false)

// Files queued but not yet handed to Uppy — these are what a "Upload" click will start,
// so both the template picker and the button stay available even mid-upload.
const pendingCount = computed(() =>
  files.value.filter(f => f.status === 'pending' && f.progress === 0 && !f.uppyFileId).length
)

const handleUpload = () => {
  uploadStore.startUpload()
}

const handleTemplateChange = (value: AcceptableValue) => {
  if (typeof value === 'string') {
    uploadStore.setTemplate(value)
  }
}

// Retired templates are refused by the upload endpoints, so offering one here would only produce a
// 422 at the worst possible moment. Filtered by the API, not here: the panel is not the only client.
const getTemplates = async () => {
  templates.value = await TemplateService.index({ enabled: true })
}

onMounted(getTemplates)
</script>

<template>
  <Dialog v-model:open="dialogOpen">
    <DialogTrigger as-child>
      <Button variant="outline">
        Upload Video
      </Button>
    </DialogTrigger>
    <DialogContent :show-close-button="true" class="sm:max-w-106.25">
      <DialogHeader>
        <DialogTitle>Uploads</DialogTitle>
      </DialogHeader>

      <UploadFiles v-model="files" />

      <div class="mt-5">
        <Select v-if="pendingCount > 0" v-model="selectedTemplate" @update:model-value="handleTemplateChange">
          <SelectTrigger>
            <SelectValue placeholder="Select template" />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup>
              <SelectLabel>Template</SelectLabel>
              <SelectItem v-for="template in templates" :key="template.ulid" :value="template.ulid">
                {{ template.name }}
              </SelectItem>
            </SelectGroup>
          </SelectContent>
        </Select>
      </div>

      <DialogFooter v-if="pendingCount > 0" class="mt-4 sm:items-center">
        <!-- The template is required server-side. Blocking the click here is the difference
             between a hint and every file flipping to "Error" after the request. -->
        <p v-if="!selectedTemplate" class="text-xs text-muted-foreground mr-auto">
          Select a template to start uploading.
        </p>
        <Button type="button" :disabled="!selectedTemplate" @click="handleUpload">
          {{ isUploading ? 'Upload more' : 'Upload' }} ({{ pendingCount }})
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
