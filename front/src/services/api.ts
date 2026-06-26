import { ApiException } from '@/exceptions/ApiException'
import { ValidationException } from '@/exceptions/ValidationException'
import axios, { type AxiosInstance, type AxiosResponse } from 'axios'

declare module 'axios' {
  interface AxiosRequestConfig {
    skipTransform?: boolean
  }
  interface InternalAxiosRequestConfig {
    skipTransform?: boolean
  }
}
import { camelizeKeys, decamelizeKeys } from 'humps'

const apiClient: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_URL_API,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'Accept': 'application/json',
  },
  timeout: 10000,
})

apiClient.interceptors.response.use((response: AxiosResponse) => {
  if (
    response.data &&
    response.headers['content-type'] === 'application/json' &&
    !response.config.skipTransform
  ) {
    response.data = camelizeKeys(response.data)
  }

  return response
})

apiClient.interceptors.request.use((config) => {
  const projectUlid = localStorage.getItem('nv:current-project-ulid')
  if (projectUlid && !config.headers['X-Project-Ulid']) {
    config.headers['X-Project-Ulid'] = projectUlid
  }

  if (config.headers['Content-Type'] === 'multipart/form-data')
    return config
  if (config.skipTransform) return config
  if (config.params) {
    config.params = decamelizeKeys(config.params)
  }
  if (config.data) {
    config.data = decamelizeKeys(config.data)
  }
  return config
})

apiClient.interceptors.response.use(
  response => response,
  (error) => {
    const status = error.response?.status
    const data = error.response?.data
    const message = data?.message || error.message || 'Unknown Error'
    const rawError = data?.error || error.code || 'Error'

    if (status === 422) {
      return Promise.reject(new ValidationException(message, camelizeKeys(data.errors) as Record<string, string[]>, rawError))
    }

    return Promise.reject(new ApiException(message, status, rawError))
  }
)

export default apiClient
