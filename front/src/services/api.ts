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
  timeout: 10000,
})

apiClient.interceptors.response.use(
  response => response,
  (error) => {
    const status = error.response?.status;
    const data = error.response?.data;
    const message = data?.message || error.message || 'Unknown Error';
    const rawError = data?.error || error.code || 'Error';

    if (status === 422) {
      return Promise.reject(new ValidationException(message, data.errors, rawError));
    }

    return Promise.reject(new ApiException(message, status, rawError));
  }
)

export default apiClient
