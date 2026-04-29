<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import prettyBytes from 'pretty-bytes'
import { VisXYContainer, VisArea, VisAxis, VisLine } from '@unovis/vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Skeleton } from '@/components/ui/skeleton'
import { Input } from '@/components/ui/input'
import type { ChartConfig } from '@/components/ui/chart'
import { ChartContainer, ChartCrosshair, ChartTooltipContent, componentToString } from '@/components/ui/chart'
import { Badge } from '@/components/ui/badge'
import { useAnalytics } from '@/composables/useAnalytics'
import AnalyticsService from '@/services/AnalyticsService'
import type { AnalyticsCard, BandwidthOverTime } from '@/types/Analytics'

const { data, loading, from, to, fetchAnalytics } = useAnalytics()

const queue = ref<Record<string, number> | null>(null)

const statusConfig: Record<string, { label: string; class: string }> = {
  pending: { label: 'Pending', class: 'border-yellow-500 text-yellow-500' },
  running: { label: 'Encoding', class: 'border-blue-500 text-blue-500' },
  failed: { label: 'Failed', class: 'border-red-500 text-red-500' },
}

const activeStatuses = computed(() => {
  if (!queue.value) return []
  return Object.entries(queue.value).filter(([_, count]) => count > 0)
})

const totalStreams = computed(() => {
  if (!queue.value) return 0
  return Object.values(queue.value).reduce((sum, count) => sum + count, 0)
})

onMounted(() => {
  fetchAnalytics()
  AnalyticsService.queueStatus().then(data => queue.value = data).catch(() => {})
})

const bwChartConfig = {
  bytes: { label: 'Bandwidth', color: 'var(--chart-1)' },
} satisfies ChartConfig

function formatDate(d: string) {
  return new Date(d).toLocaleDateString('es', { month: 'short', day: 'numeric' })
}

function pctOfTotal(bytes: number): string {
  if (!data.value) return '0%'
  const total = data.value.cards.find(c => c.label === 'Total Bandwidth')?.value ?? 0
  if (total === 0) return '0%'
  return `${((bytes / total) * 100).toFixed(1)}%`
}

function formatCardValue(card: AnalyticsCard): string {
  switch (card.format) {
    case 'bytes': return prettyBytes(card.value)
    case 'seconds': {
      if (card.value < 60) return `${card.value.toFixed(1)}s`
      if (card.value < 3600) return `${(card.value / 60).toFixed(1)}m`
      return `${(card.value / 3600).toFixed(1)}h`
    }
    default: return card.value.toLocaleString()
  }
}
</script>

<template>
  <div class="p-6 space-y-6">
    <!-- Date Range -->
    <div class="flex items-center gap-3">
      <Input type="date" v-model="from" class="w-auto" />
      <span class="text-muted-foreground text-sm">to</span>
      <Input type="date" v-model="to" class="w-auto" />
    </div>

    <!-- Queue Status -->
    <Card v-if="queue">
      <CardContent class="flex items-center justify-between py-4">
        <div class="flex items-center gap-3">
          <span class="text-sm font-medium text-muted-foreground">Streams</span>
          <div v-if="activeStatuses.length" class="flex flex-wrap gap-2">
            <Badge
              v-for="[status, count] in activeStatuses"
              :key="status"
              variant="outline"
              :class="statusConfig[status]?.class"
            >
              {{ statusConfig[status]?.label }} {{ count }}
            </Badge>
          </div>
          <span v-else class="text-sm text-muted-foreground">Idle</span>
        </div>
        <span v-if="totalStreams > 0" class="text-2xl font-bold tracking-tight">{{ totalStreams }}</span>
      </CardContent>
    </Card>

    <!-- KPI Cards -->
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <template v-if="loading">
        <Card v-for="i in 6" :key="i">
          <CardHeader class="pb-2">
            <Skeleton class="h-4 w-24" />
          </CardHeader>
          <CardContent>
            <Skeleton class="h-7 w-28" />
          </CardContent>
        </Card>
      </template>
      <Card v-else v-for="card in data?.cards" :key="card.label">
        <CardHeader class="pb-2">
          <CardTitle class="text-sm font-medium text-muted-foreground">{{ card.label }}</CardTitle>
        </CardHeader>
        <CardContent>
          <div class="text-2xl font-bold tracking-tight">{{ formatCardValue(card) }}</div>
        </CardContent>
      </Card>
    </div>

    <!-- Bandwidth Over Time -->
    <Card>
      <CardHeader>
        <CardTitle class="text-sm font-medium">Bandwidth Over Time</CardTitle>
      </CardHeader>
      <CardContent>
        <Skeleton v-if="loading" class="h-70 w-full" />
        <ChartContainer v-else-if="data?.bandwidthOverTime.length" :config="bwChartConfig" class="h-70 w-full" :cursor="true">
          <VisXYContainer :data="data.bandwidthOverTime" :padding="{ top: 10 }">
            <VisArea
              :x="(_: BandwidthOverTime, i: number) => i"
              :y="(d: BandwidthOverTime) => d.bytes"
              color="var(--chart-1)"
              :opacity="0.1"
            />
            <VisLine
              :x="(_: BandwidthOverTime, i: number) => i"
              :y="(d: BandwidthOverTime) => d.bytes"
              color="var(--chart-1)"
              :line-width="2"
            />
            <VisAxis type="x" :x="(_: BandwidthOverTime, i: number) => i" :tick-format="(i: number) => data!.bandwidthOverTime[i] ? formatDate(data!.bandwidthOverTime[i].date) : ''" :num-ticks="6" />
            <VisAxis type="y" :tick-format="(v: number) => prettyBytes(v)" />
            <ChartCrosshair color="var(--chart-1)" :template="componentToString(bwChartConfig, ChartTooltipContent, { labelFormatter: (i: number | Date) => data!.bandwidthOverTime[Number(i)] ? formatDate(data!.bandwidthOverTime[Number(i)]!.date) : '' })" />
          </VisXYContainer>
        </ChartContainer>
        <div v-else class="flex items-center justify-center h-70 text-sm text-muted-foreground">
          No data for this period
        </div>
      </CardContent>
    </Card>

    <!-- Tables -->
    <div class="grid gap-4 lg:grid-cols-2">
      <!-- Top IPs -->
      <Card>
        <CardHeader>
          <CardTitle class="text-sm font-medium">Top IPs by Bandwidth</CardTitle>
        </CardHeader>
        <CardContent>
          <Skeleton v-if="loading" class="h-64 w-full" />
          <Table v-else-if="data?.topIps.length">
            <TableHeader>
              <TableRow>
                <TableHead>IP</TableHead>
                <TableHead class="text-right">Bandwidth</TableHead>
                <TableHead class="text-right">%</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="ip in data.topIps" :key="ip.ip">
                <TableCell class="font-mono text-xs">{{ ip.ip }}</TableCell>
                <TableCell class="text-right text-xs">{{ prettyBytes(ip.bytes) }}</TableCell>
                <TableCell class="text-right text-xs text-muted-foreground">{{ pctOfTotal(ip.bytes) }}</TableCell>
              </TableRow>
            </TableBody>
          </Table>
          <div v-else class="flex items-center justify-center h-64 text-sm text-muted-foreground">
            No data
          </div>
        </CardContent>
      </Card>

      <!-- Top Videos -->
      <Card>
        <CardHeader>
          <CardTitle class="text-sm font-medium">Top Videos by Bandwidth</CardTitle>
        </CardHeader>
        <CardContent>
          <Skeleton v-if="loading" class="h-64 w-full" />
          <Table v-else-if="data?.topVideos.length">
            <TableHeader>
              <TableRow>
                <TableHead>Video</TableHead>
                <TableHead class="text-right">Bandwidth</TableHead>
                <TableHead class="text-right">%</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="video in data.topVideos" :key="video.video">
                <TableCell class="font-mono text-xs">
                  <RouterLink :to="{ name: 'Video', params: { id: video.video } }" class="hover:underline text-primary">
                    {{ (video.externalResourceId || video.video).slice(0, 8) }}
                  </RouterLink>
                </TableCell>
                <TableCell class="text-right text-xs">{{ prettyBytes(video.bytes) }}</TableCell>
                <TableCell class="text-right text-xs text-muted-foreground">{{ pctOfTotal(video.bytes) }}</TableCell>
              </TableRow>
            </TableBody>
          </Table>
          <div v-else class="flex items-center justify-center h-64 text-sm text-muted-foreground">
            No data
          </div>
        </CardContent>
      </Card>
    </div>

    <!-- Top External Users -->
    <Card>
      <CardHeader>
        <CardTitle class="text-sm font-medium">Top External Users by Upload Volume</CardTitle>
      </CardHeader>
      <CardContent>
        <Skeleton v-if="loading" class="h-64 w-full" />
        <Table v-else-if="data?.topExternalUsers.length">
          <TableHeader>
            <TableRow>
              <TableHead>External User ID</TableHead>
              <TableHead class="text-right">Upload Volume</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="user in data.topExternalUsers" :key="user.externalUserId">
              <TableCell class="font-mono text-xs">{{ user.externalUserId }}</TableCell>
              <TableCell class="text-right text-xs">{{ prettyBytes(user.bytes) }}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
        <div v-else class="flex items-center justify-center h-64 text-sm text-muted-foreground">
          No data
        </div>
      </CardContent>
    </Card>
  </div>
</template>
