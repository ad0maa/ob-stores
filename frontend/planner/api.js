// Tiny fetch wrapper for the same JSON API the jQuery screen uses.
// Every POST carries the CSRF token from the PHP-rendered <meta> tag.

export class ApiError extends Error {
  constructor(message, status, details) {
    super(message)
    this.status = status
    this.details = details
  }
}

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? ''

async function request(url, { body, ...options } = {}) {
  const headers = { Accept: 'application/json' }
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    headers['X-CSRF-Token'] = csrfToken()
  }
  const response = await fetch(url, { ...options, headers, body: body === undefined ? undefined : JSON.stringify(body) })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new ApiError(data.error || `Request failed (${response.status})`, response.status, data.details || {})
  }
  return data
}

export const getJson = (url, options) => request(url, options)
export const postJson = (url, body) => request(url, { method: 'POST', body })
