import { supabase } from './supabase'

// Calls the Cloudflare Worker with the signed-in user's Supabase access token.
export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const { data, error } = await supabase.auth.getSession()
  if (error) throw error
  if (!data.session?.access_token) throw new Error('Your session has expired. Sign in again.')
  const headers = new Headers(init.headers)
  if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json')
  headers.set('Authorization', `Bearer ${data.session.access_token}`)
  const base = String(import.meta.env.VITE_API_URL ?? '').replace(/\/$/, '')
  if (!base) throw new Error('The API URL has not been configured.')
  const response = await fetch(`${base}${path}`, { ...init, headers })
  if (!response.ok) {
    let message = `API request failed (${response.status})`
    try { const body = await response.json() as { error?: string }; if (body.error) message = body.error } catch { /* keep status message */ }
    throw new Error(message)
  }
  return response.json() as Promise<T>
}
