<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { type Stream, type Video, VideoStatus } from '@/types/Video'
import type { Output } from '@/types/Output'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Progress } from '@/components/ui/progress'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useRoute } from 'vue-router'
import VideoService from '@/services/VideoService'
import prettyBytes from 'pretty-bytes'
import { EditIcon, FileVideo, Radio, Box, Eye, Subtitles, PlayIcon } from 'lucide-vue-next'
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
const selectedOutput = ref<Output | null>(null)
const playerOutput = ref<Output | null>(null)

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

function onStreamDeleted(stream: Stream) {
  if (selectedOutput.value) {
    const idx = selectedOutput.value.streams.findIndex(s => s.id === stream.id)
    if (idx !== -1) selectedOutput.value.streams.splice(idx, 1)
  }
  const vidIdx = video.value?.streams.findIndex(s => s.id === stream.id) ?? -1
  if (vidIdx !== -1) video.value?.streams.splice(vidIdx, 1)
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

const formatLabel: Record<string, string> = {
  hls: 'HLS',
  dash: 'DASH',
  mp4: 'MP4',
}

const formatIcon: Record<string, typeof Radio> = {
  hls: Radio,
  dash: Box,
  mp4: FileVideo,
}

const getOutputStatus = (output: Output): VideoStatus => {
  const statuses = output.streams.map(s => s.status)
  if (statuses.some(s => s === VideoStatus.failed)) return VideoStatus.failed
  if (statuses.some(s => s === VideoStatus.running)) return VideoStatus.running
  if (statuses.some(s => s === VideoStatus.uploading)) return VideoStatus.uploading
  if (statuses.some(s => s === VideoStatus.downloading)) return VideoStatus.downloading
  if (statuses.every(s => s === VideoStatus.completed)) return VideoStatus.completed
  return VideoStatus.pending
}

const getOutputProgress = (output: Output): number => {
  if (output.streams.length === 0) return 0
  const total = output.streams.reduce((sum, s) => sum + s.progress, 0)
  return Math.round(total / output.streams.length)
}

const getOutputSize = (output: Output): number => {
  return output.streams.reduce((sum, s) => sum + s.size, 0)
}

const getOutputQuality = (output: Output): string => {
  const resolutions = output.streams
    .filter(s => (s.type === 'video' || s.type === 'muxed') && s.height)
    .map(s => s.height!)
    .sort((a, b) => b - a)

  if (resolutions.length === 0) return '-'
  return resolutions.map(r => `${r}p`).join(', ')
}

const getOutputAudioCount = (output: Output): number => {
  const muxed = output.streams.find(s => s.type === 'muxed')
  if (muxed) return (muxed.meta?.audioIndices as number[] | undefined)?.length ?? 0
  return output.streams.filter(s => s.type === 'audio').length
}

const subtitleStreams = computed(() => {
  return video.value?.streams.filter(s => s.type === 'subtitle') ?? []
})

const allOutputStreams = computed(() => {
  if (!selectedOutput.value) return []
  return [
    ...selectedOutput.value.streams,
    ...(selectedOutput.value.format == 'mp4' ? [] : subtitleStreams.value),
  ]
})

const hasErrors = (output: Output): boolean => {
  return output.streams.some(s => s.errorLog)
}

const videoStatus = computed((): VideoStatus => {
  if (!video.value || video.value.outputs.length === 0) return video.value?.status ?? VideoStatus.pending
  const statuses = video.value.outputs.map(o => getOutputStatus(o))
  if (statuses.some(s => s === VideoStatus.failed)) return VideoStatus.failed
  if (statuses.some(s => s === VideoStatus.running)) return VideoStatus.running
  if (statuses.some(s => s === VideoStatus.uploading)) return VideoStatus.uploading
  if (statuses.some(s => s === VideoStatus.downloading)) return VideoStatus.downloading
  if (statuses.every(s => s === VideoStatus.completed)) return VideoStatus.completed
  return VideoStatus.pending
})

let refreshInterval: number | null = null

onMounted(async () => {
  await show()
  if (video.value && ![VideoStatus.completed, VideoStatus.failed].includes(videoStatus.value)) {
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
    <!-- Video Information -->
    <div>
      <Card>
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
          <div class="space-y-3">
            <div class="flex items-center justify-between py-2 border-b">
              <span class="text-sm text-muted-foreground">Status</span>
              <Badge :variant="getStatusVariant(videoStatus)">
                {{ videoStatus }}
              </Badge>
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
            <div v-if="video.externalUserId" class="flex items-center justify-between py-2 border-b">
              <span class="text-sm text-muted-foreground">External User ID</span>
              <span class="text-sm font-semibold truncate ml-2">{{ video.externalUserId }}</span>
            </div>
            <div v-if="video.externalResourceId" class="flex items-center justify-between py-2 border-b">
              <span class="text-sm text-muted-foreground">External Resource ID</span>
              <span class="text-sm font-semibold truncate ml-2">{{ video.externalResourceId }}</span>
            </div>
            <div v-if="subtitleStreams.length > 0" class="flex items-center justify-between py-2 border-b">
              <span class="text-sm text-muted-foreground">Subtitles</span>
              <div class="flex items-center gap-1.5">
                <Subtitles :size="14" class="text-muted-foreground" />
                <span class="text-sm font-semibold">{{ subtitleStreams.length }} tracks</span>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>

    <!-- Video Player Dialog -->
    <Dialog :open="!!playerOutput" :modal="false" @update:open="(v: boolean) => { if (!v) playerOutput = null }">
      <DialogContent class="sm:max-w-4xl p-0 overflow-hidden" @pointer-down-outside.prevent @interact-outside.prevent>
        <DialogHeader class="p-6 pb-0">
          <div class="flex items-center gap-2">
            <component v-if="playerOutput" :is="formatIcon[playerOutput.format] ?? FileVideo" :size="18"
              class="text-muted-foreground" />
            <DialogTitle>{{ playerOutput ? (formatLabel[playerOutput.format] ?? playerOutput.format) : '' }} Player
            </DialogTitle>
          </div>
        </DialogHeader>
        <div class="px-6 pb-6">
          <VideoPlayer v-if="playerOutput" :video="video" :output-ulid="playerOutput.ulid" />
        </div>
      </DialogContent>
    </Dialog>

    <!-- Outputs Section -->
    <div v-if="video.outputs.length > 0" class="space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold">Outputs</h2>
        <Badge variant="outline" class="text-sm">
          {{ video.outputs.length }} Total
        </Badge>
      </div>

      <Card>
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Format</TableHead>
                <TableHead>Quality</TableHead>
                <TableHead>Audio</TableHead>
                <TableHead>Size</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Progress</TableHead>
                <TableHead class="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="output in video.outputs" :key="output.ulid"
                :class="{ 'bg-destructive/5': hasErrors(output) }">
                <TableCell>
                  <div class="flex items-center gap-2">
                    <component :is="formatIcon[output.format] ?? FileVideo" :size="16" class="text-muted-foreground" />
                    <span class="font-medium">{{ formatLabel[output.format] ?? output.format }}</span>
                  </div>
                </TableCell>
                <TableCell>
                  <span class="text-sm">{{ getOutputQuality(output) }}</span>
                </TableCell>
                <TableCell>
                  <span class="text-sm">{{ getOutputAudioCount(output) }} tracks</span>
                </TableCell>
                <TableCell>
                  <span class="text-sm">{{ prettyBytes(getOutputSize(output)) }}</span>
                </TableCell>
                <TableCell>
                  <Badge :variant="getStatusVariant(getOutputStatus(output))">
                    {{ getOutputStatus(output) }}
                  </Badge>
                </TableCell>
                <TableCell>
                  <div v-if="getOutputStatus(output) === 'running'" class="flex items-center gap-2 min-w-24">
                    <Progress :model-value="getOutputProgress(output)" class="h-2 flex-1" />
                    <span class="text-xs text-muted-foreground">{{ getOutputProgress(output) }}%</span>
                  </div>
                  <span v-else class="text-sm text-muted-foreground">-</span>
                </TableCell>
                <TableCell class="text-right space-x-1">
                  <Button v-if="getOutputStatus(output) === 'completed' && output.format != 'mp4'" variant="ghost"
                    size="icon" @click="playerOutput = output">
                    <PlayIcon :size="16" />
                  </Button>
                  <Button variant="ghost" size="icon" @click="selectedOutput = output">
                    <Eye :size="16" />
                  </Button>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>

    <!-- Fallback: Legacy videos without outputs -->
    <div v-else-if="video.streams.length > 0" class="space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold">Streams</h2>
        <Badge variant="outline" class="text-sm">
          {{ video.streams.length }} Total
        </Badge>
      </div>
      <Card>
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
                @on-deleted="() => onStreamDeleted(stream)" />
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>

    <!-- Output Detail Dialog -->
    <Dialog :open="!!selectedOutput" @update:open="(v: boolean) => { if (!v) selectedOutput = null }">
      <DialogContent class="sm:max-w-4xl max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <div class="flex items-center gap-2">
            <component v-if="selectedOutput" :is="formatIcon[selectedOutput.format] ?? FileVideo" :size="18"
              class="text-muted-foreground" />
            <DialogTitle>{{ selectedOutput ? (formatLabel[selectedOutput.format] ?? selectedOutput.format) : '' }}
              Output</DialogTitle>
            <Badge v-if="selectedOutput" :variant="getStatusVariant(getOutputStatus(selectedOutput))">
              {{ getOutputStatus(selectedOutput) }}
            </Badge>
          </div>
        </DialogHeader>

        <div v-if="selectedOutput" class="space-y-4">
          <!-- Output Info -->
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
            <div>
              <p class="text-muted-foreground">Size</p>
              <p class="font-medium">{{ prettyBytes(getOutputSize(selectedOutput)) }}</p>
            </div>
            <div>
              <p class="text-muted-foreground">Streams</p>
              <p class="font-medium">{{ allOutputStreams.length }}</p>
            </div>
            <div>
              <p class="text-muted-foreground">Quality</p>
              <p class="font-medium">{{ getOutputQuality(selectedOutput) }}</p>
            </div>
          </div>

          <!-- Progress -->
          <div v-if="getOutputStatus(selectedOutput) === 'running'" class="space-y-1.5">
            <div class="flex justify-between text-xs text-muted-foreground">
              <span>Progress</span>
              <span>{{ getOutputProgress(selectedOutput) }}%</span>
            </div>
            <Progress :model-value="getOutputProgress(selectedOutput)" class="h-2" />
          </div>

          <!-- Streams Table -->
          <div v-if="allOutputStreams.length > 0" class="border rounded-md overflow-hidden">
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
                <StreamItem v-for="stream in allOutputStreams" :stream="stream" :key="stream.id"
                  @on-deleted="() => onStreamDeleted(stream)" />
              </TableBody>
            </Table>
          </div>

          <!-- Stream Error Logs -->
          <div v-if="allOutputStreams.some(s => s.errorLog)" class="space-y-2">
            <p class="text-sm font-medium text-destructive">Error Logs</p>
            <div v-for="stream in allOutputStreams.filter(s => s.errorLog)" :key="`error-${stream.id}`"
              class="p-2.5 bg-destructive/10 border border-destructive rounded-md">
              <p class="text-xs font-medium mb-1">{{ stream.name || `Stream #${stream.id}` }} ({{
                stream.type.toUpperCase() }})</p>
              <p class="text-xs font-mono text-destructive/90">{{ stream.errorLog }}</p>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>

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
