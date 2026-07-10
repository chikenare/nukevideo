import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import Uppy, { type Meta } from '@uppy/core'
import { type AwsBody } from '@uppy/aws-s3'
import AwsS3Multipart from '@uppy/aws-s3'
import type { FileUpload } from '@/types/FileUpload'
import { useProjectsStore } from './projects'

function getXsrfToken(): Record<string, string> {
  const token = document.cookie
    .split('; ')
    .find(row => row.startsWith('XSRF-TOKEN='))
    ?.split('=')[1]
  return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}
}

export const useUploadStore = defineStore('upload', () => {
  const files = ref<FileUpload[]>([])
  const selectedTemplate = ref<string>('')

  const uppy = new Uppy<Meta, AwsBody>({
    debug: true,
    autoProceed: false,
  }).use(AwsS3Multipart, {
    endpoint: import.meta.env.VITE_URL_API,
    headers: getXsrfToken(),
    cookiesRule: 'include',
    limit: 4,
    getChunkSize() {
      return 30 * 1024 * 1024
    },
  })

  // Raw bytes uploaded per file, updated on every progress event. The global
  // speed is derived from the sum of these on a fixed 1s cadence (see below),
  // so the displayed number stays calm regardless of how often Uppy fires.
  const fileBytes = new Map<string, number>()
  const uploadSpeed = ref(0)
  let speedTimer: ReturnType<typeof setInterval> | null = null
  let lastTotal = 0
  let lastTime = 0

  function totalUploadedBytes() {
    let sum = 0
    for (const b of fileBytes.values()) sum += b
    return sum
  }

  function startSpeedTracking() {
    if (speedTimer) return
    lastTotal = totalUploadedBytes()
    lastTime = Date.now()
    speedTimer = setInterval(() => {
      const now = Date.now()
      const total = totalUploadedBytes()
      const elapsed = (now - lastTime) / 1000
      if (elapsed > 0) {
        const instant = Math.max(0, (total - lastTotal) / elapsed)
        // Smooth out jitter with an exponential moving average.
        uploadSpeed.value = uploadSpeed.value ? uploadSpeed.value * 0.7 + instant * 0.3 : instant
      }
      lastTotal = total
      lastTime = now
      if (!isUploading.value) stopSpeedTracking()
    }, 1000)
  }

  function stopSpeedTracking() {
    if (speedTimer) {
      clearInterval(speedTimer)
      speedTimer = null
    }
    uploadSpeed.value = 0
  }

  uppy.on('upload-progress', (uppyFile, progress) => {
    const file = files.value.find(f => f.uppyFileId === uppyFile?.id)
    if (file && progress.bytesTotal) {
      file.progress = Math.round((progress.bytesUploaded / progress.bytesTotal) * 100)
      fileBytes.set(file.uppyFileId!, progress.bytesUploaded)
      startSpeedTracking()
    }
  })

  uppy.on('upload-success', (uppyFile) => {
    const file = files.value.find(f => f.uppyFileId === uppyFile?.id)
    if (file) {
      file.status = 'success'
      file.progress = 100
    }
  })

  uppy.on('upload-error', (uppyFile, error) => {
    const file = files.value.find(f => f.uppyFileId === uppyFile?.id)
    if (file) {
      file.status = 'error'
      file.logError = error.message
      if (file.uppyFileId) fileBytes.delete(file.uppyFileId)
    }
  })

  const hasFiles = computed(() => files.value.length > 0)
  const isUploading = computed(() => files.value.some(f => f.progress > 0 && f.progress < 100))

  function addFiles(newFiles: FileUpload[]) {
    files.value.push(...newFiles)
  }

  function removeFile(index: number) {
    const file = files.value[index]
    if (file?.uppyFileId) {
      uppy.removeFile(file.uppyFileId)
    }
    files.value.splice(index, 1)
  }

  function clearFiles() {
    uppy.cancelAll()
    files.value = []
    fileBytes.clear()
    stopSpeedTracking()
  }

  function startUpload() {
    const projectsStore = useProjectsStore()

    files.value.forEach(file => {
      if (!file.uppyFileId && file.status === 'pending') {
        const uppyFileId = uppy.addFile({
          name: file.title,
          data: file.file,
          meta: {
            template: selectedTemplate.value,
            project: projectsStore.currentProject?.ulid,
            externalUserId: file.externalUserId,
            externalResourceId: file.externalResourceId,
          }
        })
        file.uppyFileId = uppyFileId
      }
    })

    uppy.upload()
  }

  function setTemplate(templateId: string) {
    selectedTemplate.value = templateId
  }

  function cancelUpload(fileIndex: number) {
    const file = files.value[fileIndex]
    if (file?.uppyFileId) {
      uppy.removeFile(file.uppyFileId)
      file.status = 'error'
      file.logError = 'Cancelled by user'
      file.progress = 0
      fileBytes.delete(file.uppyFileId)
    }
  }

  function pauseUpload(fileIndex: number) {
    const file = files.value[fileIndex]
    if (file?.uppyFileId) {
      uppy.pauseResume(file.uppyFileId)
      file.status = 'paused'
    }
  }

  function resumeUpload(fileIndex: number) {
    const file = files.value[fileIndex]
    if (file?.uppyFileId && file.status === 'paused') {
      uppy.pauseResume(file.uppyFileId)
      file.status = 'pending'
    }
  }

  return {
    // State
    files,
    selectedTemplate,
    uppy,

    // Computed
    hasFiles,
    isUploading,
    uploadSpeed,

    // Actions
    addFiles,
    removeFile,
    clearFiles,
    startUpload,
    setTemplate,
    cancelUpload,
    pauseUpload,
    resumeUpload,
  }
})
