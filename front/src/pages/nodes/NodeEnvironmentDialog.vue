<script setup lang="ts">
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ValidationException } from '@/exceptions/ValidationException'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { ref, nextTick, watch } from 'vue'
import NodeService from '@/services/NodeService'
import { EditorState } from '@codemirror/state'
import { EditorView, keymap, lineNumbers } from '@codemirror/view'
import { defaultKeymap } from '@codemirror/commands'
import { StreamLanguage } from '@codemirror/language'
import { githubDark, githubLight } from '@uiw/codemirror-theme-github'
import { useColorMode } from '@vueuse/core'

const colorMode = useColorMode()

const envLanguage = StreamLanguage.define({
  token(stream) {
    if (stream.sol() && stream.match(/\s*#/)) {
      stream.skipToEnd()
      return 'comment'
    }
    if (stream.sol() && stream.match(/[A-Za-z_][A-Za-z0-9_]*/)) {
      return 'def'
    }
    if (stream.eat('=')) {
      return 'operator'
    }
    stream.skipToEnd()
    return 'string-2'
  },
})

const dialogOpen = ref(false)
const loading = ref(false)
const editorContainer = ref<HTMLDivElement>()
const chunkStoreAddress = ref('')
const errors = ref<Record<string, string[]>>({})
let editorView: EditorView | null = null

const createEditor = (content: string) => {
  if (editorView) {
    editorView.destroy()
    editorView = null
  }

  if (!editorContainer.value) return

  const state = EditorState.create({
    doc: content,
    extensions: [
      keymap.of(defaultKeymap),
      lineNumbers(),
      EditorView.lineWrapping,
      envLanguage,
      colorMode.value == 'dark' ? githubDark : githubLight,
    ],
  })

  editorView = new EditorView({
    state,
    parent: editorContainer.value,
  })
}

const show = () => {
  dialogOpen.value = true
  fetchEnvironment()
}

const fetchEnvironment = async () => {
  loading.value = true
  let text = ''
  try {
    const data = await NodeService.getEnvironment()
    text = data?.environment ?? ''
    chunkStoreAddress.value = data?.chunkStoreAddress ?? ''
    errors.value = {}
  } finally {
    loading.value = false
    await nextTick()
    createEditor(text)
  }
}

const handleSave = async () => {
  if (!editorView) return
  loading.value = true
  try {
    const text = editorView.state.doc.toString()
    await NodeService.updateEnvironment({ environment: text, chunkStoreAddress: chunkStoreAddress.value || null })
    dialogOpen.value = false
  } catch (error) {
    if (error instanceof ValidationException) errors.value = error.errors
    else throw error
  } finally {
    loading.value = false
  }
}

watch(dialogOpen, (open) => {
  if (!open && editorView) {
    editorView.destroy()
    editorView = null
  }
})

defineExpose({ show })
</script>

<template>
  <Dialog v-model:open="dialogOpen">
    <DialogContent class="flex max-h-[85vh] flex-col sm:max-w-3xl">
      <DialogHeader>
        <DialogTitle>Node Environment</DialogTitle>
        <DialogDescription>
          Manage environment variables for all nodes.
        </DialogDescription>
      </DialogHeader>

      <div v-if="loading" class="flex items-center justify-center py-8">
        <span class="text-muted-foreground">Loading...</span>
      </div>

      <div v-else class="flex flex-col gap-4 min-h-0 flex-1">
        <div class="grid gap-2">
          <Label for="chunk_store_address">Chunk store</Label>
          <Input id="chunk_store_address" v-model="chunkStoreAddress" placeholder="e.g. 10.0.0.20 or chunks.internal:9009" />
          <p class="text-xs text-muted-foreground">
            Private address of the worker that runs the chunk store: an IP or a DNS name, with <code>:port</code> when 9000 is taken.
            The worker whose own address this is runs it; the first worker created fills it in. Redeploy the workers after changing it.
          </p>
          <p v-if="errors.chunkStoreAddress" class="text-sm text-destructive">{{ errors.chunkStoreAddress[0] }}</p>
        </div>

        <Label>Variables</Label>
        <div ref="editorContainer"
          class="flex-1 min-h-[300px] overflow-auto rounded-md border border-input bg-background" />

        <DialogFooter>
          <Button :disabled="loading" @click="handleSave">
            {{ loading ? 'Saving...' : 'Save' }}
          </Button>
        </DialogFooter>
      </div>
    </DialogContent>
  </Dialog>
</template>
