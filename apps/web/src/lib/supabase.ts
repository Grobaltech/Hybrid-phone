import { createClient } from '@supabase/supabase-js'

// Only the public anon key is used here. Row-level security protects the data.
export const supabase = createClient(
  import.meta.env.VITE_SUPABASE_URL,
  import.meta.env.VITE_SUPABASE_ANON_KEY,
)
