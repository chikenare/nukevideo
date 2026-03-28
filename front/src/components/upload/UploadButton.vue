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
import { onMounted, ref } from 'vue'
import type { Template } from '@/types/Template'
import TemplateService from '@/services/TemplateService'
import type { AcceptableValue } from 'reka-ui'

const uploadStore = useUploadStore()
const { files, selectedTemplate, isUploading } = storeToRefs(uploadStore)

const templates = ref<Template[]>([])
const dialogOpen = ref(false)

const handleUpload = () => {
  uploadStore.startUpload()
  dialogOpen.value = false
}

const handleTemplateChange = (value: AcceptableValue) => {
  if (typeof value === 'string') {
    uploadStore.setTemplate(value)
  }
}

const getTemplates = async () => {
  templates.value = await TemplateService.index()
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
        <Select v-if="!isUploading" v-model="selectedTemplate" @update:model-value="handleTemplateChange">
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

      <DialogFooter v-if="!isUploading" class="mt-4">
        <Button type="button" @click="handleUpload">
          Upload
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
