<script setup lang="ts">
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import {
  AlertDialog,
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
import { RotateCcw } from '@lucide/vue'
import { ref } from 'vue'
import { toast } from 'vue-sonner'

const { id } = defineProps<{ id: string }>()

/** The requeued video, so the page can swap it in and re-arm its poll without a second request. */
const emit = defineEmits<{ retried: [App.Data.VideoData] }>()

const open = ref(false)
const loading = ref(false)

const handleRetry = async () => {
  loading.value = true
  try {
    const res = await VideoService.retry(id)
    toast.success(res.data.message)
    emit('retried', res.data.data)
    open.value = false
  } catch (e) {
    // A retry is refused for reasons the operator can act on — the source is gone, the previous
    // run still has jobs in flight — so the API's own message is what has to reach them.
    if (e instanceof ApiException) toast.error(e.message)
    console.error(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AlertDialog v-model:open="open">
    <AlertDialogTrigger as-child>
      <Button variant="outline" size="icon" :disabled="loading" title="Retry encoding">
        <Spinner v-if="loading" />
        <RotateCcw v-else :size="16" />
      </Button>
    </AlertDialogTrigger>
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Retry encoding</AlertDialogTitle>
        <AlertDialogDescription>
          The video goes back to the queue and is picked up by the next free worker. Whatever the
          failed run had already encoded is reused, so a video that died near the end comes back in
          minutes.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>Cancel</AlertDialogCancel>
        <!-- A plain Button, not AlertDialogAction: that one dismisses the dialog on click, and a
             refused retry (409) would close it before the toast explains why. -->
        <Button :disabled="loading" @click="handleRetry">
          <Spinner v-if="loading" />
          Retry
        </Button>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
