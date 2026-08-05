<script setup lang="ts">
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Switch } from '@/components/ui/switch'
import { ref } from 'vue'
import StreamService from '@/services/StreamService'
type Stream = App.Data.StreamData
import { ApiException } from '@/exceptions/ApiException'
import { ValidationException } from '@/exceptions/ValidationException'
import { toast } from 'vue-sonner'

const emit = defineEmits<{ updated: [stream: Stream] }>()

const dialogOpen = ref(false)
const loading = ref(false)
const errors = ref<Record<string, string[]>>({})

const ulid = ref('')
const isSubtitle = ref(false)
const form = ref({
  name: '',
  language: '',
  forced: false,
  hearingImpaired: false,
})

const show = (stream: Stream) => {
  ulid.value = stream.ulid
  isSubtitle.value = stream.type === 'subtitle'
  form.value = {
    // Only audio/subtitle tracks reach this dialog; video renditions are the unnamed ones.
    name: stream.name ?? '',
    language: stream.language ?? '',
    forced: stream.forced,
    hearingImpaired: stream.hearingImpaired,
  }
  errors.value = {}
  dialogOpen.value = true
}

const handleUpdate = async () => {
  errors.value = {}
  loading.value = true

  try {
    const updated = await StreamService.update(ulid.value, {
      name: form.value.name,
      language: form.value.language.trim() || null,
      forced: isSubtitle.value && form.value.forced,
      // Audio SDH is baked into the packaged manifests; the API only lets subtitles change it.
      hearingImpaired: isSubtitle.value ? form.value.hearingImpaired : null,
    })
    dialogOpen.value = false
    emit('updated', updated)
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    } else if (error instanceof ApiException) {
      toast.error(error.message)
    }
  } finally {
    loading.value = false
  }
}

defineExpose({ show })
</script>

<template>
  <Dialog v-model:open="dialogOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Edit track</DialogTitle>
        <DialogDescription>
          Applies to the published DASH and HLS manifests, without re-encoding.
        </DialogDescription>
      </DialogHeader>
      <form @submit.prevent="handleUpdate" class="grid gap-4">
        <div class="grid gap-2">
          <Label for="edit_stream_name">Name</Label>
          <Input id="edit_stream_name" v-model="form.name" required />
          <p class="text-xs text-muted-foreground">Shown in the player's track selector.</p>
          <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name[0] }}</p>
        </div>
        <div class="grid gap-2">
          <Label for="edit_stream_language">Language</Label>
          <Input id="edit_stream_language" v-model="form.language" placeholder="es, en-US, es-MX" />
          <p v-if="errors.language" class="text-sm text-destructive">{{ errors.language[0] }}</p>
        </div>
        <div v-if="isSubtitle" class="flex items-center justify-between">
          <div>
            <Label for="edit_stream_forced">Forced</Label>
            <p class="text-xs text-muted-foreground">For foreign-language dialogue only.</p>
          </div>
          <Switch id="edit_stream_forced" v-model="form.forced" />
        </div>
        <div v-if="isSubtitle" class="flex items-center justify-between">
          <div>
            <Label for="edit_stream_sdh">SDH</Label>
            <p class="text-xs text-muted-foreground">Subtitles for the deaf and hard of hearing.</p>
          </div>
          <Switch id="edit_stream_sdh" v-model="form.hearingImpaired" />
          <p v-if="errors.hearingImpaired" class="text-sm text-destructive">
            {{ errors.hearingImpaired[0] }}
          </p>
        </div>
        <DialogFooter>
          <Button type="submit" :disabled="loading">
            {{ loading ? 'Saving...' : 'Save Changes' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
