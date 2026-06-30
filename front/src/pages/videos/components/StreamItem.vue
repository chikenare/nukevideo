<script setup lang="ts">
import { computed, ref } from 'vue'
import { Button } from '@/components/ui/button'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { ApiException } from '@/exceptions/ApiException'
import StreamService from '@/services/StreamService'
import type { Stream } from '@/types/Video'
import { MoreVertical, Trash2, Video, Music, Subtitles, ChevronDown } from '@lucide/vue'
import prettyBytes from 'pretty-bytes'
import { toast } from 'vue-sonner'

const { stream } = defineProps<{ stream: Stream }>()
const emit = defineEmits(['onDeleted'])

const isErrorExpanded = ref(false)
const isDeleteDialogOpen = ref(false)

const handleDelete = async () => {
  try {
    const res = await StreamService.destroy(stream.ulid)
    toast.info(res.data.message)
    emit('onDeleted')
  } catch (e) {
    if (e instanceof ApiException) toast.error(e.message)
    console.error(e)
  } finally {
    isDeleteDialogOpen.value = false
  }
}

const typeIcon = computed(() => {
  if (stream.type === 'video') return Video
  if (stream.type === 'audio') return Music
  return Subtitles
})

const codecMap: Record<string, string> = {
  libx264: 'H.264',
  libx265: 'H.265',
  libvpx: 'VP8',
  'libvpx-vp9': 'VP9',
  'libaom-av1': 'AV1',
  aac: 'AAC',
  libopus: 'Opus',
  libmp3lame: 'MP3',
  copy: 'Copy',
}

const codec = computed((): string | null => {
  if (stream.type === 'video') return (stream.inputParams?.video_codec as string) ?? null
  if (stream.type === 'audio') return (stream.inputParams?.audio_codec as string) ?? null
  return null
})

const codecLabel = computed(() =>
  codec.value ? (codecMap[codec.value] ?? codec.value) : null
)

const formatChannels = (ch: number): string => {
  if (ch === 1) return 'Mono'
  if (ch === 2) return 'Stereo'
  if (ch === 6) return '5.1'
  if (ch === 8) return '7.1'
  return `${ch}ch`
}

const label = computed(() => {
  if (stream.type === 'video') return stream.height ? `${stream.height}p` : 'Video'
  const base = stream.name ?? ''
  const lang = stream.language ? `(${stream.language.toUpperCase()})` : ''
  return `${base} ${lang}`.trim() || stream.type
})

const details = computed(() => {
  const parts: string[] = []
  if (codecLabel.value) parts.push(codecLabel.value)
  if (stream.type === 'video' && stream.width && stream.height) parts.push(`${stream.width}×${stream.height}`)
  if (stream.type === 'audio' && stream.channels) parts.push(formatChannels(stream.channels))
  if (stream.language && stream.type !== 'video') parts.push(stream.language.toUpperCase())
  return parts
})
</script>

<template>
  <div>
    <div
      class="flex items-center justify-between px-3 py-2.5 gap-3"
      :class="{ 'bg-destructive/5': stream.errorLog }"
    >
      <div class="flex items-center gap-2.5 min-w-0">
        <component :is="typeIcon" :size="15" class="text-muted-foreground shrink-0" />
        <div class="min-w-0">
          <div class="text-sm font-medium leading-tight">{{ label }}</div>
          <div v-if="details.length" class="text-xs text-muted-foreground mt-0.5">
            <span v-for="(d, i) in details" :key="i">
              <span v-if="i > 0" class="mx-1 opacity-30">·</span>{{ d }}
            </span>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-1.5 shrink-0">
        <span class="text-xs text-muted-foreground">{{ prettyBytes(stream.size) }}</span>

        <Button
          v-if="stream.errorLog"
          variant="ghost"
          size="icon"
          class="h-7 w-7 text-destructive"
          @click="isErrorExpanded = !isErrorExpanded"
        >
          <ChevronDown :size="14" :class="{ 'rotate-180': isErrorExpanded }" class="transition-transform" />
        </Button>

        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" class="h-7 w-7">
              <MoreVertical :size="14" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem @click="isDeleteDialogOpen = true" class="text-destructive">
              <Trash2 :size="14" class="mr-2" /> Delete
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>

    <div v-if="stream.errorLog && isErrorExpanded" class="px-3 pb-2.5">
      <pre class="text-xs font-mono text-destructive bg-destructive/10 border border-destructive/20 rounded p-2 whitespace-pre-wrap break-all">{{ stream.errorLog }}</pre>
    </div>
  </div>

  <AlertDialog v-model:open="isDeleteDialogOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Delete stream</AlertDialogTitle>
        <AlertDialogDescription>
          Delete stream "{{ stream.name ?? stream.ulid }}"? This action cannot be undone.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>Cancel</AlertDialogCancel>
        <AlertDialogAction @click="handleDelete">Delete</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
