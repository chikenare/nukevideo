<script setup lang="ts">
import {
  Dialog,
  DialogClose,
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
import { Progress } from '@/components/ui/progress'

import { Button } from '@/components/ui/button'
import UploadFiles from './UploadFiles.vue'
import { useUploadStore } from '@/stores/upload'
import { storeToRefs } from 'pinia'
import { onMounted, ref, watch, computed } from 'vue'
import type { Template } from '@/types/Template'
import TemplateService from '@/services/TemplateService'
import type { AcceptableValue } from 'reka-ui'
import { ChevronUp, ChevronDown, XIcon } from 'lucide-vue-next'

const uploadStore = useUploadStore()
const { files, selectedTemplate, isUploading } = storeToRefs(uploadStore)

const templates = ref<Template[]>([])
const isMinimized = ref(false)
const dialogOpen = ref(false)

// Calculate overall progress
const overallProgress = computed(() => {
  if (!files.value.length) return 0
  const totalProgress = files.value.reduce((sum, file) => sum + file.progress, 0)
  return Math.round(totalProgress / files.value.length)
})

// Count uploading files
const uploadingFiles = computed(() => {
  return files.value.filter(f => f.progress > 0 && f.progress < 100).length
})

const handleUpload = () => {
  uploadStore.startUpload()
  // Minimize when upload starts with slight delay for smooth animation
  setTimeout(() => {
    isMinimized.value = true
  }, 100)
}

const handleTemplateChange = (value: AcceptableValue) => {
  if (typeof value === 'string') {
    uploadStore.setTemplate(value)
  }
}

const getTemplates = async () => {
  templates.value = await TemplateService.index()
}

const toggleMinimize = () => {
  isMinimized.value = !isMinimized.value
}

// Reset minimize state when dialog closes
watch(dialogOpen, (newValue) => {
  if (!newValue) {
    isMinimized.value = false
  }
})

onMounted(getTemplates)

</script>

<template>
  <Dialog v-model:open="dialogOpen">
    <DialogTrigger as-child>
      <Button variant="outline">
        Upload Video
      </Button>
    </DialogTrigger>
    <DialogContent :show-close-button="false" :class="[
      isMinimized
        ? 'fixed bottom-4 right-4 w-95 sm:max-w-95 top-auto left-auto translate-x-0 translate-y-0 shadow-2xl'
        : 'sm:max-w-106.25'
    ]">
      <DialogHeader>
        <div class="flex items-center justify-between gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <DialogTitle class="text-base flex items-center justify-between w-full ">
                <div>
                  Uploads
                  <span v-if="!isMinimized && isUploading" class="text-sm font-semibold text-blue-600 animate-pulse">
                    {{ overallProgress }}%
                  </span>
                </div>
                <div>
                  <Button v-if="isUploading" variant="ghost" size="icon" @click.stop="toggleMinimize" class="shrink-0">
                    <ChevronDown v-if="!isMinimized" class="h-4 w-4" />
                    <ChevronUp v-else class="h-4 w-4" />
                  </Button>
                  <DialogClose v-show="!isMinimized" as-child>
                    <Button type="button" variant="ghost">
                      <XIcon />
                    </Button>
                  </DialogClose>
                </div>
              </DialogTitle>
            </div>
            <div v-if="isUploading && isMinimized" class="text-xs text-gray-500 mt-1">
              {{ uploadingFiles }} file{{ uploadingFiles !== 1 ? 's' : '' }} uploading...
            </div>
          </div>
        </div>

        <!-- Progress bar in minimized state -->
        <Progress v-if="isMinimized && isUploading" :model-value="overallProgress" />
      </DialogHeader>

      <div v-show="!isMinimized">
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
      </div>
    </DialogContent>
  </Dialog>
</template>
