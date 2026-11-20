-- Allow repair staff to be assigned without exposing private profile columns.
drop policy if exists profiles_repair_staff_read on profiles;
create policy profiles_repair_staff_read on profiles for select to authenticated
using (app_page_allowed('repairs',array['admin','manager','cashier']::user_role[]));

-- Do not release a device while a quoted balance remains unpaid.
create or replace function touch_repair_job()
returns trigger language plpgsql as $$
begin
  new.updated_at := now();
  if new.status = 'ready' and old.status <> 'ready' then new.completed_at := now(); end if;
  if new.status = 'collected' and old.status <> 'collected' then
    if coalesce(new.balance,0) > 0 then raise exception 'cannot mark a repair collected while a balance remains'; end if;
    new.collected_at := now();
    if new.repair_warranty_days > 0 then new.repair_warranty_until := current_date + new.repair_warranty_days; end if;
  end if;
  if new.status = 'approved' and old.status <> 'approved' then new.customer_approved_at := now(); end if;
  return new;
end $$;
