import { ref, watch } from 'vue'
import type { AnalyticsData } from '@/types/Analytics'
import AnalyticsService from '@/services/AnalyticsService'

export function useAnalytics() {
  const data = ref<AnalyticsData | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const from = ref(getDefaultFrom())
  const to = ref(getDefaultTo())

  function getDefaultFrom(): string {
    const d = new Date()
    d.setDate(d.getDate() - 30)
    return d.toISOString().slice(0, 10)
  }

  function getDefaultTo(): string {
    return new Date().toISOString().slice(0, 10)
  }

  async function fetchAnalytics() {
    loading.value = true
    error.value = null
    try {
      data.value = await AnalyticsService.index(from.value, to.value)
    } catch (e: any) {
      error.value = e.message || 'Failed to fetch analytics'
    } finally {
      loading.value = false
    }
  }

  watch([from, to], () => fetchAnalytics())

  return {
    data,
    loading,
    error,
    from,
    to,
    fetchAnalytics,
  }
}
