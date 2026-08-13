<script setup lang="ts">
import shaka from 'shaka-player/dist/shaka-player.ui';
import 'shaka-player/dist/controls.css';

import { ApiException } from '@/exceptions/ApiException';
import VideoService from '@/services/VideoService';
type Video = App.Data.VideoData
import { nextTick, onBeforeUnmount, ref, useTemplateRef } from 'vue';
import { toast } from 'vue-sonner';
import { PlayIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

const { video, outputUlid } = defineProps<{ video: Video, outputUlid: string }>()

const isPlaying = ref(false)
const isLoadingVideo = ref(false)
const containerEl = useTemplateRef<HTMLDivElement>('containerEl')
const videoEl = useTemplateRef<HTMLVideoElement>('videoEl')

// shaka ships its own types, but Player/Overlay are awkward to annotate in an SFC — keep them loose.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let player: any = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let ui: any = null

const destroyPlayer = async () => {
  await ui?.destroy()
  await player?.destroy()
  ui = null
  player = null
}

const handlePlayVideo = async () => {
  if (isPlaying.value) {
    await destroyPlayer()
    isPlaying.value = false
    return
  }

  try {
    isLoadingVideo.value = true
    const output = await VideoService.getOutputLink(outputUlid)

    shaka.polyfill.installAll()
    if (!shaka.Player.isBrowserSupported()) {
      toast.error('This browser is not supported by Shaka Player')
      return
    }

    isPlaying.value = true
    await nextTick() // the <video> + container must be in the DOM before shaka attaches

    player = new shaka.Player()
    await player.attach(videoEl.value!)
    ui = new shaka.ui.Overlay(player, containerEl.value!, videoEl.value!)

    // LABEL, not LABEL_OR_LANGUAGE: this decides which tracks the menus list, not just how they are
    // named. Shaka de-duplicates menu entries by a key built from language + roles (+ forced, for
    // text), and `ui/language_utils.js` only adds the label to that key when the format is exactly
    // LABEL — so under LABEL_OR_LANGUAGE any two tracks sharing a language and role collapse into
    // one entry and the rest are dropped silently. A release with four audio tracks and nine
    // subtitles showed three and four: both Spanish dubs became one, and the four Spanish subtitle
    // tracks became one.
    //
    // The trade-off is that a track with no label renders as "?" instead of falling back to its
    // language. Our packager always writes one ({@see PackagerCommandBuilder::descriptorLabel}), and
    // a track that is visible but oddly named beats a track the viewer cannot reach at all.
    ui.configure({
      trackLabelFormat: shaka.ui.Overlay.TrackLabelFormat.LABEL,
      textTrackLabelFormat: shaka.ui.Overlay.TrackLabelFormat.LABEL,
    })

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    player.addEventListener('error', (event: any) => {
      console.error('Shaka Player error', event.detail)
      toast.error(`Playback error (${event.detail?.code ?? 'unknown'})`)
    })

    // The signed manifest URL (DASH .mpd or HLS .m3u8); shaka auto-detects the type. Segments are
    // already tokenised inside the manifest, so no request filter is needed.
    await player.load(output.url)

    if (video.storyboardUrl) {
      // Best-effort thumbnails; ignore if the storyboard track can't be added.
      try { await player.addThumbnailsTrack(video.storyboardUrl, 'text/vtt') } catch { /* optional */ }
    }

    void videoEl.value?.play().catch(() => { /* autoplay may be blocked; UI controls remain */ })
  } catch (e) {
    await destroyPlayer()
    isPlaying.value = false
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

onBeforeUnmount(destroyPlayer)
</script>

<template>
  <div ref="containerEl"
    class="aspect-video relative bg-black flex items-center justify-center overflow-hidden border-2 rounded-xl">
    <video ref="videoEl" v-show="isPlaying" crossorigin="anonymous" class="w-full h-full" />

    <template v-if="!isPlaying">
      <img v-if="video.thumbnailUrl" :src="video.thumbnailUrl" class="object-contain w-full h-full" alt="">
      <Button class="rounded-full absolute" variant="ghost" @click="handlePlayVideo" :disabled="isLoadingVideo">
        <PlayIcon v-if="!isLoadingVideo" />
        <Spinner v-else class="size-4" />
      </Button>
    </template>
    <Spinner v-else-if="isLoadingVideo" class="text-secondary size-10 absolute" />
  </div>
</template>
