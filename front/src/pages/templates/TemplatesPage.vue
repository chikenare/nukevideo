<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import TemplateService from '@/services/TemplateService'
import type { Template } from '@/types/Template'
import type { TemplatePreset } from '@/types/Template'
import { Edit, MoreVertical, Plus, Download } from 'lucide-vue-next'
import DeleteTemplateButton from './components/DeleteTemplateButton.vue'
import { toast } from 'vue-sonner'

const router = useRouter()
const templates = ref<Template[]>([])
const presets = ref<TemplatePreset[]>([])
const loading = ref(true)
const loadingPresets = ref(true)
const adoptingSlug = ref<string | null>(null)

const fetchTemplates = async () => {
  try {
    loading.value = true
    templates.value = await TemplateService.index()
  } catch (error) {
    console.error('Error fetching templates:', error)
  } finally {
    loading.value = false
  }
}

const fetchPresets = async () => {
  try {
    loadingPresets.value = true
    presets.value = await TemplateService.presets()
  } catch (error) {
    console.error('Error fetching presets:', error)
  } finally {
    loadingPresets.value = false
  }
}

const adoptPreset = async (slug: string) => {
  try {
    adoptingSlug.value = slug
    await TemplateService.adoptPreset(slug)
    toast.success('Template added to your collection')
    await fetchTemplates()
  } catch (error) {
    console.error('Error adopting preset:', error)
    toast.error('Failed to add template')
  } finally {
    adoptingSlug.value = null
  }
}

const formatDate = (dateString?: string): string => {
  if (!dateString) return 'N/A'
  const date = new Date(dateString)
  return date.toLocaleDateString()
}

const formatVariantsSummary = (preset: TemplatePreset): string => {
  const output = preset.query.outputs[0]
  if (!output) return ''
  const resolutions = output.variants.map((v: Record<string, unknown>) => v.resolution + 'p')
  return resolutions.join(', ')
}

const formatCodec = (preset: TemplatePreset): string => {
  const output = preset.query.outputs[0]
  if (!output || !output.variants[0]) return ''
  const codec = output.variants[0].video_codec as string
  const labels: Record<string, string> = {
    libx264: 'H.264',
    libx265: 'H.265',
    libsvtav1: 'AV1',
    'libvpx-vp9': 'VP9',
    libvpx: 'VP8',
  }
  return labels[codec] || codec
}

const formatFormat = (preset: TemplatePreset): string => {
  const output = preset.query.outputs[0]
  return output?.format?.toUpperCase() || ''
}

const handleEdit = (template: Template) => {
  router.push({ name: 'EditTemplate', params: { id: template.ulid } })
}

const handleCreate = () => {
  router.push({ name: 'CreateTemplate' })
}

const handleDeleteSuccess = () => {
  fetchTemplates()
}

onMounted(() => {
  fetchTemplates()
  fetchPresets()
})
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <!-- Header -->
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold">Templates</h1>
        <p class="text-muted-foreground">Manage your encoding templates</p>
      </div>
      <Button @click="handleCreate">
        <Plus :size="16" class="mr-2" />
        Create Template
      </Button>
    </div>

    <!-- My Templates Table -->
    <div class="overflow-hidden rounded-lg border">
      <Table>
        <TableHeader class="bg-muted sticky top-0 z-10">
          <TableRow>
            <TableHead>Name</TableHead>
            <TableHead>Created</TableHead>
            <TableHead class="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-if="loading">
            <TableCell colspan="4" class="text-center">
              <Spinner />
              Loading templates...
            </TableCell>
          </TableRow>
          <TableRow v-else-if="templates.length === 0">
            <TableCell colspan="4" class="text-center text-muted-foreground py-8">
              <div class="flex flex-col items-center gap-2">
                <p>No templates found</p>
                <Button variant="outline" size="sm" @click="handleCreate">
                  <Plus :size="14" class="mr-2" />
                  Create your first template
                </Button>
              </div>
            </TableCell>
          </TableRow>
          <TableRow v-else v-for="template in templates" :key="template.ulid" class="cursor-pointer hover:bg-muted/50">
            <TableCell class="font-medium">
              {{ template.name }}
            </TableCell>
            <TableCell>
              {{ formatDate(template.createdAt) }}
            </TableCell>
            <TableCell class="text-right">
              <DropdownMenu>
                <DropdownMenuTrigger as-child>
                  <Button variant="ghost" size="icon">
                    <MoreVertical :size="16" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem @click="handleEdit(template)">
                    <Edit :size="16" class="mr-2" />
                    Edit
                  </DropdownMenuItem>
                  <DeleteTemplateButton :template="template" @deleted="handleDeleteSuccess" />
                </DropdownMenuContent>
              </DropdownMenu>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <!-- Presets Section -->
    <div class="flex flex-col gap-4">
      <div>
        <h2 class="text-xl font-semibold">Presets</h2>
        <p class="text-muted-foreground text-sm">Ready-to-use encoding templates. Add any preset to your collection.</p>
      </div>

      <div v-if="loadingPresets" class="flex justify-center py-8">
        <Spinner />
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <Card v-for="preset in presets" :key="preset.slug">
          <CardHeader class="pb-3">
            <div class="flex items-start justify-between">
              <CardTitle class="text-base">{{ preset.name }}</CardTitle>
              <Badge variant="secondary" class="text-xs">{{ preset.category }}</Badge>
            </div>
            <CardDescription>{{ preset.description }}</CardDescription>
          </CardHeader>
          <CardContent class="pb-3">
            <div class="flex flex-wrap gap-2 text-xs">
              <Badge variant="outline">{{ formatFormat(preset) }}</Badge>
              <Badge variant="outline">{{ formatCodec(preset) }}</Badge>
              <Badge variant="outline">{{ formatVariantsSummary(preset) }}</Badge>
            </div>
          </CardContent>
          <CardFooter>
            <Button
              variant="outline"
              size="sm"
              class="w-full"
              :disabled="adoptingSlug === preset.slug"
              @click="adoptPreset(preset.slug)"
            >
              <Spinner v-if="adoptingSlug === preset.slug" class="mr-2" />
              <Download v-else :size="14" class="mr-2" />
              {{ adoptingSlug === preset.slug ? 'Adding...' : 'Use this template' }}
            </Button>
          </CardFooter>
        </Card>
      </div>
    </div>
  </div>
</template>
