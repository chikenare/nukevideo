<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Collapsible, CollapsibleContent } from '@/components/ui/collapsible'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import DynamicFormField from './DynamicFormField.vue'
import type { CodecConfig } from '@/types/CodecConfig'
import type { AudioConfig, AudioChannelEntry } from '@/types/Template'
import { Trash2, ChevronDown, Plus } from 'lucide-vue-next'

const props = defineProps<{
  modelValue: AudioConfig
  config: CodecConfig
}>()

const emit = defineEmits<{
  'update:modelValue': [value: AudioConfig]
}>()

const isOpen = ref(true)

const audioConfig = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val)
})

const selectedAudioCodec = ref<string>(audioConfig.value.audio_codec as string || '')

const audioCodecs = computed(() => props.config.codecs.filter(c => c.type === 'audio'))

const audioParameters = computed(() => {
  if (!selectedAudioCodec.value) return {}

  const filtered: Record<string, typeof props.config.parameters[string]> = {}
  Object.entries(props.config.parameters).forEach(([key, param]) => {
    if (param.type === 'audio'
      && param.available_for.includes(selectedAudioCodec.value)
      && key !== 'channels'
      && key !== 'audio_bitrate') {
      filtered[key] = param
    }
  })
  return filtered
})

const bitrateOptions = computed(() => {
  const param = props.config.parameters['audio_bitrate']
  return param?.options ?? []
})

const channelOptions = computed(() => {
  const param = props.config.parameters['channels']
  return param?.options ?? []
})

const channelLabel = (ch: string) => {
  if (ch === '1') return 'Mono'
  if (ch === '2') return 'Stereo'
  if (ch === '6') return '5.1'
  return ch
}

watch(selectedAudioCodec, (newCodec) => {
  if (newCodec) {
    audioConfig.value = { ...audioConfig.value, audio_codec: newCodec }
  }
})

const channels = computed({
  get: () => audioConfig.value.channels ?? [],
  set: (val) => {
    audioConfig.value = { ...audioConfig.value, channels: val }
  }
})

const addChannel = () => {
  channels.value = [...channels.value, { channels: '', audio_bitrate: '' }]
}

const removeChannel = (index: number) => {
  if (channels.value.length > 1) {
    const updated = [...channels.value]
    updated.splice(index, 1)
    channels.value = updated
  }
}

const updateChannelField = (index: number, field: keyof AudioChannelEntry, value: string) => {
  const updated = [...channels.value]
  updated[index] = { ...updated[index], [field]: value }
  channels.value = updated
}

const updateParameter = (key: string, value: unknown) => {
  if (value === null || value === undefined || value === '') {
    const { [key]: _, ...rest } = audioConfig.value
    audioConfig.value = { ...rest, channels: channels.value } as AudioConfig
  } else {
    audioConfig.value = { ...audioConfig.value, [key]: value }
  }
}
</script>

<template>
  <Collapsible v-model:open="isOpen" as-child>
    <Card>
      <CardHeader class="cursor-pointer select-none" @click="isOpen = !isOpen">
        <div class="flex items-center gap-3">
          <ChevronDown :size="18" class="transition-transform duration-200" :class="{ '-rotate-90': !isOpen }" />
          <CardTitle>Audio Configuration</CardTitle>
        </div>
      </CardHeader>
      <CollapsibleContent>
        <CardContent class="space-y-6">
          <!-- Audio Codec Selection -->
          <div class="space-y-2">
            <Label>Audio Codec *</Label>
            <Select v-model="selectedAudioCodec">
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

          <!-- Shared Audio Parameters (excluding channels and bitrate) -->
          <div v-if="selectedAudioCodec && Object.keys(audioParameters).length > 0" class="space-y-4">
            <Separator />
            <div>
              <h4 class="text-sm font-semibold mb-4">Audio Parameters</h4>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <DynamicFormField v-for="(param, key) in audioParameters" :key="key" :param-key="String(key)"
                  :parameter="param" :model-value="audioConfig[key] as (string | number | boolean | null)"
                  @update:model-value="updateParameter(String(key), $event)" />
              </div>
            </div>
          </div>

          <!-- Channel Bitrates -->
          <div v-if="selectedAudioCodec" class="space-y-4">
            <Separator />
            <div>
              <h4 class="text-sm font-semibold mb-4">Channel Bitrates</h4>
              <div class="space-y-3">
                <div v-for="(channel, index) in channels" :key="index"
                  class="flex items-end gap-3">
                  <div class="flex-1 space-y-1">
                    <Label v-if="index === 0" class="text-xs">Channels</Label>
                    <Select :model-value="channel.channels"
                      @update:model-value="(val: string) => updateChannelField(index, 'channels', val)">
                      <SelectTrigger>
                        <SelectValue placeholder="Channels" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem v-for="opt in channelOptions" :key="opt" :value="opt">
                          {{ channelLabel(opt) }} ({{ opt }})
                        </SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div class="flex-1 space-y-1">
                    <Label v-if="index === 0" class="text-xs">Bitrate</Label>
                    <Select :model-value="channel.audio_bitrate"
                      @update:model-value="(val: string) => updateChannelField(index, 'audio_bitrate', val)">
                      <SelectTrigger>
                        <SelectValue placeholder="Bitrate" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem v-for="opt in bitrateOptions" :key="opt" :value="opt">
                          {{ opt }}
                        </SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <Button variant="ghost" size="icon" @click="removeChannel(index)"
                    :disabled="channels.length <= 1">
                    <Trash2 :size="16" class="text-destructive" />
                  </Button>
                </div>
              </div>
              <Button @click="addChannel" variant="outline" size="sm" class="mt-3">
                <Plus :size="14" class="mr-1" />
                Add Channel
              </Button>
            </div>
          </div>
        </CardContent>
      </CollapsibleContent>
    </Card>
  </Collapsible>
</template>
