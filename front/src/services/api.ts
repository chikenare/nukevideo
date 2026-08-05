import { ApiException } from '@/exceptions/ApiException'
import { ValidationException } from '@/exceptions/ValidationException'
import axios, { type AxiosInstance } from 'axios'

const apiClient: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_URL_API,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'Accept': 'application/json',
  },
})


apiClient.interceptors.request.use((config) => {
  const projectUlid = localStorage.getItem('nv:current-project-ulid')
  if (projectUlid && !config.headers['X-Project-Ulid']) {
    config.headers['X-Project-Ulid'] = projectUlid
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

    // Expired session: send the user back to login instead of leaving every view broken.
    // A hard navigation (not the router) keeps this module free of circular imports.
    if (status === 401 && !window.location.pathname.startsWith('/login')) {
      window.location.assign('/login')
    }

    if (status === 422) {
      return Promise.reject(new ValidationException(message, data.errors, rawError))
    }

    return Promise.reject(new ApiException(message, status, rawError))
  }
)

export default apiClient
