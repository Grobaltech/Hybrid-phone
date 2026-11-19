-- Production hardening and transactional workflows for Cloudflare/Supabase.
-- Apply after 0001-0004 in Supabase SQL Editor or with `supabase db push`.

-- Normalize legacy-compatible fields used by the old PHP application.
alter table purchases add column if not exists total numeric(14,2);
alter table credits add column if not exists sale_id bigint references sales(id);
alter table credit_payments add column if not exists sale_id bigint references sales(id);
alter table credit_payments add column if not exists method text not null default 'cash';
alter table credit_payments add column if not exists notes text;
alter table creditors add column if not exists updated_at timestamptz;
alter table products add column if not exists updated_at timestamptz;
alter table products add column if not exists track_inventory boolean not null default true;
alter table customers add column if not exists address text;

-- Remove incomplete starter policies and apply explicit role-based access.
drop policy if exists profiles_self_or_admin on profiles;
drop policy if exists products_read on products;
drop policy if exists products_write on products;
drop policy if exists customers_rw on customers;

do $$ declare t text; begin
  foreach t in array array['user_caps','user_page_access','suppliers','purchases','purchase_items','sales','sale_items','credits','credit_payments','creditors','creditor_payments','expenses','settings','audit_logs'] loop
    execute format('alter table %I enable row level security',t);
  end loop;
end $$;

create or replace function app_role() returns user_role
language sql stable security definer set search_path=public as $$
 select role from profiles where id=auth.uid() and disabled=false
$$;
create or replace function app_has_cap(p_cap text) returns boolean
language sql stable security definer set search_path=public as $$
 select app_role()='admin' or exists(select 1 from user_caps where user_id=auth.uid() and cap=p_cap and allowed)
$$;
create or replace function app_page_allowed(p_page text, p_roles user_role[])
returns boolean language sql stable security definer set search_path=public as $$
 select coalesce((select allowed from user_page_access where user_id=auth.uid() and page=p_page), app_role()='admin' or app_role()=any(p_roles))
$$;

create policy profiles_read on profiles for select to authenticated using (id=auth.uid() or app_role()='admin');
create policy profiles_update on profiles for update to authenticated using (id=auth.uid() or app_role()='admin') with check (id=auth.uid() or app_role()='admin');
create policy caps_read on user_caps for select to authenticated using (user_id=auth.uid() or app_role()='admin');
create policy caps_admin on user_caps for all to authenticated using (app_role()='admin') with check (app_role()='admin');
create policy pages_read on user_page_access for select to authenticated using (user_id=auth.uid() or app_role()='admin');
create policy pages_admin on user_page_access for all to authenticated using (app_role()='admin') with check (app_role()='admin');

create policy products_read on products for select to authenticated using (app_role() in ('admin','manager','cashier'));
create policy products_write on products for all to authenticated using (app_page_allowed('products',array['admin','manager']::user_role[])) with check (app_page_allowed('products',array['admin','manager']::user_role[]));
create policy customers_read_write on customers for all to authenticated using (app_role() in ('admin','manager','cashier')) with check (app_role() in ('admin','manager','cashier'));
create policy suppliers_read on suppliers for select to authenticated using (app_page_allowed('suppliers',array['admin']::user_role[]));
create policy suppliers_write on suppliers for all to authenticated using (app_page_allowed('suppliers',array['admin']::user_role[])) with check (app_page_allowed('suppliers',array['admin']::user_role[]));
create policy purchases_read on purchases for select to authenticated using (app_page_allowed('purchases',array['admin','manager']::user_role[]));
create policy purchases_write on purchases for all to authenticated using (app_page_allowed('purchases',array['admin','manager']::user_role[])) with check (app_page_allowed('purchases',array['admin','manager']::user_role[]));
create policy purchase_items_read on purchase_items for select to authenticated using (app_page_allowed('purchases',array['admin','manager']::user_role[]));
create policy purchase_items_write on purchase_items for all to authenticated using (app_page_allowed('purchases',array['admin','manager']::user_role[])) with check (app_page_allowed('purchases',array['admin','manager']::user_role[]));
create policy sales_read on sales for select to authenticated using (app_role() in ('admin','manager','cashier'));
create policy sale_items_read on sale_items for select to authenticated using (app_role() in ('admin','manager','cashier'));
create policy credits_read on credits for select to authenticated using (app_page_allowed('credits',array['admin','manager','cashier']::user_role[]));
create policy credit_payments_read on credit_payments for select to authenticated using (app_page_allowed('credits',array['admin','manager','cashier']::user_role[]));
create policy credits_write on credits for update to authenticated using (app_role() in ('admin','manager','cashier')) with check (app_role() in ('admin','manager','cashier'));
create policy creditors_read on creditors for select to authenticated using (app_page_allowed('creditors',array['admin','manager']::user_role[]));
create policy creditors_write on creditors for all to authenticated using (app_page_allowed('creditors',array['admin','manager']::user_role[])) with check (app_page_allowed('creditors',array['admin','manager']::user_role[]));
create policy creditor_payments_read on creditor_payments for select to authenticated using (app_page_allowed('creditors',array['admin','manager']::user_role[]));
create policy expenses_read on expenses for select to authenticated using (app_page_allowed('expenses',array['admin','manager']::user_role[]));
create policy expenses_write on expenses for all to authenticated using (app_page_allowed('expenses',array['admin','manager']::user_role[])) with check (app_page_allowed('expenses',array['admin','manager']::user_role[]));
create policy settings_read on settings for select to authenticated using (app_page_allowed('settings',array['admin']::user_role[]));
create policy settings_write on settings for all to authenticated using (app_page_allowed('settings',array['admin']::user_role[])) with check (app_page_allowed('settings',array['admin']::user_role[]));
create policy audit_admin_read on audit_logs for select to authenticated using (app_page_allowed('activity',array['admin']::user_role[]));

-- Cost/profit columns are only selectable by users with the view_costs capability.
-- Managers/admins are not implicitly granted cost visibility except admins.
create or replace view products_for_sale with (security_invoker=true) as
 select id,sku,name,category,barcode,price_sell,stock,track_inventory,created_at,updated_at from products;
grant select on products_for_sale to authenticated;

-- One atomic purchase receipt: creates header and items, adds stock, updates cost.
create or replace function receive_purchase(p_supplier_id bigint,p_reference text,p_items jsonb)
returns bigint language plpgsql security definer set search_path=public as $$
declare v_id bigint; v jsonb; v_product products%rowtype; v_qty integer; v_cost numeric; v_total numeric:=0;
begin
 if not app_page_allowed('purchases',array['admin','manager']::user_role[]) then raise exception 'not allowed'; end if;
 if p_items is null or jsonb_typeof(p_items)<>'array' or jsonb_array_length(p_items)=0 then raise exception 'purchase must contain items'; end if;
 insert into purchases(supplier_id,reference,total_cost,total,status,received_at) values(p_supplier_id,nullif(p_reference,''),0,0,'received',now()) returning id into v_id;
 for v in select * from jsonb_array_elements(p_items) loop
  v_qty:=(v->>'quantity')::integer; v_cost:=(v->>'unit_cost')::numeric;
  if v_qty<=0 or v_cost<0 then raise exception 'invalid purchase quantity or cost'; end if;
  select * into v_product from products where id=(v->>'product_id')::bigint for update;
  if not found then raise exception 'unknown product'; end if;
  insert into purchase_items(purchase_id,product_id,quantity,unit_cost,line_cost) values(v_id,v_product.id,v_qty,v_cost,v_qty*v_cost);
  update products set stock=case when track_inventory then stock+v_qty else stock end,price_cost=v_cost,updated_at=now() where id=v_product.id;
  v_total:=v_total+v_qty*v_cost;
 end loop;
 update purchases set total_cost=v_total,total=v_total where id=v_id;
 return v_id;
end $$;

create or replace function record_credit_payment(p_credit_id bigint,p_amount numeric,p_method text default 'cash',p_note text default null)
returns bigint language plpgsql security definer set search_path=public as $$
declare v credits%rowtype; v_id bigint;
begin
 if not app_page_allowed('credits',array['admin','manager','cashier']::user_role[]) then raise exception 'not allowed'; end if;
 if p_amount<=0 then raise exception 'payment must be greater than zero'; end if;
 select * into v from credits where id=p_credit_id for update;
 if not found then raise exception 'credit not found'; end if;
 if p_amount>v.balance then raise exception 'payment exceeds outstanding balance'; end if;
 insert into credit_payments(credit_id,sale_id,amount,method,note,notes) values(v.id,v.sale_id,p_amount,coalesce(p_method,'cash'),p_note,p_note) returning id into v_id;
 update credits set balance=balance-p_amount,status=case when balance-p_amount<=0 then 'closed'::credit_status else 'open'::credit_status,updated_at=now() where id=v.id;
 return v_id;
end $$;

create or replace function record_creditor_payment(p_creditor_id bigint,p_amount numeric,p_note text default null)
returns bigint language plpgsql security definer set search_path=public as $$
declare v creditors%rowtype; v_id bigint;
begin
 if not app_page_allowed('creditors',array['admin','manager']::user_role[]) then raise exception 'not allowed'; end if;
 if p_amount<=0 then raise exception 'payment must be greater than zero'; end if;
 select * into v from creditors where id=p_creditor_id for update;
 if not found then raise exception 'creditor not found'; end if;
 if p_amount>v.balance then raise exception 'payment exceeds outstanding balance'; end if;
 insert into creditor_payments(creditor_id,amount,note) values(v.id,p_amount,p_note) returning id into v_id;
 update creditors set amount_paid=amount_paid+p_amount,balance=balance-p_amount,updated_at=now() where id=v.id;
 return v_id;
end $$;

revoke all on function receive_purchase(bigint,text,jsonb) from public, anon, authenticated;
revoke all on function record_credit_payment(bigint,numeric,text,text) from public, anon, authenticated;
revoke all on function record_creditor_payment(bigint,numeric,text) from public, anon, authenticated;
grant execute on function record_credit_payment(bigint,numeric,text,text) to authenticated;
grant execute on function record_creditor_payment(bigint,numeric,text) to authenticated;

-- RPCs are the only supported write path for sale headers and line items.
revoke insert,update,delete on sales,sale_items from authenticated;
revoke insert,update,delete on purchases,purchase_items from authenticated;
revoke insert,update,delete on credits,credit_payments,creditor_payments from authenticated;
revoke all on function create_sale(bigint,payment_method,numeric,text,jsonb) from public, anon, authenticated;
grant execute on function create_sale(bigint,payment_method,numeric,text,jsonb) to authenticated;

-- Prevent a user from changing their own role or disabled flag through the browser.
revoke update on profiles from authenticated;
grant update(phone,email,about) on profiles to authenticated;

-- Create an ordered purchase or receive it immediately. Ordered items do not
-- affect inventory until receive_purchase(p_purchase_id) is called.
create or replace function create_purchase(p_supplier_id bigint,p_reference text,p_items jsonb,p_status purchase_status default 'received')
returns bigint language plpgsql security definer set search_path=public as $$
declare v_id bigint; v jsonb; v_product products%rowtype; v_qty integer; v_cost numeric; v_total numeric:=0; v_packs integer; v_pack_size integer;
begin
 if not app_page_allowed('purchases',array['admin','manager']::user_role[]) then raise exception 'not allowed'; end if;
 if p_items is null or jsonb_typeof(p_items)<>'array' or jsonb_array_length(p_items)=0 then raise exception 'purchase must contain items'; end if;
 insert into purchases(supplier_id,reference,total_cost,total,status,received_at) values(p_supplier_id,nullif(p_reference,''),0,0,p_status,case when p_status='received' then now() else null end) returning id into v_id;
 for v in select * from jsonb_array_elements(p_items) loop
  v_qty:=(v->>'quantity')::integer; v_cost:=(v->>'unit_cost')::numeric;
  v_packs:=nullif(v->>'packs','')::integer;v_pack_size:=nullif(v->>'pack_size','')::integer;
  if v_qty<=0 or v_cost<0 then raise exception 'invalid purchase quantity or cost'; end if;
  select * into v_product from products where id=(v->>'product_id')::bigint;
  if not found then raise exception 'unknown product'; end if;
  insert into purchase_items(purchase_id,product_id,quantity,packs,pack_size,unit_cost,line_cost) values(v_id,v_product.id,v_qty,v_packs,v_pack_size,v_cost,v_qty*v_cost);
  if p_status='received' then update products set stock=case when track_inventory then stock+v_qty else stock end,price_cost=v_cost,updated_at=now() where id=v_product.id; end if;
  v_total:=v_total+v_qty*v_cost;
 end loop;
 update purchases set total_cost=v_total,total=v_total where id=v_id;
 return v_id;
end $$;

create or replace function receive_purchase(p_purchase_id bigint)
returns void language plpgsql security definer set search_path=public as $$
declare v_purchase purchases%rowtype; v_item record;
begin
 if not app_page_allowed('purchases',array['admin','manager']::user_role[]) then raise exception 'not allowed'; end if;
 select * into v_purchase from purchases where id=p_purchase_id for update;
 if not found then raise exception 'purchase not found'; end if;
 if v_purchase.status='received' then raise exception 'purchase already received'; end if;
 for v_item in select product_id,quantity,unit_cost from purchase_items where purchase_id=p_purchase_id loop
  update products set stock=case when track_inventory then stock+v_item.quantity else stock end,price_cost=v_item.unit_cost,updated_at=now() where id=v_item.product_id;
 end loop;
 update purchases set status='received',received_at=now() where id=p_purchase_id;
end $$;

revoke all on function create_purchase(bigint,text,jsonb,purchase_status) from public, anon, authenticated;
revoke all on function receive_purchase(bigint) from public, anon, authenticated;
grant execute on function create_purchase(bigint,text,jsonb,purchase_status) to authenticated;
grant execute on function receive_purchase(bigint) to authenticated;
