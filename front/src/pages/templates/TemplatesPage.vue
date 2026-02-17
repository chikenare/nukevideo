<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Button } from '@/components/ui/button'
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
import { Edit, MoreVertical, Plus } from 'lucide-vue-next'
import DeleteTemplateButton from './components/DeleteTemplateButton.vue'

const router = useRouter()
const templates = ref<Template[]>([])
const loading = ref(true)

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

const formatDate = (dateString?: string): string => {
  if (!dateString) return 'N/A'
  const date = new Date(dateString)
  return date.toLocaleDateString()
}

const getVariantsInfo = (query: Template['query']): string => {
  const count = query.variants?.length || 0
  if (count === 0) return 'No variants'
  if (count === 1) return '1 quality'
  return `${count} qualities`
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
})
</script>

<template>
  <div class="flex flex-col gap-4 p-4">
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

    <!-- Table -->
    <div class="overflow-hidden rounded-lg border">
      <Table>
        <TableHeader class="bg-muted sticky top-0 z-10">
          <TableRow>
            <TableHead>Name</TableHead>
            <TableHead>Configuration</TableHead>
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
              <div class="text-sm">
                <div class="font-medium">{{ getVariantsInfo(template.query) }}</div>
              </div>
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
  </div>
</template>
