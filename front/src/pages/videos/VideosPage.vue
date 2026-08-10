<script setup lang="ts">
import Badge from '@/components/ui/badge/Badge.vue';
import Input from '@/components/ui/input/Input.vue';
import Spinner from '@/components/ui/spinner/Spinner.vue';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import UploadButton from '@/components/upload/UploadButton.vue';
import { FileVideo } from '@lucide/vue';
import { ref, onMounted, onUnmounted } from 'vue';
import VideoService from '@/services/VideoService';
import { useUploadStore } from '@/stores/upload';
type Video = App.Data.VideoData
import type { Pagination as ResPagination } from '@/types/Pagination';
import {
  Pagination,
  PaginationContent,
  PaginationItem,
  PaginationNext,
  PaginationPrevious,
} from '@/components/ui/pagination'
import { formatSecondsToTime } from '@/utils/timeFormatter';

const uploadStore = useUploadStore();

const videos = ref<ResPagination<Video>>({ currentPage: 1, data: [], perPage: 15, total: 0 });
const loading = ref(true);
const page = ref(1);
const search = ref('');
let searchTimeout: ReturnType<typeof setTimeout>;

// Typing fast or paging fast issues overlapping requests, and the last one to ARRIVE used to win
// rather than the last one issued — the table could end up showing results for a query the input
// no longer holds. Only the newest request is allowed to write.
let latestRequest = 0;

const fetchVideos = async (options: { silent?: boolean } = {}) => {
  const requestId = ++latestRequest;

  try {
    if (!options.silent) loading.value = true;

    const result = await VideoService.index({
      page: page.value,
      search: search.value || undefined,
    });

    if (requestId === latestRequest) videos.value = result;
  } catch (error) {
    console.error('Error fetching videos:', error);
  } finally {
    if (requestId === latestRequest) loading.value = false;
  }
};

const terminalStatuses: App.Enums.VideoStatus[] = ['completed', 'failed'];

/** A video is created by the upload webhook and then walks its status machine on the workers, so
 *  the table only ever reflected the moment it mounted: a just-uploaded video never appeared and
 *  a running one never advanced without a manual reload. */
const hasVideosInFlight = () =>
  videos.value.data.some(video => !terminalStatuses.includes(video.status));

let refreshInterval: ReturnType<typeof setInterval> | null = null;

const stopPolling = () => {
  if (refreshInterval) {
    clearInterval(refreshInterval);
    refreshInterval = null;
  }
};

const onSearch = () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    page.value = 1;
    fetchVideos();
  }, 300);
};

const onPageChange = (newPage: number) => {
  page.value = newPage;
  fetchVideos();
};

const formatDate = (dateString: string): string => {
  const date = new Date(dateString);
  return date.toLocaleDateString();
};

/**
 * Ticks still owed to a just-finished upload. The video only exists once the bucket notification
 * has landed AND `OnVideoUploaded` has run off the `default` queue, which is not a fixed delay —
 * and until the row exists, `hasVideosInFlight()` is false, so the normal poll would not look for
 * it. A bounded window of forced refreshes covers the gap without polling forever.
 */
const FORCED_REFRESH_TICKS = 12;

let forcedRefreshTicks = 0;

const onUploadSuccess = () => {
  forcedRefreshTicks = FORCED_REFRESH_TICKS;
};

onMounted(async () => {
  await fetchVideos();

  // Silent refreshes: flipping the page skeleton on every tick would make the table unusable.
  refreshInterval = setInterval(() => {
    if (forcedRefreshTicks > 0) forcedRefreshTicks--;

    if (forcedRefreshTicks > 0 || hasVideosInFlight()) fetchVideos({ silent: true });
  }, 5000);

  uploadStore.uppy.on('upload-success', onUploadSuccess);
});

onUnmounted(() => {
  stopPolling();
  clearTimeout(searchTimeout);
  uploadStore.uppy.off('upload-success', onUploadSuccess);
});
</script>

<template>
  <div class="flex flex-col gap-4 p-4">
    <div class="flex justify-end">
      <UploadButton />
    </div>
    <Input v-model="search" placeholder="Search videos..." @input="onSearch" />
    <div class="overflow-hidden rounded-lg border">
      <Table>
        <TableHeader class="bg-muted sticky top-0 z-10">
          <TableRow>
            <TableHead class="w-20"></TableHead>
            <TableHead>Title</TableHead>
            <TableHead>Duration</TableHead>
            <TableHead>Status</TableHead>
            <TableHead>Created</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-if="loading">
            <TableCell colspan="5" class="text-center">
              <Spinner />
              Loading videos...
            </TableCell>
          </TableRow>
          <TableRow v-else-if="videos.data.length === 0">
            <TableCell colspan="5" class="text-center text-muted-foreground">
              No videos found
            </TableCell>
          </TableRow>
          <TableRow v-else v-for="video in videos.data" :key="video.ulid">
            <TableCell>
              <div class="w-16 h-10 rounded bg-muted flex items-center justify-center overflow-hidden">
                <img
                  v-if="video.thumbnailUrl"
                  :src="video.thumbnailUrl"
                  :alt="video.name"
                  class="w-full h-full object-cover"
                  @error="($event.target as HTMLImageElement).style.display = 'none'"
                />
                <FileVideo v-if="!video.thumbnailUrl" class="w-4 h-4 text-muted-foreground" />
              </div>
            </TableCell>
            <TableCell>
              <RouterLink :to="`/videos/${video.ulid}`">{{ video.name }}</RouterLink>
            </TableCell>
            <TableCell>{{ formatSecondsToTime(video.duration) }}</TableCell>
            <TableCell>
              <Badge :variant="video.status === 'completed' ? 'default' : 'outline'">
                <Spinner v-if="video.status === 'running'" />
                {{ video.status }}
              </Badge>
              <span v-if="video.status === 'running' && video.outputs.length" class="ml-2 text-xs text-muted-foreground">
                {{ Math.round(video.outputs.reduce((sum, o) => sum + o.progress, 0) / video.outputs.length) }}%
              </span>
            </TableCell>
            <TableCell>{{ formatDate(video.createdAt) }}</TableCell>
          </TableRow>
        </TableBody>
      </Table>

      <div v-if="videos.total > videos.perPage" class="py-4">
        <Pagination v-slot="{ page: currentPage }" :items-per-page="videos.perPage" :total="videos.total" :default-page="page" @update:page="onPageChange">
          <PaginationContent v-slot="{ items }">
            <PaginationPrevious />
            <template v-for="(item, index) in items" :key="index">
              <PaginationItem v-if="item.type === 'page'" :value="item.value" :is-active="item.value === currentPage">
                {{ item.value }}
              </PaginationItem>
            </template>
            <PaginationNext />
          </PaginationContent>
        </Pagination>
      </div>
    </div>
  </div>
</template>
