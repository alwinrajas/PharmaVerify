import axios, { AxiosError, type AxiosRequestConfig } from 'axios'
import type { ApiEnvelope } from '@/types'

const TOKEN_KEY = 'pharmaverify.token'

export const tokenStore = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

export const apiClient = axios.create({
  baseURL: '/api',
  headers: { Accept: 'application/json' },
})

apiClient.interceptors.request.use((config) => {
  const token = tokenStore.get()

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError<{ message?: string }>) => {
    // An expired or revoked token means the session is over: clear it and send
    // the user back to the sign-in screen rather than showing a broken page.
    if (error.response?.status === 401 && !error.config?.url?.includes('auth/login')) {
      tokenStore.clear()

      if (!window.location.pathname.startsWith('/login')) {
        window.location.assign('/login')
      }
    }

    return Promise.reject(error)
  },
)

/** The message the API sent, or a safe fallback. Never a stack trace. */
export function apiErrorMessage(error: unknown, fallback = 'Something went wrong. Please try again.'): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined

    // Validation failures carry the specific field messages; the first one is
    // far more useful to the user than the generic summary.
    const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined

    return firstFieldError ?? data?.message ?? fallback
  }

  if (error instanceof Error && error.message) {
    return error.message
  }

  return fallback
}

/** Field-level validation errors, for feeding back into a form. */
export function apiFieldErrors(error: unknown): Record<string, string> {
  if (!axios.isAxiosError(error)) return {}

  const errors = (error.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors

  if (!errors) return {}

  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]))
}

export async function get<T>(url: string, params?: Record<string, unknown>): Promise<ApiEnvelope<T>> {
  const { data } = await apiClient.get<ApiEnvelope<T>>(url, { params })
  return data
}

export async function post<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<ApiEnvelope<T>> {
  const { data } = await apiClient.post<ApiEnvelope<T>>(url, body, config)
  return data
}

export async function put<T>(url: string, body?: unknown): Promise<ApiEnvelope<T>> {
  const { data } = await apiClient.put<ApiEnvelope<T>>(url, body)
  return data
}

export async function patch<T>(url: string, body?: unknown): Promise<ApiEnvelope<T>> {
  const { data } = await apiClient.patch<ApiEnvelope<T>>(url, body)
  return data
}

export async function destroy<T>(url: string): Promise<ApiEnvelope<T>> {
  const { data } = await apiClient.delete<ApiEnvelope<T>>(url)
  return data
}

/**
 * Downloads a file from the API and hands it to the browser.
 * Used by every Excel / PDF export button.
 */
export async function download(url: string, params: Record<string, unknown>, fallbackName: string): Promise<void> {
  const response = await apiClient.get(url, { params, responseType: 'blob' })

  const disposition = response.headers['content-disposition'] as string | undefined
  const match = disposition?.match(/filename="?([^"]+)"?/)
  const fileName = match?.[1] ?? fallbackName

  const blobUrl = URL.createObjectURL(response.data as Blob)
  const anchor = document.createElement('a')
  anchor.href = blobUrl
  anchor.download = fileName
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(blobUrl)
}
