<script setup lang="ts">
import { computed } from 'vue'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { formatBytes } from '@/utils/byteFormatter'

/**
 * A stored footprint as one number, with the split behind a tooltip: `served` is the CMAF package
 * that streams, `retained` the processed rendition kept beside it when the template sets
 * `keep_processed_files`. Without anything retained there is nothing to explain and the tooltip
 * is skipped.
 */
const { served, retained } = defineProps<{
  served?: number | null
  retained?: number | null
}>()

const total = computed(() => formatBytes((served ?? 0) + (retained ?? 0)))
</script>

<template>
  <TooltipProvider v-if="retained">
    <Tooltip>
      <TooltipTrigger as="span" class="cursor-help underline decoration-dotted underline-offset-4">
        {{ total }}
      </TooltipTrigger>
      <TooltipContent>
        <p>{{ formatBytes(served) }} streaming · {{ formatBytes(retained) }} stored files</p>
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
  <span v-else>{{ total }}</span>
</template>
