<script setup lang="ts">
import { ref } from 'vue'
import { Input } from '@/components/ui/input'
import { CheckCircle2, XCircle, SquarePen, Pause, Play, X } from 'lucide-vue-next'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useUploadStore } from '@/stores/upload'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Progress } from '@/components/ui/progress'
import type { FileUpload } from '@/types/FileUpload'
import { generateThumbnail } from '@/utils/videoThumbnail'

const files = defineModel<FileUpload[]>({ default: [] })

const uploadStore = useUploadStore()

const fileInputRef = ref<HTMLInputElement | null>(null)
const editingFile = ref<FileUpload | null>(null)
const editDialogOpen = ref(false)

const handleClick = () => {
  fileInputRef.value?.click()
}

const handleFileChange = async (event: Event) => {
  const target = event.target as HTMLInputElement
  const selectedFiles = target.files

  for (const file of Array.from(selectedFiles ?? [])) {
    const thumbnail = await generateThumbnail(file)
    files.value.push({
      title: file.name,
      file: file,
      thumbnail: thumbnail,
      progress: 0,
      status: 'pending'
    })
  }
}

const openEditDialog = (file: FileUpload) => {
  editingFile.value = file
  editDialogOpen.value = true
}

const saveEdit = () => {
  editDialogOpen.value = false
  editingFile.value = null
}

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
  <div v-if="!files?.length">
    <input ref="fileInputRef" type="file" accept=".mp4,.mkv" multiple class="hidden" @change="handleFileChange" />
    <div class="h-50 cursor-pointer rounded-lg bg-secondary flex place-items-center justify-center"
      @click="handleClick">
      <span class="underline text-sm">Upload from local file</span>
    </div>
  </div>
  <div v-else class="flex flex-col gap-3 max-h-100 overflow-y-auto">
    <div v-for="(file, i) in files" :key="i" class="flex flex-col gap-2">
      <div class="flex gap-3 items-center">
        <div class="relative">
          <img v-if="file.thumbnail" :src="file.thumbnail" alt="Video preview"
            class="w-20 aspect-video object-cover rounded" />
        </div>
        <div class="flex-1 flex flex-col gap-1">
          <div class="flex items-center gap-2">
            <span class="text-sm">{{ file.title }}</span>
            <!-- Status badge -->
            <Badge v-if="file.status === 'success'" variant="default" class="bg-green-500">
              <CheckCircle2 class="w-3 h-3 mr-1" />
              Success
            </Badge>
            <Badge v-else-if="file.status === 'error'" variant="destructive">
              <XCircle class="w-3 h-3 mr-1" />
              Error
            </Badge>
            <Badge v-else-if="file.status === 'paused'" variant="secondary">
              <Pause class="w-3 h-3 mr-1" />
              Paused
            </Badge>
          </div>
          <!-- Progress bar -->
          <div v-if="file.progress > 0" class="w-full">
            <Progress :model-value="file.progress" />
          </div>
          <!-- Error message -->
          <p v-if="file.logError" class="text-xs text-red-500">{{ file.logError }}</p>
        </div>
        <!-- Control buttons -->
        <div class="flex gap-1">
          <!-- Pause/Resume button -->
          <Button v-if="file.progress > 0 && file.progress < 100 && file.status !== 'error'" variant="ghost" size="icon"
            @click="handlePauseResume(i)">
            <Pause v-if="file.status !== 'paused'" class="w-4 h-4" />
            <Play v-else class="w-4 h-4" />
          </Button>
          <!-- Cancel button -->
          <Button v-if="file.progress > 0 && file.progress < 100 && file.status !== 'error'" variant="ghost" size="icon"
            @click="handleCancel(i)">
            <X class="w-4 h-4" />
          </Button>
          <!-- Edit button -->
          <Button v-if="file.status == 'pending'" variant="ghost" size="icon" @click="openEditDialog(file)">
            <SquarePen class="w-4 h-4" />
          </Button>
        </div>
      </div>
    </div>
  </div>

  <Dialog v-model:open="editDialogOpen">
    <DialogContent class="sm:max-w-106.25">
      <DialogHeader>
        <DialogTitle>Edit Video Details</DialogTitle>
        <DialogDescription>
          Update the creator ID and external ID for this video.
        </DialogDescription>
      </DialogHeader>
      <div v-if="editingFile" class="grid gap-4 py-4">
        <div class="grid gap-2">
          <label for="title" class="text-sm font-medium">Title</label>
          <Input id="title" v-model="editingFile.title" placeholder="Enter title" />
        </div>
        <div class="grid gap-2">
          <label for="creatorId" class="text-sm font-medium">Creator ID</label>
          <Input id="creatorId" v-model="editingFile.creatorId" placeholder="Enter creator ID" />
        </div>
        <div class="grid gap-2">
          <label for="externalId" class="text-sm font-medium">External ID</label>
          <Input id="externalId" v-model="editingFile.externalId" placeholder="Enter external ID" />
        </div>
      </div>
      <DialogFooter>
        <Button type="button" @click="saveEdit">Save changes</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
