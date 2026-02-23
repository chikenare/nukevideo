<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import VariantForm from './components/VariantForm.vue'
import AudioChannelForm from './components/AudioChannelForm.vue'
import { useCodecConfig } from '@/composables/useCodecConfig'
import TemplateService from '@/services/TemplateService'
import type { Template, CreateTemplateDto, UpdateTemplateDto, OutputFormat, AudioConfig } from '@/types/Template'
import { Plus, Save, ArrowLeft } from 'lucide-vue-next'
import { ApiException } from '@/exceptions/ApiException'
import { toast } from 'vue-sonner'

const router = useRouter()
const route = useRoute()
const { config, loading: configLoading, fetchConfig } = useCodecConfig()

const isEdit = computed(() => !!route.params.id)
const pageTitle = computed(() => isEdit.value ? 'Edit Template' : 'Create Template')

const templateName = ref('')
const outputFormat = ref<OutputFormat>('hls')
const variants = ref<Record<string, unknown>[]>([{}])
const audioConfig = ref<AudioConfig>({ channels: [{ channels: '', audio_bitrate: '' }] })
const loading = ref(false)
const saving = ref(false)

const isMuxedFormat = computed(() => outputFormat.value === 'mp4' || outputFormat.value === 'mkv')

watch(outputFormat, (newFormat) => {
  if ((newFormat === 'mp4' || newFormat === 'mkv') && variants.value.length > 1) {
    variants.value = [variants.value[0]!]
  }
})

const addVariant = () => {
  variants.value.push({})
}

const removeVariant = (index: number) => {
  if (variants.value.length > 1) {
    variants.value.splice(index, 1)
  }
}

const loadTemplate = async () => {
  if (!route.params.id) return

  loading.value = true
  try {
    const template: Template = await TemplateService.show(route.params.id as string)
    templateName.value = template.name
    outputFormat.value = template.query.output_format ?? 'hls'
    variants.value = template.query.variants.length > 0
      ? template.query.variants
      : [{}]
    audioConfig.value = template.query.audio ?? { channels: [{ channels: '', audio_bitrate: '' }] }
  } catch (error) {
    console.error('Error loading template:', error)
  } finally {
    loading.value = false
  }
}

const saveTemplate = async () => {
  if (!templateName.value.trim()) {
    toast.info('Please enter a template name')
    return
  }

  if (variants.value.length === 0 || !variants.value[0]?.video_codec) {
    toast.info('Please configure at least one variant with a video codec')
    return
  }

  if (!audioConfig.value.audio_codec) {
    toast.info('Please select an audio codec')
    return
  }

  if (!audioConfig.value.channels || audioConfig.value.channels.length === 0) {
    toast.info('Please configure at least one audio channel')
    return
  }

  saving.value = true
  try {
    const data: CreateTemplateDto | UpdateTemplateDto = {
      name: templateName.value,
      query: {
        output_format: outputFormat.value,
        variants: variants.value,
        audio: audioConfig.value
      }
    }

    if (isEdit.value) {
      const res = await TemplateService.update(route.params.id as string, data)
      toast.success(res.data.message)
    } else {
      await TemplateService.store(data)
      router.push({ name: 'Templates' })
    }

  } catch (error) {
    if (error instanceof ApiException) {
      toast.error(error.message)
    }
    console.error(error)
  } finally {
    saving.value = false
  }
}

const goBack = () => {
  router.push({ name: 'Templates' })
}

onMounted(async () => {
  await fetchConfig()
  if (isEdit.value) {
    await loadTemplate()
  }
})
</script>

<template>
  <div class="flex flex-col gap-6 p-6 max-w-7xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-4">
        <Button variant="ghost" size="icon" @click="goBack">
          <ArrowLeft :size="20" />
        </Button>
        <div>
          <h1 class="text-3xl font-bold">{{ pageTitle }}</h1>
          <p class="text-muted-foreground">
            Configure encoding parameters for your video templates
          </p>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="configLoading || loading" class="flex items-center justify-center py-12">
      <Spinner class="mr-2" />
      <span>Loading configuration...</span>
    </div>

    <!-- Template Form -->
    <div v-else-if="config" class="space-y-6">
      <!-- Template Name -->
      <Card>
        <CardHeader>
          <CardTitle>Template Information</CardTitle>
        </CardHeader>
        <CardContent>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="space-y-2">
              <Label for="template-name">Template Name *</Label>
              <Input id="template-name" v-model="templateName" placeholder="e.g., High Quality Web, Mobile Optimized" />
            </div>
            <div class="space-y-2">
              <Label for="output-format">Output Format</Label>
              <Select v-model="outputFormat">
                <SelectTrigger>
                  <SelectValue placeholder="Select format" />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem value="hls">HLS (Streaming)</SelectItem>
                    <SelectItem value="mp4">MP4</SelectItem>
                    <SelectItem value="mkv">MKV</SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Video Variants -->
      <div class="space-y-4">
        <div>
          <h2 class="text-xl font-semibold">Quality Variants</h2>
          <p class="text-sm text-muted-foreground">
            Add multiple quality variants for this template (e.g., 1080p, 720p, 480p)
          </p>
        </div>

        <div class="space-y-4">
          <VariantForm v-for="(variant, index) in variants" :key="index" :model-value="variant"
            @update:model-value="variants[index] = $event" :config="config" :index="index"
            @remove="removeVariant(index)" />
        </div>

        <Button v-if="!isMuxedFormat" @click="addVariant" variant="outline" class="w-full border-dashed">
          <Plus :size="16" class="mr-2" />
          Add Variant
        </Button>
      </div>

      <!-- Audio Configuration -->
      <AudioChannelForm v-if="config" :model-value="audioConfig"
        @update:model-value="audioConfig = $event" :config="config" />

      <!-- Save Button (Bottom) -->
      <div class="flex justify-end gap-2 pt-4 border-t">
        <Button variant="outline" @click="goBack">
          Cancel
        </Button>
        <Button @click="saveTemplate" :disabled="saving">
          <Save :size="16" class="mr-2" />
          {{ saving ? 'Saving...' : 'Save Template' }}
        </Button>
      </div>
    </div>

    <!-- Error State -->
    <div v-else class="text-center py-12 text-destructive">
      Failed to load configuration. Please refresh the page.
    </div>
  </div>
</template>
