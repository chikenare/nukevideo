<script setup lang="ts">
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogDescription,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import { ref, computed } from 'vue'
import type { Node } from '@/types/Node'
import NodeService from '@/services/NodeService'
import { CheckCircle2, XCircle, Circle, Loader2, AlertTriangle } from 'lucide-vue-next'

type StepStatus = 'pending' | 'running' | 'success' | 'failed'

type DeployStep = {
  key: string
  label: string
  status: StepStatus
  error?: string
}

type Phase = 'loading' | 'jobs-warning' | 'steps' | 'deploying'

const open = ref(false)
const node = ref<Node | null>(null)
const steps = ref<DeployStep[]>([])
const deploying = ref(false)
const deployFinished = ref(false)
const phase = ref<Phase>('loading')
const pendingJobsCount = ref(0)
const reservedJobsCount = ref(0)

const totalJobs = computed(() => pendingJobsCount.value + reservedJobsCount.value)

const emit = defineEmits<{
  (e: 'node-updated', node: Node): void
}>()

const show = async (targetNode: Node) => {
  node.value = targetNode
  steps.value = []
  deploying.value = false
  deployFinished.value = false
  phase.value = 'loading'
  pendingJobsCount.value = 0
  reservedJobsCount.value = 0
  open.value = true

  try {
    if (targetNode.type === 'worker') {
      const jobs = await NodeService.getPendingJobs(targetNode.id)

      if (jobs.total > 0) {
        pendingJobsCount.value = jobs.totalPending
        reservedJobsCount.value = jobs.totalReserved
        phase.value = 'jobs-warning'
        return
      }
    }

    await loadSteps()
  } catch {
    steps.value = [{ key: 'error', label: 'Failed to load deploy info', status: 'failed' }]
    phase.value = 'steps'
  }
}

const loadSteps = async () => {
  phase.value = 'loading'
  try {
    const rawSteps = await NodeService.getDeploySteps(node.value!.id)
    steps.value = rawSteps.map(s => ({ ...s, status: 'pending' as StepStatus }))
    phase.value = 'steps'
  } catch {
    steps.value = [{ key: 'error', label: 'Failed to load deploy steps', status: 'failed' }]
    phase.value = 'steps'
  }
}

const disableAndRetry = async () => {
  if (!node.value) return

  try {
    const updated = await NodeService.updateNode(node.value.id, { isActive: false })
    node.value = updated
    emit('node-updated', updated)
  } catch {
    // stay on warning
  }
}

const proceedAnyway = async () => {
  await loadSteps()
}

const runDeploy = async () => {
  if (!node.value || deploying.value) return

  deploying.value = true
  deployFinished.value = false
  phase.value = 'deploying'

  for (const step of steps.value) {
    step.status = 'running'

    try {
      await NodeService.deployStep(node.value.id, step.key)
      step.status = 'success'
    } catch (err: unknown) {
      step.status = 'failed'
      step.error = (err as any).response?.data?.message || (err as Error).message || 'Unknown error'
      break
    }
  }

  deploying.value = false
  deployFinished.value = true
}

const hasError = () => steps.value.some(s => s.status === 'failed')

defineExpose({ show })
</script>

<template>
  <AlertDialog v-model:open="open">
    <AlertDialogContent class="max-w-md">
      <AlertDialogHeader>
        <AlertDialogTitle>Deploy to {{ node?.name }}</AlertDialogTitle>
        <AlertDialogDescription v-if="phase === 'jobs-warning'">
          Active jobs detected on this node.
        </AlertDialogDescription>
      </AlertDialogHeader>

      <!-- Loading -->
      <div v-if="phase === 'loading'" class="flex items-center justify-center py-6">
        <Spinner />
      </div>

      <!-- Jobs warning -->
      <div v-else-if="phase === 'jobs-warning'" class="flex flex-col gap-4 py-2">
        <div class="flex items-start gap-3 rounded-md border border-yellow-500/30 bg-yellow-500/10 p-3">
          <AlertTriangle class="h-5 w-5 text-yellow-500 mt-0.5 shrink-0" />
          <div class="flex flex-col gap-1">
            <span class="text-sm font-medium">
              {{ totalJobs }} active {{ totalJobs === 1 ? 'job' : 'jobs' }} on this node
            </span>
            <span class="text-xs text-muted-foreground" v-if="reservedJobsCount > 0">
              {{ reservedJobsCount }} running, {{ pendingJobsCount }} pending
            </span>
            <span class="text-xs text-muted-foreground" v-else>
              {{ pendingJobsCount }} pending
            </span>
            <span class="text-xs text-muted-foreground mt-1">
              Deploying now will restart workers. Running jobs will finish gracefully,
              but pending jobs may be delayed.
            </span>
            <span class="text-xs text-muted-foreground mt-1">
              You can disable the node first to stop new jobs from being dispatched,
              wait for current jobs to finish, and then deploy safely.
            </span>
          </div>
        </div>
      </div>

      <!-- Deploy steps -->
      <div v-else class="flex flex-col gap-3 py-2">
        <div v-for="step in steps" :key="step.key" class="flex items-start gap-3">
          <div class="mt-0.5">
            <Loader2 v-if="step.status === 'running'" class="h-4 w-4 animate-spin text-blue-500" />
            <CheckCircle2 v-else-if="step.status === 'success'" class="h-4 w-4 text-emerald-500" />
            <XCircle v-else-if="step.status === 'failed'" class="h-4 w-4 text-red-500" />
            <Circle v-else class="h-4 w-4 text-muted-foreground/40" />
          </div>
          <div class="flex flex-col gap-0.5">
            <span class="text-sm font-medium" :class="{ 'text-muted-foreground': step.status === 'pending' }">
              {{ step.label }}
            </span>
            <span v-if="step.error" class="text-xs text-red-500">{{ step.error }}</span>
          </div>
        </div>
      </div>

      <AlertDialogFooter>
        <!-- Jobs warning phase -->
        <template v-if="phase === 'jobs-warning'">
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <Button variant="outline" @click="disableAndRetry">
            Disable node
          </Button>
          <Button variant="destructive" @click="proceedAnyway">
            Deploy anyway
          </Button>
        </template>

        <!-- Steps / deploying phase -->
        <template v-else-if="phase !== 'loading'">
          <AlertDialogCancel :disabled="deploying">
            {{ deployFinished ? 'Close' : 'Cancel' }}
          </AlertDialogCancel>
          <Button v-if="!deployFinished || hasError()" :disabled="deploying || steps.length === 0" @click="runDeploy">
            {{ deploying ? 'Deploying...' : hasError() ? 'Retry' : 'Deploy' }}
          </Button>
        </template>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
