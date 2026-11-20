-- Backward compatibility for legacy audit triggers still active in some
-- Hybrid databases. Keep the unified details column while accepting old/new
-- row snapshots written by legacy audit functions.
alter table public.audit_logs
  add column if not exists old_values jsonb,
  add column if not exists new_values jsonb;

comment on column public.audit_logs.old_values is
  'Legacy before-image snapshot retained for audit trigger compatibility.';
comment on column public.audit_logs.new_values is
  'Legacy after-image snapshot retained for audit trigger compatibility.';
