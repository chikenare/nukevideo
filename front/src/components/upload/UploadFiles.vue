<script setup lang="ts">
import { ref } from 'vue'
import { Input } from '@/components/ui/input'
import { CheckCircle2, XCircle, SquarePen, Pause, Play, X, Trash2, Plus } from '@lucide/vue'
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

const files = defineModel<FileUpload[]>({ default: () => [] })

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

  // Reset so re-selecting the same file (e.g. after removing it) still fires @change.
  target.value = ''
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

const handleRemove = (index: number) => {
  uploadStore.removeFile(index)
}

// Still waiting in the queue: not yet handed to Uppy, so it's safe to edit or drop.
const isQueued = (file: FileUpload) => file.status === 'pending' && file.progress === 0 && !file.uppyFileId

// Mid-transfer (or paused mid-transfer): only pause/cancel apply, never a plain remove.
const isTransferring = (file: FileUpload) => file.progress > 0 && file.progress < 100 && file.status !== 'error'

// Removable when it's done, failed, or still queued — for in-flight files use cancel instead.
const canRemove = (file: FileUpload) => file.status === 'success' || file.status === 'error' || isQueued(file)
</script>
<template>
  <input ref="fileInputRef" type="file" accept=".mp4,.mkv" multiple class="hidden" @change="handleFileChange" />

  <!-- Empty state: full dropzone -->
  <div v-if="!files?.length">
    <div class="h-50 cursor-pointer rounded-lg bg-secondary flex place-items-center justify-center"
      @click="handleClick">
      <span class="underline text-sm">Upload from local file</span>
    </div>
  </div>

  <!-- File list -->
  <div v-else class="flex flex-col gap-3">
    <div class="flex flex-col gap-3 max-h-100 overflow-y-auto">
      <div v-for="(file, i) in files" :key="i" class="flex flex-col gap-2">
        <div class="flex gap-3 items-center">
          <div class="relative">
            <img v-if="file.thumbnail" :src="file.thumbnail" alt="Video preview"
              class="w-20 aspect-video object-cover rounded" />
          </div>
          <div class="flex-1 flex flex-col gap-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="text-sm truncate">{{ file.title }}</span>
              <!-- Status badge -->
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
            <!-- Progress bar -->
            <div v-if="file.progress > 0" class="w-full">
              <Progress :model-value="file.progress" />
            </div>
            <!-- Error message -->
            <p v-if="file.logError" class="text-xs text-red-500">{{ file.logError }}</p>
          </div>
          <!-- Control buttons -->
          <div class="flex gap-1 shrink-0">
            <!-- Pause/Resume button -->
            <Button v-if="isTransferring(file)" variant="ghost" size="icon" @click="handlePauseResume(i)">
              <Pause v-if="file.status !== 'paused'" class="w-4 h-4" />
              <Play v-else class="w-4 h-4" />
            </Button>
            <!-- Cancel button -->
            <Button v-if="isTransferring(file)" variant="ghost" size="icon" @click="handleCancel(i)">
              <X class="w-4 h-4" />
            </Button>
            <!-- Edit button -->
            <Button v-if="isQueued(file)" variant="ghost" size="icon" @click="openEditDialog(file)">
              <SquarePen class="w-4 h-4" />
            </Button>
            <!-- Remove button (done / failed / queued) -->
            <Button v-if="canRemove(file)" variant="ghost" size="icon" @click="handleRemove(i)">
              <Trash2 class="w-4 h-4 text-destructive" />
            </Button>
          </div>
        </div>
      </div>
    </div>

    <!-- Add more files (available even while an upload is in progress) -->
    <Button variant="outline" size="sm" class="self-start border-dashed" @click="handleClick">
      <Plus class="w-4 h-4 mr-1" />
      Add more files
    </Button>
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
          <label for="externalUserId" class="text-sm font-medium">External User ID</label>
          <Input id="externalUserId" v-model="editingFile.externalUserId" placeholder="Enter external user ID" />
        </div>
        <div class="grid gap-2">
          <label for="externalResourceId" class="text-sm font-medium">External Resource ID</label>
          <Input id="externalResourceId" v-model="editingFile.externalResourceId" placeholder="Enter external resource ID" />
        </div>
      </div>
      <DialogFooter>
        <Button type="button" @click="saveEdit">Save changes</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
