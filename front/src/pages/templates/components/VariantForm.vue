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
import { Trash2, ChevronDown } from 'lucide-vue-next'

const props = defineProps<{
  modelValue: Record<string, unknown>
  config: CodecConfig
  index: number
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
  'remove': []
}>()

const isOpen = ref(true)

const variant = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val)
})

const selectedVideoCodec = ref<string>(variant.value.video_codec as string || '')
const selectedAudioCodec = ref<string>(variant.value.audio_codec as string || '')

// Get available codecs
const videoCodecs = computed(() => props.config.codecs.filter(c => c.type === 'video'))
const audioCodecs = computed(() => {
  if (!selectedVideoCodec.value) return []

  return props.config.codecs.filter(c => {
    if (c.type !== 'audio') return false
    if (!c.available_for || c.available_for.length === 0) return true
    return c.available_for.includes(selectedVideoCodec.value)
  })
})

// Get parameters for selected codecs
const videoParameters = computed(() => {
  if (!selectedVideoCodec.value) return {}

  const filtered: Record<string, typeof props.config.parameters[string]> = {}
  Object.entries(props.config.parameters).forEach(([key, param]) => {
    if (param.type === 'video' && param.available_for.includes(selectedVideoCodec.value)) {
      filtered[key] = param
    }
  })
  return filtered
})

const audioParameters = computed(() => {
  if (!selectedAudioCodec.value) return {}

  const filtered: Record<string, typeof props.config.parameters[string]> = {}
  Object.entries(props.config.parameters).forEach(([key, param]) => {
    if (param.type === 'audio' && param.available_for.includes(selectedAudioCodec.value)) {
      filtered[key] = param
    }
  })
  return filtered
})

// Watch for codec changes and update variant
watch(selectedVideoCodec, (newCodec) => {
  variant.value = { ...variant.value, video_codec: newCodec }

  // Reset audio codec if it's not compatible
  if (selectedAudioCodec.value) {
    const isCompatible = audioCodecs.value.some(c => c.codec === selectedAudioCodec.value)
    if (!isCompatible) {
      selectedAudioCodec.value = ''
      const { ...rest } = variant.value
      variant.value = rest
    }
  }
})

watch(selectedAudioCodec, (newCodec) => {
  if (newCodec) {
    variant.value = { ...variant.value, audio_codec: newCodec }
  } else {
    const { ...rest } = variant.value
    variant.value = rest
  }
})

const variantSummary = computed(() => {
  const parts: string[] = []
  const videoCodec = videoCodecs.value.find(c => c.codec === selectedVideoCodec.value)
  if (videoCodec) parts.push(videoCodec.label)
  const audioCodec = audioCodecs.value.find(c => c.codec === selectedAudioCodec.value)
  if (audioCodec) parts.push(audioCodec.label)
  return parts.length > 0 ? parts.join(' / ') : 'Not configured'
})

const updateParameter = (key: string, value: unknown) => {
  if (value === null || value === undefined || value === '') {
    const { ...rest } = variant.value
    variant.value = rest
  } else {
    variant.value = { ...variant.value, [key]: value }
  }
}
</script>

<template>
  <Collapsible v-model:open="isOpen" as-child>
    <Card>
      <CardHeader class="cursor-pointer select-none" @click="isOpen = !isOpen">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <ChevronDown :size="18" class="transition-transform duration-200" :class="{ '-rotate-90': !isOpen }" />
            <div>
              <CardTitle class="flex items-center gap-2">
                Variant {{ index + 1 }}
                <span class="text-sm font-normal text-muted-foreground">— {{ variantSummary }}</span>
              </CardTitle>
            </div>
          </div>
          <Button variant="ghost" size="icon" @click.stop="emit('remove')">
            <Trash2 :size="16" class="text-destructive" />
          </Button>
        </div>
      </CardHeader>
      <CollapsibleContent>
        <CardContent class="space-y-6">
          <!-- Video Codec Selection -->
          <div class="space-y-2">
            <Label>Video Codec *</Label>
            <Select v-model="selectedVideoCodec">
              <SelectTrigger>
                <SelectValue placeholder="Select video codec" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="codec in videoCodecs" :key="codec.codec" :value="codec.codec">
                  <div>
                    <div class="font-medium">{{ codec.label }}</div>
                    <div class="text-xs text-muted-foreground">{{ codec.description }}</div>
                  </div>
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <!-- Video Parameters -->
          <div v-if="selectedVideoCodec && Object.keys(videoParameters).length > 0" class="space-y-4">
            <Separator />
            <div>
              <h4 class="text-sm font-semibold mb-4">Video Parameters</h4>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <DynamicFormField v-for="(param, key) in videoParameters" :key="key" :param-key="String(key)"
                  :parameter="param" :model-value="variant[key] as (string | number | boolean | null)"
                  @update:model-value="updateParameter(String(key), $event)" />
              </div>
            </div>
          </div>

          <!-- Audio Codec Selection -->
          <div v-if="selectedVideoCodec" class="space-y-2">
            <Separator />
            <Label>Audio Codec</Label>
            <Select v-model="selectedAudioCodec">
              <SelectTrigger>
                <SelectValue placeholder="Select audio codec" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="codec in audioCodecs" :key="codec.codec" :value="codec.codec">
                  <div>
                    <div class="font-medium">{{ codec.label }}</div>
                  </div>
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <!-- Audio Parameters -->
          <div v-if="selectedAudioCodec && Object.keys(audioParameters).length > 0" class="space-y-4">
            <div>
              <h4 class="text-sm font-semibold mb-4">Audio Parameters</h4>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <DynamicFormField v-for="(param, key) in audioParameters" :key="key" :param-key="String(key)"
                  :parameter="param" :model-value="variant[key] as (string | number | boolean | null)"
                  @update:model-value="updateParameter(String(key), $event)" />
              </div>
            </div>
          </div>
        </CardContent>
      </CollapsibleContent>
    </Card>
  </Collapsible>
</template>
