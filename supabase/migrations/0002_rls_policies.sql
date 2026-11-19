-- Initial row-level security helpers and baseline policies. The complete policy set is in migrations 0005-0009.

-- ---------------------------------------------------------------- helpers
create or replace function app_role() returns user_role
language sql stable security definer set search_path = public as $$
  select role from profiles where id = auth.uid() and not disabled
$$;

create or replace function app_has_cap(p_cap text) returns boolean
language sql stable security definer set search_path = public as $$
  select app_role() = 'admin'
      or exists (select 1 from user_caps
                 where user_id = auth.uid() and cap = p_cap and allowed)
$$;

create or replace function app_page_allowed(p_page text, p_roles user_role[]) returns boolean
language sql stable security definer set search_path = public as $$
  select coalesce((select allowed from user_page_access where user_id=auth.uid() and page=p_page), app_role()='admin' or app_role()=any(p_roles))
$$;

-- ---------------------------------------------------------------- enable RLS everywhere
alter table profiles          enable row level security;
alter table user_caps         enable row level security;
alter table user_page_access  enable row level security;
alter table suppliers         enable row level security;
alter table products          enable row level security;
alter table customers         enable row level security;
alter table purchases         enable row level security;
alter table purchase_items    enable row level security;
alter table sales             enable row level security;
alter table sale_items        enable row level security;
alter table credits           enable row level security;
alter table credit_payments   enable row level security;
alter table creditors         enable row level security;
alter table creditor_payments enable row level security;
alter table expenses          enable row level security;
alter table settings          enable row level security;
alter table audit_logs        enable row level security;

-- ---------------------------------------------------------------- example policies (extend per table)
-- Mirrors access_map() in legacy/php-app/lib/auth.php

create policy profiles_self_or_admin on profiles for select
  using (id = auth.uid() or app_role() = 'admin');

create policy products_read on products for select
  using (app_role() in ('admin', 'manager', 'cashier'));
create policy products_write on products for all
  using (app_role() in ('admin', 'manager')) with check (app_role() in ('admin', 'manager'));

create policy customers_rw on customers for all
  using (app_role() in ('admin', 'manager', 'cashier'))
  with check (app_role() in ('admin', 'manager', 'cashier'));


-- Remaining role-specific policies and column grants are completed in 0005-0009.
