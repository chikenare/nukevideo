<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { ApiException } from '@/exceptions/ApiException';
import VideoService from '@/services/VideoService';
import { Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { toast } from 'vue-sonner'


const router = useRouter()


const { id } = defineProps<{
  id: string
}>()

const loading = ref(false)

const handleDeleteVideo = async () => {
  if (!confirm('Are you sure you want to delete this video?')) return
  loading.value = true

  try {
    await VideoService.destroy(id)
    router.replace('/videos')
  } catch (e) {
    if (e instanceof ApiException) {
      console.log('toast')
      toast.error(e.message)
    }
    console.error(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Button variant="destructive" size="icon" :disabled="loading" @click="handleDeleteVideo" title="Eliminar video">
    <Spinner v-if="loading" />
    <Trash2 v-else :size="16" />
  </Button>
</template>
