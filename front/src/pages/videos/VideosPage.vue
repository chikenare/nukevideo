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
import { ref, onMounted } from 'vue';
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

const videos = ref<ResPagination<Video>>({ currentPage: 1, data: [], perPage: 0, total: 0 });
const loading = ref(true);

const fetchVideos = async () => {
  try {
    loading.value = true;
    videos.value = await VideoService.index();
  } catch (error) {
    console.error('Error fetching videos:', error);
  } finally {
    loading.value = false;
  }
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
    <Input placeholder="Search" />
    <div class="overflow-hidden rounded-lg border">
      <Table>
        <TableHeader class="bg-muted sticky top-0 z-10">
          <TableRow>
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
              <RouterLink :to="`/videos/${video.ulid}`">{{ video.name }}</RouterLink>
            </TableCell>
            <TableCell>{{ formatSecondsToTime(video.duration) }}</TableCell>
            <TableCell>
              <Badge :variant="video.status === 'completed' ? 'default' : 'outline'">
                <Spinner v-if="video.status === 'running'" />
                {{ video.status }}
              </Badge>
            </TableCell>
            <TableCell>{{ formatDate(video.createdAt) }}</TableCell>
          </TableRow>
        </TableBody>
      </Table>

      <div class="py-4">
        <Pagination v-slot="{ page }" :items-per-page="videos.perPage" :total="videos.total" :default-page="1">
          <PaginationContent v-slot="{ items }">
            <PaginationPrevious />
            <template v-for="(item, index) in items" :key="index">
              <PaginationItem v-if="item.type === 'page'" :value="item.value" :is-active="item.value === page">
                {{ item.value }}
              </PaginationItem>
            </template>
            <!-- <PaginationEllipsis :index="4" /> -->
            <PaginationNext />
          </PaginationContent>
        </Pagination>
      </div>
    </div>
  </div>
</template>
