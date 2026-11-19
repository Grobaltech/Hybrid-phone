import { createClient } from '@supabase/supabase-js'
import type { Env } from '../env'

// Service-role client: bypasses RLS. Use ONLY after the caller's role has been checked.
export const adminClient = (env: Env) =>
  createClient(env.SUPABASE_URL, env.SUPABASE_SERVICE_ROLE_KEY, {
    auth: { persistSession: false },
  })
