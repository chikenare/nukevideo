<script setup lang="ts">
import { ref, computed } from 'vue'
import { useUploadStore } from '@/stores/upload'
import { storeToRefs } from 'pinia'
import { Progress } from '@/components/ui/progress'
import { Button } from '@/components/ui/button'
import { CheckCircle2, XCircle, Pause, Play, X, ChevronUp, ChevronDown } from 'lucide-vue-next'
import { Badge } from '@/components/ui/badge'

const uploadStore = useUploadStore()
const { files, isUploading } = storeToRefs(uploadStore)

const isMinimized = ref(false)

const overallProgress = computed(() => {
  if (!files.value.length) return 0
  const totalProgress = files.value.reduce((sum, file) => sum + file.progress, 0)
  return Math.round(totalProgress / files.value.length)
})

const uploadingFiles = computed(() => {
  return files.value.filter(f => f.progress > 0 && f.progress < 100).length
})

const handlePauseResume = (index: number) => {
  const file = files.value[index]
  if (!file) return
  if (file.status === 'paused') {
    uploadStore.resumeUpload(index)
  } else {
    uploadStore.pauseUpload(index)
  }
}

const handleCancel = (index: number) => {
  uploadStore.cancelUpload(index)
}
</script>

<template>
  <div v-if="isUploading" class="fixed bottom-4 right-4 z-50 w-95 bg-background border rounded-lg shadow-2xl">
    <!-- Header -->
    <div class="flex items-center justify-between p-4 pb-2">
      <span class="text-sm font-medium">
        Uploads
        <span class="text-sm font-semibold text-blue-600 animate-pulse ml-1">{{ overallProgress }}%</span>
      </span>
      <Button variant="ghost" size="icon" class="h-7 w-7" @click="isMinimized = !isMinimized">
        <ChevronDown v-if="!isMinimized" class="h-4 w-4" />
        <ChevronUp v-else class="h-4 w-4" />
      </Button>
    </div>

    <!-- Minimized -->
    <div v-if="isMinimized" class="px-4 pb-4">
      <div class="text-xs text-muted-foreground mb-2">
        {{ uploadingFiles }} file{{ uploadingFiles !== 1 ? 's' : '' }} uploading...
      </div>
      <Progress :model-value="overallProgress" />
    </div>

    <!-- Expanded: full file list -->
    <div v-else class="px-4 pb-4 flex flex-col gap-3 max-h-80 overflow-y-auto">
      <div v-for="(file, i) in files" :key="i" class="flex gap-3 items-center">
        <div class="relative">
          <img v-if="file.thumbnail" :src="file.thumbnail" alt="Video preview"
            class="w-16 aspect-video object-cover rounded" />
        </div>
        <div class="flex-1 flex flex-col gap-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-xs truncate">{{ file.title }}</span>
            <Badge v-if="file.status === 'success'" variant="default" class="bg-green-500 shrink-0">
              <CheckCircle2 class="w-3 h-3 mr-1" />
              Success
            </Badge>
            <Badge v-else-if="file.status === 'error'" variant="destructive" class="shrink-0">
              <XCircle class="w-3 h-3 mr-1" />
              Error
            </Badge>
            <Badge v-else-if="file.status === 'paused'" variant="secondary" class="shrink-0">
              <Pause class="w-3 h-3 mr-1" />
              Paused
            </Badge>
          </div>
          <Progress v-if="file.progress > 0" :model-value="file.progress" class="h-1.5" />
          <p v-if="file.logError" class="text-xs text-red-500 truncate">{{ file.logError }}</p>
        </div>
        <div class="flex gap-0.5 shrink-0">
          <Button v-if="file.progress > 0 && file.progress < 100 && file.status !== 'error'" variant="ghost"
            size="icon" class="h-7 w-7" @click="handlePauseResume(i)">
            <Pause v-if="file.status !== 'paused'" class="w-3.5 h-3.5" />
            <Play v-else class="w-3.5 h-3.5" />
          </Button>
          <Button v-if="file.progress > 0 && file.progress < 100 && file.status !== 'error'" variant="ghost"
            size="icon" class="h-7 w-7" @click="handleCancel(i)">
            <X class="w-3.5 h-3.5" />
          </Button>
        </div>
      </div>
    </div>
  </div>
</template>
