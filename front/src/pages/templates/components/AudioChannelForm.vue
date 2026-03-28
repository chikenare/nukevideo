<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import DynamicFormField from './DynamicFormField.vue'
import type { CodecConfig } from '@/types/CodecConfig'
import type { AudioConfig, AudioChannelEntry } from '@/types/Template'
import { Trash2, Plus, Settings2, Music } from 'lucide-vue-next'

const props = defineProps<{
  modelValue: AudioConfig
  config: CodecConfig
  format?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: AudioConfig]
}>()

const dialogOpen = ref(false)
const localAudio = ref<AudioConfig>({ ...props.modelValue })
const localCodec = ref('')

const audioCodecs = computed(() => {
  const allAudio = props.config.codecs.filter(c => c.type === 'audio')
  if (!props.format || !props.config.formats) return allAudio

  const formatConfig = props.config.formats[props.format]
  if (!formatConfig) return allAudio

  return allAudio.filter(c => !c.container || formatConfig.containers.includes(c.container))
})

const audioParameters = computed(() => {
  if (!localCodec.value) return {}

  const filtered: Record<string, typeof props.config.parameters[string]> = {}
  Object.entries(props.config.parameters).forEach(([key, param]) => {
    if (
      param.type === 'audio' &&
      param.availableFor.includes(localCodec.value) &&
      key !== 'channels' &&
      key !== 'audioBitrate'
    ) {
      filtered[key] = param
    }
  })
  return filtered
})

const bitrateOptions = computed(() => props.config.parameters['audioBitrate']?.options ?? [])
const channelOptions = computed(() => props.config.parameters['channels']?.options ?? [])

const channelLabel = (ch: string) => {
  if (ch === '1') return 'Mono'
  if (ch === '2') return 'Stereo'
  if (ch === '6') return '5.1'
  return ch
}

watch(localCodec, (newCodec) => {
  if (newCodec) localAudio.value = { ...localAudio.value, audioCodec: newCodec }
})

const addChannel = () => {
  localAudio.value.channels.push({ channels: '', audioBitrate: '' })
}

const removeChannel = (index: number) => {
  if (localAudio.value.channels.length > 1) {
    localAudio.value.channels.splice(index, 1)
  }
}

const updateChannelField = (index: number, field: keyof AudioChannelEntry, value: string) => {
  localAudio.value.channels[index] = { ...localAudio.value.channels[index], [field]: value } as AudioChannelEntry
}

const updateParameter = (key: string, value: unknown) => {
  if (value === null || value === undefined || value === '') {
    const { [key]: _, ...rest } = localAudio.value
    localAudio.value = { ...rest, channels: localAudio.value.channels } as AudioConfig
  } else {
    localAudio.value = { ...localAudio.value, [key]: value }
  }
}

const openDialog = () => {
  localAudio.value = JSON.parse(JSON.stringify(props.modelValue))
  localCodec.value = (props.modelValue.audioCodec as string) || ''
  dialogOpen.value = true
}

const saveDialog = () => {
  emit('update:modelValue', { ...localAudio.value, audioCodec: localCodec.value })
  dialogOpen.value = false
}

// Summary for compact display
const currentCodecLabel = computed(() => {
  const codec = props.modelValue.audioCodec as string
  if (!codec) return null
  return audioCodecs.value.find(c => c.codec === codec)?.label ?? codec
})

const channelSummary = computed(() => {
  const chs = props.modelValue.channels ?? []
  return chs
    .filter(ch => ch.channels && ch.audioBitrate)
    .map(ch => `${channelLabel(ch.channels as string)} @ ${ch.audioBitrate}`)
    .join(', ')
})
</script>

<template>
  <div class="flex items-center justify-between px-4 py-3 rounded-lg border bg-card gap-4">
    <div class="flex items-center gap-3 min-w-0">
      <Music :size="16" class="text-muted-foreground shrink-0" />
      <div class="min-w-0">
        <p class="text-sm font-medium leading-none">{{ currentCodecLabel ?? 'Not configured' }}</p>
        <p v-if="channelSummary" class="text-xs text-muted-foreground mt-0.5 truncate">
          {{ channelSummary }}
        </p>
      </div>
    </div>
    <div class="flex items-center gap-2 shrink-0">
      <Badge v-if="!currentCodecLabel" variant="secondary" class="text-xs">Incomplete</Badge>
      <Button variant="outline" size="sm" @click="openDialog">
        <Settings2 :size="14" class="mr-1.5" />
        Configure
      </Button>
    </div>
  </div>

  <Dialog v-model:open="dialogOpen">
    <DialogContent class="max-w-2xl max-h-[85vh] overflow-y-auto">
      <DialogHeader>
        <DialogTitle>Configure Audio Track</DialogTitle>
      </DialogHeader>

      <div class="space-y-6 py-2">
        <div class="space-y-2">
          <Label>Audio Codec *</Label>
          <Select v-model="localCodec">
            <SelectTrigger>
              <SelectValue placeholder="Select audio codec" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="codec in audioCodecs" :key="codec.codec" :value="codec.codec">
                <div>
                  <div class="font-medium">{{ codec.label }}</div>
                  <div class="text-xs text-muted-foreground">{{ codec.description }}</div>
                </div>
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div v-if="localCodec && Object.keys(audioParameters).length > 0">
          <Separator class="mb-5" />
          <h4 class="text-sm font-semibold mb-4">Audio Parameters</h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <DynamicFormField
              v-for="(param, key) in audioParameters"
              :key="key"
              :param-key="String(key)"
              :parameter="param"
              :model-value="localAudio[key] as (string | number | boolean | null)"
              @update:model-value="updateParameter(String(key), $event)"
            />
          </div>
        </div>

        <div v-if="localCodec">
          <Separator class="mb-5" />
          <div class="flex items-center justify-between mb-4">
            <h4 class="text-sm font-semibold">Channel Bitrates</h4>
            <Button @click="addChannel" variant="outline" size="sm">
              <Plus :size="14" class="mr-1.5" />
              Add Channel
            </Button>
          </div>
          <div class="space-y-3">
            <div
              v-for="(channel, index) in localAudio.channels"
              :key="index"
              class="flex items-end gap-3"
            >
              <div class="flex-1 space-y-1.5">
                <Label v-if="index === 0" class="text-xs text-muted-foreground">Channels</Label>
                <Select
                  :model-value="channel.channels"
                  @update:model-value="(val: any) => updateChannelField(index, 'channels', val)"
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select channels" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="opt in channelOptions" :key="opt" :value="opt">
                      {{ channelLabel(opt) }} ({{ opt }})
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div class="flex-1 space-y-1.5">
                <Label v-if="index === 0" class="text-xs text-muted-foreground">Bitrate</Label>
                <Select
                  :model-value="channel.audioBitrate"
                  @update:model-value="(val: any) => updateChannelField(index, 'audioBitrate', val)"
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select bitrate" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="opt in bitrateOptions" :key="opt" :value="opt">
                      {{ opt }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <Button
                variant="ghost"
                size="icon"
                class="h-9 w-9"
                @click="removeChannel(index)"
                :disabled="localAudio.channels.length <= 1"
              >
                <Trash2 :size="14" class="text-destructive" />
              </Button>
            </div>
          </div>
        </div>
      </div>

      <DialogFooter>
        <Button variant="outline" @click="dialogOpen = false">Cancel</Button>
        <Button @click="saveDialog">Save</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
