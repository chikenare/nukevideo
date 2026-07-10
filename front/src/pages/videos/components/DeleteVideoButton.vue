<script setup lang="ts">
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import { ApiException } from '@/exceptions/ApiException'
import VideoService from '@/services/VideoService'
import { Trash2 } from '@lucide/vue'
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { toast } from 'vue-sonner'

const router = useRouter()

const { id } = defineProps<{ id: string }>()

const loading = ref(false)

const handleDeleteVideo = async () => {
  loading.value = true
  try {
    await VideoService.destroy(id)
    router.replace('/videos')
  } catch (e) {
    if (e instanceof ApiException) toast.error(e.message)
    console.error(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AlertDialog>
    <AlertDialogTrigger as-child>
      <Button variant="destructive" size="icon" :disabled="loading" title="Delete video">
        <Spinner v-if="loading" />
        <Trash2 v-else :size="16" />
      </Button>
    </AlertDialogTrigger>
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Delete video</AlertDialogTitle>
        <AlertDialogDescription>
          This action cannot be undone. The video and all its packaged assets will be permanently deleted.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>Cancel</AlertDialogCancel>
        <AlertDialogAction :disabled="loading" @click="handleDeleteVideo">Delete</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
