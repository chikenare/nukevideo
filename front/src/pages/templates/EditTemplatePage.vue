<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Separator } from '@/components/ui/separator'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import VariantForm from './components/VariantForm.vue'
import AudioChannelForm from './components/AudioChannelForm.vue'
import { useCodecConfig } from '@/composables/useCodecConfig'
import TemplateService from '@/services/TemplateService'
import type { Template, TemplateOutput, CreateTemplateDto, UpdateTemplateDto } from '@/types/Template'
import type { OutputFormat } from '@/types/Output'
import { Plus, Save, ArrowLeft, Trash2 } from 'lucide-vue-next'
import { ApiException } from '@/exceptions/ApiException'
import { toast } from 'vue-sonner'

const router = useRouter()
const route = useRoute()
const { config, loading: configLoading, fetchConfig } = useCodecConfig()

const isEdit = computed(() => !!route.params.id)
const pageTitle = computed(() => isEdit.value ? 'Edit Template' : 'Create Template')

const templateName = ref('')
const outputs = ref<TemplateOutput[]>([
  { format: 'hls' as OutputFormat, variants: [{}], audio: { channels: [{ channels: '', audioBitrate: '' }] } }
])
const loading = ref(false)
const saving = ref(false)

const availableFormats = computed(() => {
  if (!config.value?.formats) return []
  return Object.entries(config.value.formats).map(([key, fmt]) => ({
    value: key,
    label: fmt.label,
    description: fmt.description,
  }))
})

// --- Outputs ---
const addOutput = () => {
  const usedFormats = outputs.value.map(o => o.format)
  const nextFormat = availableFormats.value.find(f => !usedFormats.includes(f.value as OutputFormat))
  outputs.value.push({
    format: (nextFormat?.value ?? 'hls') as OutputFormat,
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

watch(outputs, (newOutputs) => {
  for (const output of newOutputs) {
    if (output.format === 'mp4' && output.variants.length > 1) {
      output.variants.splice(1)
    }
  }
}, { deep: true })


// --- Load / Save ---
const loadTemplate = async () => {
  if (!route.params.id) return

  loading.value = true
  try {
    const template: Template = await TemplateService.show(route.params.id as string)
    templateName.value = template.name

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
    if (!output.variants || output.variants.length === 0 || !output.variants[0]?.videoCodec) {
      toast.info(`Output ${index + 1} (${output.format.toUpperCase()}): please configure at least one variant with a video codec`)
      return
    }
  }

  for (const [index, output] of outputs.value.entries()) {
    if (!output.audio?.audioCodec) {
      toast.info(`Output ${index + 1} (${output.format.toUpperCase()}): please configure the audio track`)
      return
    }
  }

  saving.value = true
  try {
    const data: CreateTemplateDto | UpdateTemplateDto = {
      name: templateName.value,
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
        </CardContent>
      </Card>

      <!-- Output Formats -->
      <div class="space-y-4">
        <div>
          <h2 class="text-lg font-semibold">Output Formats</h2>
          <p class="text-sm text-muted-foreground">
            Each output format can have multiple quality variants.
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
              <!-- Format Selection -->
              <div class="space-y-2">
                <Label>Format *</Label>
                <Select v-model="output.format" class="max-w-xs">
                  <SelectTrigger class="max-w-xs">
                    <SelectValue placeholder="Select format" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="fmt in availableFormats" :key="fmt.value" :value="fmt.value">
                      <div>
                        <div class="font-medium">{{ fmt.label }}</div>
                        <div class="text-xs text-muted-foreground">{{ fmt.description }}</div>
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
                  <Button v-if="output.format !== 'mp4'" @click="addVariant(outputIndex)" variant="outline" size="sm">
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
                    :format="output.format"
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
                  :format="output.format"
                />
              </div>
            </CardContent>
          </Card>
        </div>

        <Button
          v-if="availableFormats.length > outputs.length"
          @click="addOutput"
          variant="outline"
          class="w-full border-dashed"
        >
          <Plus :size="16" class="mr-2" />
          Add Output Format
        </Button>
      </div>



    </div>

    <!-- Error State -->
    <div v-else class="text-center py-12 text-destructive">
      Failed to load configuration. Please refresh the page.
    </div>
  </div>
</template>
