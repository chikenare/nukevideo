<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { type Stream, type Video, VideoStatus } from '@/types/Video'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useRoute } from 'vue-router'
import VideoService from '@/services/VideoService'
import prettyBytes from 'pretty-bytes'
import { EditIcon } from 'lucide-vue-next'
import { formatSecondsToTime } from '@/utils/timeFormatter'
import DeleteVideoButton from './components/DeleteVideoButton.vue'
import StreamItem from './components/StreamItem.vue'
import { toast } from 'vue-sonner'
import { ApiException } from '@/exceptions/ApiException'
import VideoPlayer from './components/VideoPlayer.vue'

const route = useRoute()

const video = ref<Video>()
const isEditDialogOpen = ref(false)
const editName = ref('')

const show = async () => {
  video.value = await VideoService.show(route.params.id!.toString())
}

const formatDate = (dateString: string | null): string => {
  if (!dateString) return 'N/A'
  return new Date(dateString).toLocaleString()
}

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline'

const getStatusVariant = (status: VideoStatus): BadgeVariant => {
  const variants: Record<VideoStatus, BadgeVariant> = {
    pending: 'secondary',
    running: 'default',
    completed: 'default',
    failed: 'destructive',
    downloading: 'default',
    uploading: 'default'
  }
  return variants[status] || 'default'
}

function onDeleted(stream: Stream) {
  video.value?.streams.splice(video.value!.streams.indexOf(stream), 1)
}

const handleEdit = () => {
  editName.value = video.value?.name ?? ''
  isEditDialogOpen.value = true
}

const handleUpdate = async () => {
  if (!video.value) return

  try {
    const res = await VideoService.update(video.value.ulid, { name: editName.value })
    toast.success(res.data.message)

    video.value.name = editName.value
    isEditDialogOpen.value = false
  } catch (e) {
    if (e instanceof ApiException) {
      toast.error(e.message)
    }
    console.error(e)
  }
}

const sizeFormatted = computed(() => {
  return prettyBytes(video.value?.size ?? 0, { binary: true })
})

let refreshInterval: number | null = null

onMounted(async () => {
  await show()
  if (video.value && ![VideoStatus.completed, VideoStatus.failed].includes(video.value.status)) {
    refreshInterval = setInterval(() => {
      show()
    }, 5000)
  }
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})

</script>

<template>
  <div v-if="video" class="container mx-auto p-6 space-y-6">
    <!-- Video Section -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Video Player -->
      <VideoPlayer :video="video" />

      <!-- Video Information -->
      <Card class="col-span-2">
        <CardHeader>
          <div class="flex items-start justify-between gap-2">
            <div class="space-y-1 flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <CardTitle class="text-xl font-bold">{{ video.name }}</CardTitle>
                <Button variant="ghost" size="icon" @click="handleEdit" class="h-8 w-8">
                  <EditIcon :size="16" />
                </Button>
              </div>
              <CardDescription class="text-xs break-all">{{ video.ulid }}</CardDescription>
            </div>
            <DeleteVideoButton :id="video.ulid" />
          </div>
        </CardHeader>
        <CardContent class="space-y-4">
          <!-- Video Stats -->
          <div class="space-y-3">
            <div class="flex items-center justify-between py-2 border-b">
              <span class="text-sm text-muted-foreground">Status</span>
              <span class="text-sm font-semibold">
                <Badge :variant="getStatusVariant(video.status)">
                  {{ video.status }}
                </Badge>
              </span>
            </div>
            <div class="flex items-center justify-between py-2 border-b">
              <span class="text-sm text-muted-foreground">Duration</span>
              <span class="text-sm font-semibold">{{ formatSecondsToTime(video.duration) }}</span>
            </div>
            <div class="flex items-center justify-between py-2 border-b">
              <span class="text-sm text-muted-foreground">Size</span>
              <span class="text-sm font-semibold">{{ sizeFormatted }}</span>
            </div>
            <div class="flex items-center justify-between py-2 border-b">
              <span class="text-sm text-muted-foreground">Created</span>
              <span class="text-sm font-semibold">{{ formatDate(video.createdAt) }}</span>
            </div>
            <div v-if="video.creatorId" class="flex items-center justify-between py-2 border-b">
              <span class="text-sm text-muted-foreground">Creator ID</span>
              <span class="text-sm font-semibold truncate ml-2">{{ video.creatorId }}</span>
            </div>
            <div v-if="video.externalId" class="flex items-center justify-between py-2 border-b">
              <span class="text-sm text-muted-foreground">External ID</span>
              <span class="text-sm font-semibold truncate ml-2">{{ video.externalId }}</span>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>

    <!-- Streams Section -->
    <div class="space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold">Streams</h2>
        <Badge variant="outline" class="text-sm">
          {{ video.streams.length }} Total
        </Badge>
      </div>

      <!-- Streams Table -->
      <Card v-if="video.streams.length > 0">
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Label</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Size</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Started</TableHead>
                <TableHead>Completed</TableHead>
                <TableHead class="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <StreamItem v-for="stream in video.streams" :stream="stream" :key="stream.id"
                @on-deleted="() => onDeleted(stream)" />
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <!-- Empty State -->
      <Card v-else class="border-dashed">
        <CardContent class="flex flex-col items-center justify-center py-12">
          <p class="text-muted-foreground text-lg">No streams available</p>
        </CardContent>
      </Card>

      <!-- Error Logs Section -->
      <div v-if="video.streams.some(s => s.errorLog)" class="mt-6 space-y-3">
        <h3 class="text-lg font-semibold">Error Logs</h3>
        <Card v-for="stream in video.streams.filter(s => s.errorLog)" :key="`error-${stream.id}`"
          class="border-destructive">
          <CardHeader class="pb-3">
            <CardTitle class="text-base">{{ stream.name || `Stream #${stream.id}` }}</CardTitle>
            <CardDescription>{{ stream.type.toUpperCase() }}</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="p-3 bg-destructive/10 border border-destructive rounded-md">
              <p class="text-xs font-mono text-destructive/90">{{ stream.errorLog }}</p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>

    <!-- Edit Video Name Dialog -->
    <Dialog v-model:open="isEditDialogOpen">
      <DialogContent class="sm:max-w-106.25">
        <DialogHeader>
          <DialogTitle>Edit Video Name</DialogTitle>
        </DialogHeader>
        <div class="grid gap-4 py-4">
          <div class="grid grid-cols-4 items-center gap-4">
            <Label for="name" class="text-right">
              Name
            </Label>
            <Input id="name" v-model="editName" class="col-span-3" @keyup.enter="handleUpdate" />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" @click="isEditDialogOpen = false">
            Cancel
          </Button>
          <Button @click="handleUpdate">
            Save changes
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>

<style scoped>
.container {
  max-width: 1400px;
}
</style>
