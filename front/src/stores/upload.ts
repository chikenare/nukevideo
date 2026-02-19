import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import Uppy, { type Meta } from '@uppy/core'
import { type AwsBody } from '@uppy/aws-s3'
import AwsS3Multipart from '@uppy/aws-s3'
import type { FileUpload } from '@/types/FileUpload'

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
  })

  uppy.on('upload-progress', (uppyFile, progress) => {
    const file = files.value.find(f => f.uppyFileId === uppyFile?.id)
    if (file && progress.bytesTotal) {
      file.progress = Math.round((progress.bytesUploaded / progress.bytesTotal) * 100)
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
  }

  function startUpload() {
    files.value.forEach(file => {
      if (!file.uppyFileId && file.status === 'pending') {
        const uppyFileId = uppy.addFile({
          name: file.title,
          type: file.file.type,
          data: file.file,
          meta: {
            template: selectedTemplate.value,
            filename: file.title,
            creatorId: file.creatorId,
            externalId: file.externalId,
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
