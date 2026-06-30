<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { type Stream, type Video, VideoStatus } from '@/types/Video'
import type { Output } from '@/types/Output'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Progress } from '@/components/ui/progress'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useRoute } from 'vue-router'
import VideoService from '@/services/VideoService'
import prettyBytes from 'pretty-bytes'
import { EditIcon, FileVideo, Radio, Box, Subtitles, PlayIcon } from '@lucide/vue'
import { formatSecondsToTime } from '@/utils/timeFormatter'
import DeleteVideoButton from './components/DeleteVideoButton.vue'
import StreamItem from './components/StreamItem.vue'
import { toast } from 'vue-sonner'
import { ApiException } from '@/exceptions/ApiException'
import ShakaVideoPlayer from './components/ShakaVideoPlayer.vue'

const route = useRoute()

const video = ref<Video>()
const isEditDialogOpen = ref(false)
const editName = ref('')
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
    uploading: 'default',
  }
  return variants[status] || 'default'
}

function onStreamDeleted(stream: Stream) {
  video.value?.outputs.forEach(output => {
    const idx = output.streams.findIndex(s => s.ulid === stream.ulid)
    if (idx !== -1) output.streams.splice(idx, 1)
  })
  const vidIdx = video.value?.streams.findIndex(s => s.ulid === stream.ulid) ?? -1
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
    if (e instanceof ApiException) toast.error(e.message)
    console.error(e)
  }
}

const sizeFormatted = computed(() => prettyBytes(video.value?.size ?? 0, { binary: true }))

const formatLabel: Record<string, string> = {
  hls: 'HLS',
  dash: 'DASH',
}

const formatIcon: Record<string, typeof Radio> = {
  hls: Radio,
  dash: Box,
}

const getOutputSize = (output: Output): number =>
  output.streams.reduce((sum, s) => sum + s.size, 0)

const subtitleStreams = computed(() =>
  video.value?.streams.filter(s => s.type === 'subtitle') ?? []
)

const outputStreams = (output: Output): Stream[] => [
  ...output.streams,
  ...subtitleStreams.value,
]

const getOutputSummary = (output: Output): string => {
  const resolutions = output.streams
    .filter(s => s.type === 'video' && s.height)
    .map(s => s.height!)
    .sort((a, b) => b - a)
    .map(r => `${r}p`)

  const audioCount = output.streams.filter(s => s.type === 'audio').length

  const parts: string[] = []
  if (resolutions.length) parts.push(resolutions.join(', '))
  if (audioCount) parts.push(`${audioCount} audio`)
  if (subtitleStreams.value.length) parts.push(`${subtitleStreams.value.length} subtitle`)
  return parts.join(' · ')
}

const terminalStatuses = [VideoStatus.completed, VideoStatus.failed]

const isStillProcessing = computed(() => {
  if (!video.value) return false
  if (!terminalStatuses.includes(video.value.status as VideoStatus)) return true
  return video.value.outputs.some(o => !terminalStatuses.includes(o.status))
})

let refreshInterval: number | null = null

onMounted(async () => {
  await show()
  if (video.value && isStillProcessing.value) {
    refreshInterval = setInterval(() => show(), 5000)
  }
})

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval)
})
</script>

<template>
  <div v-if="video" class="container mx-auto p-6 space-y-6">

    <!-- Video Information -->
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
      <CardContent class="space-y-3">
        <div class="flex items-center justify-between py-2 border-b">
          <span class="text-sm text-muted-foreground">Status</span>
          <Badge :variant="getStatusVariant(video.status)">{{ video.status }}</Badge>
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
      </CardContent>
    </Card>

    <!-- Video Player Dialog -->
    <Dialog :open="!!playerOutput" :modal="false" @update:open="(v: boolean) => { if (!v) playerOutput = null }">
      <DialogContent class="sm:max-w-4xl p-0 overflow-hidden" @pointer-down-outside.prevent @interact-outside.prevent>
        <DialogHeader class="p-6 pb-0">
          <div class="flex items-center gap-2">
            <component v-if="playerOutput" :is="formatIcon[playerOutput.format] ?? FileVideo" :size="18" class="text-muted-foreground" />
            <DialogTitle>{{ playerOutput ? (formatLabel[playerOutput.format] ?? playerOutput.format) : '' }} Player</DialogTitle>
          </div>
        </DialogHeader>
        <div class="px-6 pb-6">
          <ShakaVideoPlayer v-if="playerOutput" :video="video" :output-ulid="playerOutput.ulid" />
        </div>
      </DialogContent>
    </Dialog>

    <!-- Outputs Section -->
    <div v-if="video.outputs.length > 0" class="space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold">Outputs</h2>
        <Badge variant="outline" class="text-sm">{{ video.outputs.length }} Total</Badge>
      </div>

      <div class="space-y-3">
        <Card v-for="output in video.outputs" :key="output.ulid">
          <CardHeader class="pb-3">
            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-2 min-w-0">
                <component :is="formatIcon[output.format] ?? FileVideo" :size="16" class="text-muted-foreground shrink-0" />
                <span class="font-semibold text-sm">{{ formatLabel[output.format] ?? output.format }}</span>
                <Badge :variant="getStatusVariant(output.status)" class="text-xs">{{ output.status }}</Badge>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <span class="text-sm text-muted-foreground">{{ prettyBytes(getOutputSize(output)) }}</span>
                <Button
                  v-if="output.status === 'completed'"
                  variant="ghost"
                  size="icon"
                  class="h-7 w-7"
                  @click="playerOutput = output"
                >
                  <PlayIcon :size="14" />
                </Button>
              </div>
            </div>

            <!-- Summary line -->
            <p v-if="getOutputSummary(output)" class="text-xs text-muted-foreground mt-0.5 ml-6">
              {{ getOutputSummary(output) }}
            </p>

            <!-- Progress (only while running) -->
            <div v-if="output.status === 'running'" class="flex items-center gap-2 mt-2">
              <Progress :model-value="output.progress" class="h-1.5 flex-1" />
              <span class="text-xs text-muted-foreground tabular-nums">{{ output.progress }}%</span>
            </div>
          </CardHeader>

          <CardContent class="pt-0 pb-0">
            <div v-if="outputStreams(output).length" class="border rounded-md divide-y overflow-hidden mb-4">
              <StreamItem
                v-for="stream in outputStreams(output)"
                :key="stream.ulid"
                :stream="stream"
                @on-deleted="() => onStreamDeleted(stream)"
              />
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
            <Label for="name" class="text-right">Name</Label>
            <Input id="name" v-model="editName" class="col-span-3" @keyup.enter="handleUpdate" />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" @click="isEditDialogOpen = false">Cancel</Button>
          <Button @click="handleUpdate">Save changes</Button>
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
