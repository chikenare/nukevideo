<script setup lang="ts">
import 'vidstack/bundle';

import { ApiException } from '@/exceptions/ApiException';
import VideoService from '@/services/VideoService';
import type { Video } from '@/types/Video';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { PlayIcon } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

const { video, outputUlid } = defineProps<{ video: Video, outputUlid: string }>()

const isPlaying = ref(false)
const videoUrl = ref<string | null>(null)
const isLoadingVideo = ref(false)

const handlePlayVideo = async () => {
  if (isPlaying.value) {
    isPlaying.value = false
    videoUrl.value = null
    return
  }

  try {
    isLoadingVideo.value = true
    const sources = await VideoService.getVideoSources(video.ulid)
    const url = sources.find(e => e.ulid == outputUlid)?.url

    if (url) {
      videoUrl.value = url
    }
    isPlaying.value = true
  } catch (e) {
    if (e instanceof ApiException) {
      toast.error(e.message)
    } else {
      toast.error('Failed to load video')
    }
    console.error(e)
  } finally {
    isLoadingVideo.value = false
  }
}

</script>

<template>
  <div
    class="aspect-video relative bg-black rounded-t-lg flex items-center justify-center overflow-hidden border-2 rounded-xl">
    <template v-if="isPlaying && videoUrl">
      <media-player :title="video.name" :src="videoUrl" crossorigin="anonymous" class="w-full h-full" autoplay>
        <media-provider />
        <media-video-layout :thumbnails="video.storyboardUrl" />
      </media-player>
    </template>
    <template v-if="video.status == 'completed' && !isPlaying && !videoUrl">
      <img v-if="video.thumbnailUrl" :src="video.thumbnailUrl" class="object-contain w-full h-full" alt="">
      <Button class="rounded-full absolute" variant="ghost" @click="handlePlayVideo"
        :disabled="isLoadingVideo || video.status !== 'completed'">
        <PlayIcon v-if="!isLoadingVideo" />
        <span v-else class="animate-spin">⏳</span>
      </Button>
    </template>
    <template v-else>
      <Spinner class="text-secondary size-10" />
    </template>
  </div>
</template>
