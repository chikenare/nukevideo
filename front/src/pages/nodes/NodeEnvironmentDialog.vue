<script setup lang="ts">
import { Button } from '@/components/ui/button'
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
    await NodeService.updateEnvironment(text)
    dialogOpen.value = false
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
    <DialogContent class="max-w-3xl max-h-[80vh] flex flex-col">
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
