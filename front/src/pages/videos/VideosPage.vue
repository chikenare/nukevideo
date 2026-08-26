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
import { FileVideo, ArrowUp, ArrowDown, ArrowUpDown } from '@lucide/vue';
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import VideoService, { type VideoSort } from '@/services/VideoService';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
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
import { formatBytes } from '@/utils/byteFormatter';

const uploadStore = useUploadStore();
const route = useRoute();
const router = useRouter();

const videos = ref<ResPagination<Video>>({ currentPage: 1, data: [], perPage: 15, total: 0 });
const loading = ref(true);

// The page and the query live in the URL (`/videos?q=trailer&page=2`), so a reload, a shared link
// or the back button all land on the list the user was actually looking at.
const page = ref(Math.max(Number(route.query.page) || 1, 1));
const search = ref(typeof route.query.q === 'string' ? route.query.q : '');
let searchTimeout: ReturnType<typeof setTimeout>;

const STATUSES: App.Enums.VideoStatus[] = ['pending', 'downloading', 'running', 'uploading', 'completed', 'failed'];
const SORTS: VideoSort[] = ['created_at', 'name', 'size', 'duration', 'status'];

const isStatus = (value: unknown): value is App.Enums.VideoStatus =>
  typeof value === 'string' && (STATUSES as string[]).includes(value);
const isSort = (value: unknown): value is VideoSort => typeof value === 'string' && (SORTS as string[]).includes(value);

// 'all' rather than '' for the unfiltered choice: the select cannot represent an empty value.
const status = ref<App.Enums.VideoStatus | 'all'>(isStatus(route.query.status) ? route.query.status : 'all');
const sort = ref<VideoSort>(isSort(route.query.sort) ? route.query.sort : 'created_at');
const direction = ref<'asc' | 'desc'>(route.query.dir === 'asc' ? 'asc' : 'desc');

/** Mirror the current state into the URL, leaving the defaults (page 1, no query, newest first) implicit. */
const syncQuery = () => {
  const query: Record<string, string> = {};
  if (search.value) query.q = search.value;
  if (status.value !== 'all') query.status = status.value;
  if (sort.value !== 'created_at') query.sort = sort.value;
  if (direction.value !== 'desc') query.dir = direction.value;
  if (page.value > 1) query.page = String(page.value);

  // replace(), not push(): typing a search must not bury the previous page in the history stack.
  router.replace({ query });
};

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
      status: status.value === 'all' ? undefined : status.value,
      sort: sort.value,
      direction: direction.value,
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
    syncQuery();
    fetchVideos();
  }, 300);
};

const onStatusChange = () => {
  page.value = 1;
  syncQuery();
  fetchVideos();
};

/** Clicking the current column flips the direction; a new column starts descending — the
 *  "biggest / latest first" reading is what a click on Size or Created is usually after. */
const onSort = (column: VideoSort) => {
  if (sort.value === column) {
    direction.value = direction.value === 'desc' ? 'asc' : 'desc';
  } else {
    sort.value = column;
    direction.value = column === 'name' || column === 'status' ? 'asc' : 'desc';
  }
  page.value = 1;
  syncQuery();
  fetchVideos();
};

const onPageChange = (newPage: number) => {
  if (newPage === page.value) return;

  page.value = newPage;
  syncQuery();
  fetchVideos();
};

// Back/forward moves the URL under us; the list follows it. Our own syncQuery() writes the state
// that is already loaded, so the guard below keeps that from firing a second identical request.
watch(() => route.query, (query) => {
  // Navigating away (to a video, say) also swaps route.query out from under this watcher.
  if (route.name !== 'Videos') return;

  const urlPage = Math.max(Number(query.page) || 1, 1);
  const urlSearch = typeof query.q === 'string' ? query.q : '';
  const urlStatus = isStatus(query.status) ? query.status : 'all';
  const urlSort = isSort(query.sort) ? query.sort : 'created_at';
  const urlDirection = query.dir === 'asc' ? 'asc' : 'desc';

  if (
    urlPage === page.value && urlSearch === search.value && urlStatus === status.value
    && urlSort === sort.value && urlDirection === direction.value
  ) return;

  page.value = urlPage;
  search.value = urlSearch;
  status.value = urlStatus;
  sort.value = urlSort;
  direction.value = urlDirection;
  fetchVideos();
});

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
    <div class="flex gap-2">
      <Input v-model="search" placeholder="Search videos..." class="flex-1" @input="onSearch" />
      <Select v-model="status" @update:model-value="onStatusChange">
        <SelectTrigger class="w-40">
          <SelectValue placeholder="Status" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All statuses</SelectItem>
          <SelectItem v-for="s in STATUSES" :key="s" :value="s" class="capitalize">{{ s }}</SelectItem>
        </SelectContent>
      </Select>
    </div>
    <div class="overflow-hidden rounded-lg border">
      <Table>
        <TableHeader class="bg-muted sticky top-0 z-10">
          <TableRow>
            <TableHead class="w-20"></TableHead>
            <TableHead
              v-for="column in ([['name', 'Title'], ['duration', 'Duration'], ['size', 'Size'], ['status', 'Status'], ['created_at', 'Created']] as [VideoSort, string][])"
              :key="column[0]"
              class="cursor-pointer select-none hover:text-foreground"
              :aria-sort="sort === column[0] ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'"
              @click="onSort(column[0])"
            >
              <span class="inline-flex items-center gap-1">
                {{ column[1] }}
                <ArrowUp v-if="sort === column[0] && direction === 'asc'" class="h-3 w-3" />
                <ArrowDown v-else-if="sort === column[0]" class="h-3 w-3" />
                <ArrowUpDown v-else class="h-3 w-3 opacity-30" />
              </span>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-if="loading">
            <TableCell colspan="6" class="text-center">
              <Spinner />
              Loading videos...
            </TableCell>
          </TableRow>
          <TableRow v-else-if="videos.data.length === 0">
            <TableCell colspan="6" class="text-center text-muted-foreground">
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
            <!-- Everything the video keeps on S3: the packages plus any retained renditions/source. -->
            <TableCell>{{ formatBytes(video.size) }}</TableCell>
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
        <Pagination v-slot="{ page: currentPage }" :items-per-page="videos.perPage" :total="videos.total" :page="page" @update:page="onPageChange">
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
