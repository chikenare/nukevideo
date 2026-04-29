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
import { Trash2, Settings2 } from 'lucide-vue-next'

const props = defineProps<{
  modelValue: Record<string, unknown>
  config: CodecConfig
  index: number
  format?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
  'remove': []
}>()

const dialogOpen = ref(false)
const localVariant = ref<Record<string, unknown>>({})
const localCodec = ref('')

const videoCodecs = computed(() => {
  const codecs = props.config.codecs.filter(c => c.type === 'video')
  if (!props.format || !props.config.formats) return codecs

  const formatConfig = props.config.formats[props.format]
  if (!formatConfig) return codecs

  return codecs.filter(c => {
    return formatConfig.protocols.length === 0 ||
      (c.protocols && c.protocols.some((p: string) => formatConfig.protocols.includes(p)))
  })
})

const videoParameters = computed(() => {
  if (!localCodec.value) return {}

  const filtered: Record<string, typeof props.config.parameters[string]> = {}
  Object.entries(props.config.parameters).forEach(([key, param]) => {
    if (param.type === 'video' && param.availableFor.includes(localCodec.value)) {
      filtered[key] = param
    }
  })
  return filtered
})

watch(localCodec, (newCodec) => {
  localVariant.value = { ...localVariant.value, videoCodec: newCodec }
})

const codecLabel = computed(() => {
  const codec = props.modelValue.videoCodec as string
  return videoCodecs.value.find(c => c.codec === codec)?.label ?? null
})

const openDialog = () => {
  localVariant.value = { ...props.modelValue }
  localCodec.value = (props.modelValue.videoCodec as string) || ''
  dialogOpen.value = true
}

const saveDialog = () => {
  emit('update:modelValue', { ...localVariant.value, videoCodec: localCodec.value })
  dialogOpen.value = false
}

const updateParameter = (key: string, value: unknown) => {
  if (value === null || value === undefined || value === '') {
    const { [key]: _, ...rest } = localVariant.value
    localVariant.value = rest
  } else {
    localVariant.value = { ...localVariant.value, [key]: value }
  }
}
</script>

<template>
  <div class="flex items-center justify-between px-4 py-3 rounded-lg border bg-card gap-4">
    <div class="flex items-center gap-3 min-w-0">
      <div
        class="flex items-center justify-center w-6 h-6 rounded-full bg-muted text-muted-foreground text-xs font-semibold shrink-0">
        {{ index + 1 }}
      </div>
      <div class="min-w-0">
        <p class="text-sm font-medium leading-none">Variant {{ index + 1 }}</p>
        <p class="text-xs text-muted-foreground mt-0.5 truncate">
          {{ codecLabel ?? 'Not configured' }}
        </p>
      </div>
    </div>
    <div class="flex items-center gap-2 shrink-0">
      <Badge v-if="!codecLabel" variant="secondary" class="text-xs">Incomplete</Badge>
      <Button variant="outline" size="sm" @click="openDialog">
        <Settings2 :size="14" class="mr-1.5" />
        Configure
      </Button>
      <Button variant="ghost" size="icon" class="h-8 w-8" @click="emit('remove')">
        <Trash2 :size="14" class="text-destructive" />
      </Button>
    </div>
  </div>

  <Dialog v-model:open="dialogOpen">
    <DialogContent class="max-w-2xl max-h-[85vh] overflow-y-auto">
      <DialogHeader>
        <DialogTitle>Configure Variant {{ index + 1 }}</DialogTitle>
      </DialogHeader>

      <div class="space-y-6 py-2">
        <div class="space-y-2">
          <Label>Video Codec *</Label>
          <Select v-model="localCodec">
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

        <div v-if="localCodec && Object.keys(videoParameters).length > 0">
          <Separator class="mb-5" />
          <h4 class="text-sm font-semibold mb-4">Video Parameters</h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <DynamicFormField
              v-for="(param, key) in videoParameters"
              :key="key"
              :param-key="String(key)"
              :parameter="param"
              :model-value="localVariant[key] as (string | number | boolean | null)"
              @update:model-value="updateParameter(String(key), $event)"
            />
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
