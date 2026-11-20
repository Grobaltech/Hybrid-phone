-- Hybrid phone-only repair department.
-- Repairs support both Hybrid-purchased devices and external customers.
create sequence if not exists repair_job_number_seq;

create table if not exists repair_jobs (
  id bigint generated always as identity primary key,
  job_no text not null unique,
  customer_type text not null check (customer_type in ('hybrid','external')),
  customer_id bigint references customers(id) on delete set null,
  customer_name text not null,
  customer_phone text not null,
  customer_email text,
  brand text not null,
  model text not null,
  imei text,
  serial_number text,
  reported_fault text not null,
  intake_condition text,
  accessories_received text,
  warranty_status text not null default 'not_applicable'
    check (warranty_status in ('not_applicable','pending','approved','rejected')),
  warranty_reference text,
  warranty_notes text,
  purchase_sale_id bigint references sales(id) on delete set null,
  diagnosis text,
  quote_labour numeric(14,2) not null default 0 check (quote_labour >= 0),
  quote_parts numeric(14,2) not null default 0 check (quote_parts >= 0),
  quoted_total numeric(14,2) not null default 0 check (quoted_total >= 0),
  actual_parts_cost numeric(14,2) not null default 0 check (actual_parts_cost >= 0),
  amount_paid numeric(14,2) not null default 0 check (amount_paid >= 0),
  balance numeric(14,2) not null default 0 check (balance >= 0),
  status text not null default 'received'
    check (status in ('received','diagnosing','awaiting_approval','approved','repairing','testing','ready','collected','unrepairable','cancelled')),
  assigned_to uuid references profiles(id) on delete set null,
  received_by uuid references profiles(id) on delete set null,
  customer_approved_at timestamptz,
  completed_at timestamptz,
  collected_at timestamptz,
  repair_warranty_days integer not null default 30 check (repair_warranty_days between 0 and 365),
  repair_warranty_until date,
  internal_notes text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create or replace function set_repair_job_number()
returns trigger language plpgsql set search_path=public as $$
begin
  if new.job_no is null or new.job_no = '' then
    new.job_no := 'HYB-RP-' || to_char(now(),'YYMMDD') || '-' || lpad(nextval('repair_job_number_seq')::text,5,'0');
  end if;
  new.updated_at := now();
  return new;
end $$;

drop trigger if exists repair_job_number_before_insert on repair_jobs;
create trigger repair_job_number_before_insert
before insert on repair_jobs
for each row execute function set_repair_job_number();

create or replace function touch_repair_job()
returns trigger language plpgsql as $$
begin
  new.updated_at := now();
  if new.status = 'ready' and old.status <> 'ready' then new.completed_at := now(); end if;
  if new.status = 'collected' and old.status <> 'collected' then
    new.collected_at := now();
    if new.repair_warranty_days > 0 then new.repair_warranty_until := current_date + new.repair_warranty_days; end if;
  end if;
  if new.status = 'approved' and old.status <> 'approved' then new.customer_approved_at := now(); end if;
  return new;
end $$;
drop trigger if exists repair_job_touch_before_update on repair_jobs;
create trigger repair_job_touch_before_update before update on repair_jobs
for each row execute function touch_repair_job();

create table if not exists repair_payments (
  id bigint generated always as identity primary key,
  job_id bigint not null references repair_jobs(id) on delete restrict,
  amount numeric(14,2) not null check (amount > 0),
  method text not null default 'cash' check (method in ('cash','mobile_money','bank','card')),
  reference text,
  note text,
  received_by uuid references profiles(id),
  created_at timestamptz not null default now()
);

create table if not exists repair_job_parts (
  id bigint generated always as identity primary key,
  job_id bigint not null references repair_jobs(id) on delete restrict,
  product_id bigint not null references products(id) on delete restrict,
  quantity integer not null check (quantity > 0),
  unit_cost numeric(14,2) not null check (unit_cost >= 0),
  line_cost numeric(14,2) not null check (line_cost >= 0),
  added_by uuid references profiles(id),
  created_at timestamptz not null default now()
);

create table if not exists repair_job_events (
  id bigint generated always as identity primary key,
  job_id bigint not null references repair_jobs(id) on delete restrict,
  event_type text not null,
  details text,
  created_by uuid references profiles(id),
  created_at timestamptz not null default now()
);

create index if not exists repair_jobs_created_idx on repair_jobs(created_at desc);
create index if not exists repair_jobs_status_idx on repair_jobs(status);
create index if not exists repair_jobs_customer_phone_idx on repair_jobs(customer_phone);
create index if not exists repair_jobs_imei_idx on repair_jobs(imei) where imei is not null;
create index if not exists repair_jobs_assigned_idx on repair_jobs(assigned_to);
create index if not exists repair_payments_job_idx on repair_payments(job_id,created_at desc);
create index if not exists repair_parts_job_idx on repair_job_parts(job_id);

alter table repair_jobs enable row level security;
alter table repair_payments enable row level security;
alter table repair_job_parts enable row level security;
alter table repair_job_events enable row level security;

drop policy if exists repair_jobs_read on repair_jobs;
drop policy if exists repair_jobs_insert on repair_jobs;
drop policy if exists repair_jobs_update on repair_jobs;
drop policy if exists repair_payments_read on repair_payments;
drop policy if exists repair_parts_read on repair_job_parts;
drop policy if exists repair_events_read on repair_job_events;

create policy repair_jobs_read on repair_jobs for select to authenticated
using (app_page_allowed('repairs',array['admin','manager','cashier']::user_role[]));
create policy repair_jobs_insert on repair_jobs for insert to authenticated
with check (app_page_allowed('repairs',array['admin','manager','cashier']::user_role[]));
create policy repair_jobs_update on repair_jobs for update to authenticated
using (app_page_allowed('repairs',array['admin','manager','cashier']::user_role[]))
with check (app_page_allowed('repairs',array['admin','manager','cashier']::user_role[]));
create policy repair_payments_read on repair_payments for select to authenticated
using (app_page_allowed('repairs',array['admin','manager','cashier']::user_role[]));
create policy repair_parts_read on repair_job_parts for select to authenticated
using (app_page_allowed('repairs',array['admin','manager','cashier']::user_role[]));
create policy repair_events_read on repair_job_events for select to authenticated
using (app_page_allowed('repairs',array['admin','manager','cashier']::user_role[]));

grant select,insert,update on repair_jobs to authenticated;
grant select on repair_payments,repair_job_parts,repair_job_events to authenticated;
grant usage,select on sequence repair_job_number_seq to authenticated;

create or replace function record_repair_payment(
  p_job_id bigint, p_amount numeric, p_method text default 'cash',
  p_reference text default null, p_note text default null
) returns bigint
language plpgsql security definer set search_path=public as $$
declare v_job repair_jobs%rowtype; v_payment_id bigint;
begin
  if auth.uid() is null or not app_page_allowed('repairs',array['admin','manager','cashier']::user_role[]) then
    raise exception 'not allowed';
  end if;
  if p_amount is null or p_amount <= 0 then raise exception 'payment must be greater than zero'; end if;
  if p_method not in ('cash','mobile_money','bank','card') then raise exception 'invalid payment method'; end if;
  select * into v_job from repair_jobs where id=p_job_id for update;
  if not found then raise exception 'repair job not found'; end if;
  if p_amount > greatest(v_job.quoted_total-v_job.amount_paid,0) then raise exception 'payment exceeds outstanding repair balance'; end if;
  insert into repair_payments(job_id,amount,method,reference,note,received_by)
  values(p_job_id,p_amount,p_method,nullif(p_reference,''),nullif(p_note,''),auth.uid())
  returning id into v_payment_id;
  update repair_jobs set amount_paid=amount_paid+p_amount,
    balance=greatest(quoted_total-(amount_paid+p_amount),0),updated_at=now()
  where id=p_job_id;
  insert into repair_job_events(job_id,event_type,details,created_by)
  values(p_job_id,'payment',format('Payment received: %s UGX via %s',p_amount,p_method),auth.uid());
  return v_payment_id;
end $$;

create or replace function add_repair_part(
  p_job_id bigint, p_product_id bigint, p_quantity integer
) returns bigint
language plpgsql security definer set search_path=public as $$
declare v_job repair_jobs%rowtype; v_product products%rowtype; v_part_id bigint; v_line numeric;
begin
  if auth.uid() is null or not app_page_allowed('repairs',array['admin','manager']::user_role[]) then
    raise exception 'only an administrator or manager can issue spare parts';
  end if;
  if p_quantity is null or p_quantity < 1 then raise exception 'quantity must be at least one'; end if;
  select * into v_job from repair_jobs where id=p_job_id for update;
  if not found then raise exception 'repair job not found'; end if;
  if v_job.status in ('collected','cancelled') then raise exception 'closed repair jobs cannot consume parts'; end if;
  select * into v_product from products where id=p_product_id for update;
  if not found then raise exception 'spare part not found'; end if;
  if not v_product.track_inventory then raise exception 'this product is not tracked in inventory'; end if;
  if v_product.stock < p_quantity then raise exception 'insufficient spare-parts stock'; end if;
  v_line := v_product.price_cost * p_quantity;
  update products set stock=stock-p_quantity,updated_at=now() where id=p_product_id;
  insert into repair_job_parts(job_id,product_id,quantity,unit_cost,line_cost,added_by)
  values(p_job_id,p_product_id,p_quantity,v_product.price_cost,v_line,auth.uid())
  returning id into v_part_id;
  update repair_jobs set actual_parts_cost=actual_parts_cost+v_line,updated_at=now() where id=p_job_id;
  insert into repair_job_events(job_id,event_type,details,created_by)
  values(p_job_id,'spare_part',format('%s × %s issued from stock',p_quantity,v_product.name),auth.uid());
  return v_part_id;
end $$;

revoke all on function record_repair_payment(bigint,numeric,text,text,text) from public,anon,authenticated;
revoke all on function add_repair_part(bigint,bigint,integer) from public,anon,authenticated;
grant execute on function record_repair_payment(bigint,numeric,text,text,text) to authenticated;
grant execute on function add_repair_part(bigint,bigint,integer) to authenticated;
