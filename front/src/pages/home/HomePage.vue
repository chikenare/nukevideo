<script setup lang="ts">
import { computed, onMounted } from 'vue'
import prettyBytes from 'pretty-bytes'
import { HardDrive, MonitorPlay, Server, TrendingUp } from 'lucide-vue-next'
import { VisXYContainer, VisArea, VisAxis, VisLine } from '@unovis/vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Skeleton } from '@/components/ui/skeleton'
import { Input } from '@/components/ui/input'
import type { ChartConfig } from '@/components/ui/chart'
import { ChartContainer, ChartCrosshair, ChartTooltipContent, componentToString } from '@/components/ui/chart'
import { useAnalytics } from '@/composables/useAnalytics'
import type { BandwidthOverTime } from '@/types/Analytics'

const { data, loading, from, to, fetchAnalytics } = useAnalytics()

onMounted(() => fetchAnalytics())

const bwChartConfig = {
  bytes: { label: 'Bandwidth', color: 'var(--chart-1)' },
} satisfies ChartConfig

const avgBwPerDay = computed(() => {
  if (!data.value?.bandwidthOverTime.length) return 0
  return data.value.summary.totalBytes / data.value.bandwidthOverTime.length
})

function formatDate(d: string) {
  return new Date(d).toLocaleDateString('es', { month: 'short', day: 'numeric' })
}

function pctOfTotal(bytes: number): string {
  if (!data.value) return '0%'
  const total = data.value.summary.totalBytes
  if (total === 0) return '0%'
  return `${((bytes / total) * 100).toFixed(1)}%`
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

    <!-- KPI Cards -->
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Card>
        <CardHeader class="flex flex-row items-center justify-between pb-2">
          <CardTitle class="text-sm font-medium text-muted-foreground">Total Bandwidth</CardTitle>
          <HardDrive class="h-4 w-4 text-muted-foreground" />
        </CardHeader>
        <CardContent>
          <Skeleton v-if="loading" class="h-7 w-28" />
          <div v-else class="text-2xl font-bold tracking-tight">{{ prettyBytes(data?.summary.totalBytes ?? 0) }}</div>
        </CardContent>
      </Card>
      <Card>
        <CardHeader class="flex flex-row items-center justify-between pb-2">
          <CardTitle class="text-sm font-medium text-muted-foreground">Avg / Day</CardTitle>
          <TrendingUp class="h-4 w-4 text-muted-foreground" />
        </CardHeader>
        <CardContent>
          <Skeleton v-if="loading" class="h-7 w-28" />
          <div v-else class="text-2xl font-bold tracking-tight">{{ prettyBytes(avgBwPerDay) }}</div>
        </CardContent>
      </Card>
      <Card>
        <CardHeader class="flex flex-row items-center justify-between pb-2">
          <CardTitle class="text-sm font-medium text-muted-foreground">Active Videos</CardTitle>
          <MonitorPlay class="h-4 w-4 text-muted-foreground" />
        </CardHeader>
        <CardContent>
          <Skeleton v-if="loading" class="h-7 w-28" />
          <div v-else class="text-2xl font-bold tracking-tight">{{ data?.summary.uniqueVideos ?? 0 }}</div>
        </CardContent>
      </Card>
      <Card>
        <CardHeader class="flex flex-row items-center justify-between pb-2">
          <CardTitle class="text-sm font-medium text-muted-foreground">Nodes</CardTitle>
          <Server class="h-4 w-4 text-muted-foreground" />
        </CardHeader>
        <CardContent>
          <Skeleton v-if="loading" class="h-7 w-28" />
          <div v-else class="text-2xl font-bold tracking-tight">{{ data?.nodeCount ?? 0 }}</div>
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
            <ChartCrosshair color="var(--chart-1)" :template="componentToString(bwChartConfig, ChartTooltipContent, { labelFormatter: (i: number | Date) => data!.bandwidthOverTime[Number(i)] ? formatDate(data!.bandwidthOverTime[Number(i)].date) : '' })" />
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
                    {{ (video.extid || video.video).slice(0, 8) }}
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
  </div>
</template>
