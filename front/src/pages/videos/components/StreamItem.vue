<script setup lang="ts">
import { computed, ref } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ApiException } from '@/exceptions/ApiException'
import StreamService from '@/services/StreamService'
import type { Stream, UpdateStreamDto } from '@/types/Video'
import { Edit, MoreVertical, Trash2, Video, Music, Subtitles, ChevronDown } from '@lucide/vue'
import prettyBytes from 'pretty-bytes'
import { toast } from 'vue-sonner'

const { stream } = defineProps<{ stream: Stream }>()
const emit = defineEmits(['onDeleted', 'onUpdated'])

const isEditDialogOpen = ref(false)
const isErrorExpanded = ref(false)
const editName = ref('')
const editLanguage = ref('')

// Language is part of the manifest for audio and is the sidecar metadata for subtitles; video has none.
const canEditLanguage = computed(() => stream.type === 'audio' || stream.type === 'subtitle')

const handleEdit = () => {
  editName.value = stream.name ?? ''
  editLanguage.value = stream.language ?? ''
  isEditDialogOpen.value = true
}

const handleUpdate = async () => {
  try {
    const dto: UpdateStreamDto = { name: editName.value }
    if (canEditLanguage.value) dto.language = editLanguage.value || null
    const res = await StreamService.update(stream.ulid, dto)
    toast.success(res.data.message)
    isEditDialogOpen.value = false
    emit('onUpdated')
  } catch (e) {
    if (e instanceof ApiException) toast.error(e.message)
    console.error(e)
  }
}

const handleDelete = async () => {
  if (!confirm(`Delete stream ${stream.name ?? stream.ulid}`)) return
  try {
    const res = await StreamService.destroy(stream.ulid)
    toast.info(res.data.message)
    emit('onDeleted')
  } catch (e) {
    if (e instanceof ApiException) toast.error(e.message)
    console.error(e)
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
            <DropdownMenuItem @click="handleEdit">
              <Edit :size="14" class="mr-2" /> Edit
            </DropdownMenuItem>
            <DropdownMenuItem @click="handleDelete" class="text-destructive">
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

  <Dialog v-model:open="isEditDialogOpen">
    <DialogContent class="sm:max-w-106.25">
      <DialogHeader>
        <DialogTitle>Edit Stream Label</DialogTitle>
      </DialogHeader>
      <div class="grid gap-4 py-4">
        <div class="grid grid-cols-4 items-center gap-4">
          <Label for="label" class="text-right">Label</Label>
          <Input id="label" v-model="editName" class="col-span-3" @keyup.enter="handleUpdate" />
        </div>
        <div v-if="canEditLanguage" class="grid grid-cols-4 items-center gap-4">
          <Label for="language" class="text-right">Language</Label>
          <Input id="language" v-model="editLanguage" placeholder="es, en, es-MX…" class="col-span-3" @keyup.enter="handleUpdate" />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" @click="isEditDialogOpen = false">Cancel</Button>
        <Button @click="handleUpdate">Save changes</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
