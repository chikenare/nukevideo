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
import { FileVideo } from 'lucide-vue-next';
import { ref, watch, onMounted } from 'vue';
import VideoService from '@/services/VideoService';
import type { Video } from '@/types/Video';
import type { Pagination as ResPagination } from '@/types/Pagination';
import {
  Pagination,
  PaginationContent,
  PaginationItem,
  PaginationNext,
  PaginationPrevious,
} from '@/components/ui/pagination'
import { formatSecondsToTime } from '@/utils/timeFormatter';

const videos = ref<ResPagination<Video>>({ currentPage: 1, data: [], perPage: 15, total: 0 });
const loading = ref(true);
const page = ref(1);
const search = ref('');
let searchTimeout: ReturnType<typeof setTimeout>;

const fetchVideos = async () => {
  try {
    loading.value = true;
    videos.value = await VideoService.index({
      page: page.value,
      search: search.value || undefined,
    });
  } catch (error) {
    console.error('Error fetching videos:', error);
  } finally {
    loading.value = false;
  }
};

const onSearch = (value: string) => {
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

onMounted(() => {
  fetchVideos();
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
          <TableRow v-else v-for="video in videos.data" :key="video.id">
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
              <span v-if="video.status === 'running' && video.streams.length" class="ml-2 text-xs text-muted-foreground">
                {{ Math.round(video.streams.reduce((sum, s) => sum + s.progress, 0) / video.streams.length) }}%
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
