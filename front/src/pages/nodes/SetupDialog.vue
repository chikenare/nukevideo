<script setup lang="ts">
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import { ref, computed, nextTick } from 'vue'
import type { Node, ValidationCheck } from '@/types/Node'
import NodeService from '@/services/NodeService'
import { CheckCircle2, XCircle, AlertTriangle, Terminal } from 'lucide-vue-next'

const emit = defineEmits<{
  (e: 'node-updated', node: Node): void
}>()

const open = ref(false)
const node = ref<Node | null>(null)

// Setup tab
const setupRunning = ref(false)
const setupFinished = ref(false)
const setupError = ref(false)
const setupLines = ref<string[]>([])
const setupTerminalEl = ref<HTMLElement | null>(null)

// Services tab
const deployRunning = ref(false)
const deployFinished = ref(false)
const deployError = ref(false)
const deployLines = ref<string[]>([])
const deployTerminalEl = ref<HTMLElement | null>(null)
const showJobsWarning = ref(false)
const pendingJobsCount = ref(0)
const reservedJobsCount = ref(0)
const totalJobs = computed(() => pendingJobsCount.value + reservedJobsCount.value)

// Validate tab
const validating = ref(false)
const validationChecks = ref<ValidationCheck[]>([])
const validated = ref(false)

const show = async (targetNode: Node) => {
  node.value = targetNode
  setupRunning.value = false
  setupFinished.value = false
  setupError.value = false
  setupLines.value = []
  deployRunning.value = false
  deployFinished.value = false
  deployError.value = false
  deployLines.value = []
  showJobsWarning.value = false
  pendingJobsCount.value = 0
  reservedJobsCount.value = 0
  validating.value = false
  validationChecks.value = []
  validated.value = false
  open.value = true

  // Check for active jobs on worker nodes
  if (targetNode.type === 'worker') {
    try {
      const jobs = await NodeService.getPendingJobs(targetNode.id)
      if (jobs.total > 0) {
        pendingJobsCount.value = jobs.totalPending
        reservedJobsCount.value = jobs.totalReserved
        showJobsWarning.value = true
      }
    } catch {
      // ignore
    }
  }
}

// ── Shared SSE handler ──

const handleSSE = (
  lines: typeof setupLines,
  terminalEl: typeof setupTerminalEl,
  errorFlag: typeof setupError,
) => (event: { type: string; data: string }) => {
  if (event.type === 'output') {
    for (const line of event.data.split('\n')) {
      if (line) {
        lines.value.push(line)
        nextTick(() => {
          if (terminalEl.value) terminalEl.value.scrollTop = terminalEl.value.scrollHeight
        })
      }
    }
  } else if (event.type === 'error') {
    lines.value.push(`ERROR: ${event.data}`)
    errorFlag.value = true
    nextTick(() => {
      if (terminalEl.value) terminalEl.value.scrollTop = terminalEl.value.scrollHeight
    })
  }
}

// ── Setup tab ──

const runSetup = async () => {
  if (!node.value || setupRunning.value) return

  setupRunning.value = true
  setupFinished.value = false
  setupError.value = false
  setupLines.value = []

  try {
    await NodeService.runSetup(node.value.id, handleSSE(setupLines, setupTerminalEl, setupError))
  } catch (err) {
    setupLines.value.push(`ERROR: ${(err as Error).message}`)
    setupError.value = true
  }

  setupRunning.value = false
  setupFinished.value = true
}

// ── Services tab ──

const disableNode = async () => {
  if (!node.value) return
  try {
    const updated = await NodeService.updateNode(node.value.id, { isActive: false })
    node.value = updated
    emit('node-updated', updated)
    showJobsWarning.value = false
  } catch { /* stay on warning */ }
}

const runDeploy = async () => {
  if (!node.value || deployRunning.value) return

  deployRunning.value = true
  deployFinished.value = false
  deployError.value = false
  deployLines.value = []

  try {
    await NodeService.runDeploy(node.value.id, handleSSE(deployLines, deployTerminalEl, deployError))
  } catch (err) {
    deployLines.value.push(`ERROR: ${(err as Error).message}`)
    deployError.value = true
  }

  deployRunning.value = false
  deployFinished.value = true
}

// ── Validate tab ──

const runValidation = async () => {
  if (!node.value || validating.value) return

  validating.value = true
  validationChecks.value = []
  validated.value = false

  try {
    validationChecks.value = await NodeService.runValidation(node.value.id)
  } catch (err) {
    validationChecks.value = [{
      key: 'error',
      label: 'Validation failed',
      status: 'error',
      output: (err as Error).message,
    }]
  }

  validating.value = false
  validated.value = true
}

defineExpose({ show })
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-2xl max-h-[85vh] flex flex-col">
      <DialogHeader>
        <DialogTitle>Setup {{ node?.name }}</DialogTitle>
        <DialogDescription>Install prerequisites, manage services, and validate the node.</DialogDescription>
      </DialogHeader>

      <Tabs default-value="setup" class="flex-1 flex flex-col min-h-0">
        <TabsList class="w-full">
          <TabsTrigger value="setup" class="flex-1">Setup</TabsTrigger>
          <TabsTrigger value="services" class="flex-1">Services</TabsTrigger>
          <TabsTrigger value="validate" class="flex-1">Validate</TabsTrigger>
        </TabsList>

        <!-- Setup Tab -->
        <TabsContent value="setup" class="flex-1 flex flex-col min-h-0 gap-3">
          <div
            ref="setupTerminalEl"
            class="flex-1 min-h-75 max-h-100 overflow-y-auto rounded-md border bg-zinc-950 p-3 font-mono text-xs"
          >
            <div v-if="setupLines.length === 0 && !setupRunning" class="flex items-center gap-2 text-zinc-500">
              <Terminal class="h-4 w-4" />
              <span>Ready to install prerequisites...</span>
            </div>
            <div v-for="(line, i) in setupLines" :key="i">
              <span
                :class="[
                  line.startsWith('===') ? 'text-blue-400 font-semibold' :
                  line.startsWith('ERROR') ? 'text-red-400' :
                  'text-zinc-300'
                ]"
              >{{ line }}</span>
            </div>
            <div v-if="setupRunning" class="flex items-center gap-2 text-zinc-500 mt-1">
              <Spinner class="h-3 w-3" />
              <span>Running...</span>
            </div>
          </div>

          <div class="flex justify-end gap-2">
            <Button v-if="!setupFinished || setupError" :disabled="setupRunning" @click="runSetup">
              {{ setupRunning ? 'Running...' : setupError ? 'Retry' : 'Run' }}
            </Button>
            <div v-if="setupFinished && !setupError" class="flex items-center gap-2 text-sm text-emerald-500">
              <CheckCircle2 class="h-4 w-4" />
              <span>Setup complete</span>
            </div>
          </div>
        </TabsContent>

        <!-- Services Tab -->
        <TabsContent value="services" class="flex-1 flex flex-col min-h-0 gap-3">
          <!-- Jobs warning -->
          <div v-if="showJobsWarning" class="flex items-start gap-3 rounded-md border border-yellow-500/30 bg-yellow-500/10 p-3">
            <AlertTriangle class="h-5 w-5 text-yellow-500 mt-0.5 shrink-0" />
            <div class="flex flex-col gap-1 flex-1">
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
                Deploying will restart containers. You can disable the node first to stop new jobs.
              </span>
              <div class="flex gap-2 mt-2">
                <Button variant="outline" size="sm" @click="disableNode">Disable node</Button>
                <Button variant="ghost" size="sm" @click="showJobsWarning = false">Continue anyway</Button>
              </div>
            </div>
          </div>

          <div
            ref="deployTerminalEl"
            class="flex-1 min-h-75 max-h-100 overflow-y-auto rounded-md border bg-zinc-950 p-3 font-mono text-xs"
          >
            <div v-if="deployLines.length === 0 && !deployRunning" class="flex items-center gap-2 text-zinc-500">
              <Terminal class="h-4 w-4" />
              <span>Ready to deploy services...</span>
            </div>
            <div v-for="(line, i) in deployLines" :key="i">
              <span
                :class="[
                  line.startsWith('===') ? 'text-blue-400 font-semibold' :
                  line.startsWith('ERROR') ? 'text-red-400' :
                  'text-zinc-300'
                ]"
              >{{ line }}</span>
            </div>
            <div v-if="deployRunning" class="flex items-center gap-2 text-zinc-500 mt-1">
              <Spinner class="h-3 w-3" />
              <span>Deploying...</span>
            </div>
          </div>

          <div class="flex justify-end gap-2">
            <Button v-if="!deployFinished || deployError" :disabled="deployRunning" @click="runDeploy">
              {{ deployRunning ? 'Deploying...' : deployError ? 'Retry' : 'Deploy' }}
            </Button>
            <div v-if="deployFinished && !deployError" class="flex items-center gap-2 text-sm text-emerald-500">
              <CheckCircle2 class="h-4 w-4" />
              <span>Deployed successfully</span>
            </div>
          </div>
        </TabsContent>

        <!-- Validate Tab -->
        <TabsContent value="validate" class="flex-1 flex flex-col min-h-0 gap-3">
          <div class="flex-1 min-h-50 flex flex-col gap-2">
            <div v-if="!validated && !validating" class="flex items-center justify-center h-full text-sm text-muted-foreground">
              Run validation to check the node's status.
            </div>

            <div v-if="validating" class="flex items-center justify-center h-full">
              <Spinner />
            </div>

            <div v-if="validated" class="flex flex-col gap-2">
              <div
                v-for="check in validationChecks"
                :key="check.key"
                class="flex items-start gap-3 rounded-md border p-3"
              >
                <div class="mt-0.5">
                  <CheckCircle2 v-if="check.status === 'ok'" class="h-4 w-4 text-emerald-500" />
                  <AlertTriangle v-else-if="check.status === 'warning'" class="h-4 w-4 text-yellow-500" />
                  <XCircle v-else class="h-4 w-4 text-red-500" />
                </div>
                <div class="flex flex-col gap-0.5 flex-1 min-w-0">
                  <span class="text-sm font-medium">{{ check.label }}</span>
                  <span class="text-xs text-muted-foreground font-mono whitespace-pre-wrap break-all">{{ check.output }}</span>
                </div>
              </div>
            </div>
          </div>

          <div class="flex justify-end">
            <Button :disabled="validating" @click="runValidation">
              {{ validating ? 'Checking...' : validated ? 'Re-check' : 'Run checks' }}
            </Button>
          </div>
        </TabsContent>
      </Tabs>
    </DialogContent>
  </Dialog>
</template>
