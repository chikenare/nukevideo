<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Separator } from '@/components/ui/separator'
import { Switch } from '@/components/ui/switch'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import VariantForm from './components/VariantForm.vue'
import AudioChannelForm from './components/AudioChannelForm.vue'
import { useCodecConfig } from '@/composables/useCodecConfig'
import TemplateService from '@/services/TemplateService'
import type { Template, TemplateOutput, CreateTemplateDto, UpdateTemplateDto } from '@/types/Template'
import { Plus, Save, ArrowLeft, Trash2 } from '@lucide/vue'
import { ApiException } from '@/exceptions/ApiException'
import { toast } from 'vue-sonner'

const router = useRouter()
const route = useRoute()
const { config, loading: configLoading, fetchConfig } = useCodecConfig()

const isEdit = computed(() => !!route.params.id)
const pageTitle = computed(() => isEdit.value ? 'Edit Template' : 'Create Template')

const templateName = ref('')
const keepProcessedFiles = ref(true)
const outputs = ref<TemplateOutput[]>([
  { videoCodec: '', variants: [{}], audio: { channels: [{ channels: '', audioBitrate: '' }] } }
])

const videoCodecs = computed(() => config.value?.codecs.filter(c => c.type === 'video') ?? [])
const loading = ref(false)
const saving = ref(false)

// --- Outputs ---
const addOutput = () => {
  outputs.value.push({
    videoCodec: '',
    variants: [{}],
    audio: { channels: [{ channels: '', audioBitrate: '' }] },
  })
}

const removeOutput = (index: number) => {
  if (outputs.value.length > 1) {
    outputs.value.splice(index, 1)
  }
}

const addVariant = (outputIndex: number) => {
  const output = outputs.value[outputIndex]
  if (output) output.variants.push({})
}

const removeVariant = (outputIndex: number, variantIndex: number) => {
  const output = outputs.value[outputIndex]
  if (output && output.variants.length > 1) {
    output.variants.splice(variantIndex, 1)
  }
}
// --- Load / Save ---
const loadTemplate = async () => {
  if (!route.params.id) return

  loading.value = true
  try {
    const template: Template = await TemplateService.show(route.params.id as string)
    templateName.value = template.name
    keepProcessedFiles.value = template.keepProcessedFiles ?? true

    if (template.query.outputs && template.query.outputs.length > 0) {
      outputs.value = template.query.outputs
    }
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

  for (const [index, output] of outputs.value.entries()) {
    if (!output.videoCodec) {
      toast.info(`Output ${index + 1}: please select a video codec`)
      return
    }
    if (!output.variants || output.variants.length === 0 || !output.variants[0]?.width) {
      toast.info(`Output ${index + 1}: please configure at least one variant`)
      return
    }
  }

  for (const [index, output] of outputs.value.entries()) {
    if (!output.audio?.audioCodec) {
      toast.info(`Output ${index + 1}: please configure the audio track`)
      return
    }
  }

  saving.value = true
  try {
    const data: CreateTemplateDto | UpdateTemplateDto = {
      name: templateName.value,
      keepProcessedFiles: keepProcessedFiles.value,
      commands: [],
      query: {
        outputs: outputs.value,
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
  <div class="flex flex-col gap-6 p-6 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-4">
        <Button variant="ghost" size="icon" @click="goBack">
          <ArrowLeft :size="20" />
        </Button>
        <div>
          <h1 class="text-2xl font-bold">{{ pageTitle }}</h1>
          <p class="text-sm text-muted-foreground">
            Configure encoding parameters for your video template
          </p>
        </div>
      </div>
      <div class="flex gap-2">
        <Button variant="outline" @click="goBack">Cancel</Button>
        <Button @click="saveTemplate" :disabled="saving">
          <Save :size="16" class="mr-2" />
          {{ saving ? 'Saving...' : 'Save Template' }}
        </Button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="configLoading || loading" class="flex items-center justify-center py-16">
      <Spinner class="mr-2" />
      <span>Loading configuration...</span>
    </div>

    <!-- Template Form -->
    <div v-else-if="config" class="space-y-6">

      <!-- Template Name -->
      <Card>
        <CardHeader>
          <CardTitle class="text-base">Template Name</CardTitle>
        </CardHeader>
        <CardContent>
          <div class="space-y-2">
            <Label for="template-name">Name *</Label>
            <Input
              id="template-name"
              v-model="templateName"
              placeholder="e.g., High Quality Web, Mobile Optimized"
              class="max-w-sm"
            />
          </div>

          <div class="flex items-start gap-3 pt-4">
            <Switch
              id="keep-processed-files"
              v-model="keepProcessedFiles"
              @update:checked="keepProcessedFiles = $event"
            />
            <div class="space-y-0.5">
              <Label for="keep-processed-files">Keep processed files</Label>
              <p class="text-sm text-muted-foreground">
                Retain the encoded video/audio renditions after packaging. Turn off to keep only the
                packaged stream and save storage.
              </p>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Outputs -->
      <div class="space-y-4">
        <div>
          <h2 class="text-lg font-semibold">Outputs</h2>
          <p class="text-sm text-muted-foreground">
            Each output is a codec with multiple quality variants. HLS and DASH manifests are
            generated automatically for whichever the codecs support.
          </p>
        </div>

        <div v-for="(output, outputIndex) in outputs" :key="outputIndex">
          <Card>
            <CardHeader class="pb-4">
              <div class="flex items-center justify-between">
                <CardTitle class="text-base">
                  Output {{ outputIndex + 1 }}
                </CardTitle>
                <Button
                  v-if="outputs.length > 1"
                  variant="ghost"
                  size="icon"
                  class="h-8 w-8"
                  @click="removeOutput(outputIndex)"
                >
                  <Trash2 :size="15" class="text-destructive" />
                </Button>
              </div>
            </CardHeader>
            <CardContent class="space-y-5">
              <!-- Video Codec (per output: variants share it) -->
              <div class="space-y-2">
                <Label>Video Codec *</Label>
                <Select v-model="output.videoCodec" class="max-w-xs">
                  <SelectTrigger class="max-w-xs">
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

              <Separator />

              <!-- Quality Variants -->
              <div class="space-y-3">
                <div class="flex items-center justify-between">
                  <div>
                    <p class="text-sm font-medium">Quality Variants</p>
                    <p class="text-xs text-muted-foreground">e.g., 1080p, 720p, 480p</p>
                  </div>
                  <Button @click="addVariant(outputIndex)" variant="outline" size="sm">
                    <Plus :size="14" class="mr-1.5" />
                    Add Variant
                  </Button>
                </div>

                <div class="space-y-2">
                  <VariantForm
                    v-for="(variant, variantIndex) in output.variants"
                    :key="variantIndex"
                    :model-value="variant"
                    @update:model-value="output.variants[variantIndex] = $event"
                    :config="config"
                    :index="variantIndex"
                    :video-codec="output.videoCodec"
                    @remove="removeVariant(outputIndex, variantIndex)"
                  />
                </div>
              </div>

              <Separator />

              <!-- Audio Track -->
              <div class="space-y-3">
                <div>
                  <p class="text-sm font-medium">Audio Track</p>
                  <p class="text-xs text-muted-foreground">Configure audio codec and channel layouts</p>
                </div>

                <AudioChannelForm
                  :model-value="output.audio"
                  @update:model-value="output.audio = $event"
                  :config="config"
                  :video-codec="output.videoCodec"
                />
              </div>
            </CardContent>
          </Card>
        </div>

        <Button
          @click="addOutput"
          variant="outline"
          class="w-full border-dashed"
        >
          <Plus :size="16" class="mr-2" />
          Add Output
        </Button>
      </div>



    </div>

    <!-- Error State -->
    <div v-else class="text-center py-12 text-destructive">
      Failed to load configuration. Please refresh the page.
    </div>
  </div>
</template>
