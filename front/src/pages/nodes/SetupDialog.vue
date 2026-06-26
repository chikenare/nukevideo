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
import { CheckCircle2, XCircle, AlertTriangle, Terminal, Copy, Check } from '@lucide/vue'

const emit = defineEmits<{
  (e: 'node-updated', node: Node): void
}>()

const open = ref(false)
const node = ref<Node | null>(null)

// Deploy tab
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

// Install tab
const bootstrapLoading = ref(false)
const bootstrapCommand = ref<string | null>(null)
const bootstrapCopied = ref(false)

const generateBootstrap = async () => {
  if (!node.value || bootstrapLoading.value) return
  bootstrapLoading.value = true
  bootstrapCommand.value = null
  try {
    const result = await NodeService.generateBootstrapToken(node.value.id)
    bootstrapCommand.value = result.command
  } catch {
    // ignore, button stays enabled
  }
  bootstrapLoading.value = false
}

const copyBootstrap = async () => {
  if (!bootstrapCommand.value) return
  await navigator.clipboard.writeText(bootstrapCommand.value)
  bootstrapCopied.value = true
  setTimeout(() => { bootstrapCopied.value = false }, 2000)
}

const show = async (targetNode: Node) => {
  node.value = targetNode
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
  bootstrapLoading.value = false
  bootstrapCommand.value = null
  bootstrapCopied.value = false
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

// ── SSE handler ──

const handleSSE = (event: { type: string; data: string }) => {
  if (event.type === 'output') {
    for (const line of event.data.split('\n')) {
      if (line) {
        deployLines.value.push(line)
        nextTick(() => {
          if (deployTerminalEl.value) deployTerminalEl.value.scrollTop = deployTerminalEl.value.scrollHeight
        })
      }
    }
  } else if (event.type === 'error') {
    deployLines.value.push(`ERROR: ${event.data}`)
    deployError.value = true
    nextTick(() => {
      if (deployTerminalEl.value) deployTerminalEl.value.scrollTop = deployTerminalEl.value.scrollHeight
    })
  }
}

// ── Deploy tab ──

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
    await NodeService.runDeploy(node.value.id, handleSSE)
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

      <Tabs default-value="deploy" class="flex-1 flex flex-col min-h-0">
        <TabsList class="w-full">
          <TabsTrigger value="deploy" class="flex-1">Deploy</TabsTrigger>
          <TabsTrigger value="validate" class="flex-1">Validate</TabsTrigger>
          <TabsTrigger v-if="node?.type === 'worker'" value="install" class="flex-1">Install</TabsTrigger>
        </TabsList>

        <!-- Deploy Tab -->
        <TabsContent value="deploy" class="flex-1 flex flex-col min-h-0 gap-3">
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
              <span>Ready to set up and deploy the node...</span>
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
        <!-- Install Tab -->
        <TabsContent v-if="node?.type === 'worker'" value="install" class="flex-1 flex flex-col min-h-0 gap-4">
          <p class="text-sm text-muted-foreground">
            Generate a one-time install command to deploy this worker on a machine without SSH access (e.g. behind NAT).
          </p>

          <div v-if="bootstrapCommand" class="flex flex-col gap-2">
            <div class="flex items-center gap-2 rounded-md border bg-zinc-950 px-3 py-2 font-mono text-xs text-zinc-300 break-all">
              <span class="flex-1 select-all">{{ bootstrapCommand }}</span>
              <Button variant="ghost" size="icon" class="h-6 w-6 shrink-0" @click="copyBootstrap">
                <Check v-if="bootstrapCopied" class="h-3.5 w-3.5 text-emerald-500" />
                <Copy v-else class="h-3.5 w-3.5" />
              </Button>
            </div>
            <p class="text-xs text-muted-foreground">Valid for 1 hour · single use</p>
          </div>

          <div class="flex justify-end">
            <Button :disabled="bootstrapLoading" @click="generateBootstrap">
              {{ bootstrapLoading ? 'Generating...' : bootstrapCommand ? 'Regenerate' : 'Generate command' }}
            </Button>
          </div>
        </TabsContent>
      </Tabs>
    </DialogContent>
  </Dialog>
</template>
