export class ApiError extends Error {
  readonly status: number
  readonly payload: unknown

  constructor(status: number, payload: unknown, message: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.payload = payload
  }

  /** True while the user is authenticated but still owes a second factor. */
  get needsTwoFactor(): boolean {
    return (
      this.status === 401 &&
      typeof this.payload === 'object' &&
      this.payload !== null &&
      'two_factor_complete' in this.payload &&
      this.payload.two_factor_complete === false
    )
  }
}

type RequestOptions = Omit<RequestInit, 'body'> & { body?: unknown }

function readCsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)csrf-token=([^;]+)/)

  return match ? decodeURIComponent(match[1]) : null
}

export async function apiFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { body, headers, ...rest } = options
  const method = (rest.method ?? 'GET').toUpperCase()

  const finalHeaders = new Headers(headers)
  finalHeaders.set('Accept', 'application/json')

  // FormData sets its own content type, including the multipart
  // boundary; overriding it makes the upload unparseable.
  if (body !== undefined && !(body instanceof FormData)) {
    finalHeaders.set('Content-Type', 'application/json')
  }

  if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
    const token = readCsrfToken()

    if (token) {
      finalHeaders.set('X-CSRF-Token', token)
    }
  }

  const response = await fetch(`/api${path}`, {
    ...rest,
    headers: finalHeaders,
    // Same-origin cookies carry the session; see the auth design notes.
    credentials: 'same-origin',
    body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
  })

  const text = await response.text()
  const payload = text ? tryParseJson(text) : null

  if (!response.ok) {
    throw new ApiError(response.status, payload, extractMessage(payload) ?? response.statusText)
  }

  return payload as T
}

function tryParseJson(text: string): unknown {
  try {
    return JSON.parse(text)
  } catch {
    return text
  }
}

function extractMessage(payload: unknown): string | null {
  if (typeof payload === 'object' && payload !== null) {
    for (const key of ['message', 'error', 'detail'] as const) {
      if (key in payload && typeof (payload as Record<string, unknown>)[key] === 'string') {
        return (payload as Record<string, string>)[key]
      }
    }
  }

  return null
}

/**
 * A field out of an error payload, when the server sent one.
 *
 * ApiError carries `unknown` on purpose -- the body comes off the
 * wire -- so every reader would otherwise repeat the same narrowing.
 */
export function errorField(error: unknown, field: string): string | null {
  if (!(error instanceof ApiError) || typeof error.payload !== 'object' || error.payload === null) {
    return null
  }

  const value = (error.payload as Record<string, unknown>)[field]

  return typeof value === 'string' ? value : null
}
