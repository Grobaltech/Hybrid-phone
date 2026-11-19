-- Do not expose cost/profit snapshots through direct browser table reads.
revoke select on products from authenticated;
grant select(id,sku,name,category,barcode,price_sell,stock,track_inventory,quantity,created_at,updated_at) on products to authenticated;
revoke select on sales from authenticated;
grant select(id,receipt_no,customer_id,total,tax_rate,tax_amount,grand_total,payment_method,notes,created_by,created_at) on sales to authenticated;
revoke select on sale_items from authenticated;
grant select(id,sale_id,product_id,quantity,price_sell,actual_price,line_total) on sale_items to authenticated;

create or replace view sales_public with (security_invoker=true) as
 select id,receipt_no,customer_id,total,tax_rate,tax_amount,grand_total,payment_method,notes,created_by,created_at from sales;
grant select on sales_public to authenticated;

create or replace view sales_reporting with (security_invoker=false) as
 select id,receipt_no,customer_id,total,tax_rate,tax_amount,grand_total,payment_method,created_at,
        case when app_has_cap('view_costs') then total_cost else null end as total_cost,
        case when app_has_cap('view_costs') then profit else null end as profit
 from sales where app_page_allowed('reports',array['admin','manager']::user_role[]);
grant select on sales_reporting to authenticated;

create or replace function inventory_value() returns numeric
language plpgsql security definer set search_path=public as $$
declare v numeric;
begin
 if app_role() is null or not app_has_cap('view_costs') then raise exception 'view_costs permission required'; end if;
 select coalesce(sum(stock*price_cost),0) into v from products;
 return v;
end $$;
revoke all on function inventory_value() from public, anon, authenticated;
grant execute on function inventory_value() to authenticated;
