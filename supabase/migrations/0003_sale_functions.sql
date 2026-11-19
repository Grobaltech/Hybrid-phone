-- DRAFT, UNTESTED. Transactional business logic. This replaces the PHP
-- beginTransaction + SELECT ... FOR UPDATE code in pages/sales.php, purchases.php, credits.php.

-- create_sale: one call = one transaction (all or nothing).
-- p_items example: [{"product_id": 1, "quantity": 2, "price_sell": 15000}]
create or replace function create_sale(
  p_customer_id    bigint,
  p_payment_method payment_method,
  p_tax_rate       numeric,
  p_notes          text,
  p_items          jsonb
) returns bigint
language plpgsql security definer set search_path = public as $$
declare
  v_sale_id bigint;
  v_item    jsonb;
  v_prod    products%rowtype;
  v_qty     integer;
  v_price   numeric;
  v_total   numeric := 0;
  v_cost    numeric := 0;
begin
  if not app_page_allowed('sales',array['admin','manager','cashier']::user_role[]) then raise exception 'not allowed'; end if;
  if p_tax_rate < 0 or p_tax_rate > 100 then raise exception 'invalid tax rate'; end if;
  if p_items is null or jsonb_typeof(p_items) <> 'array' or jsonb_array_length(p_items) = 0 then raise exception 'sale must contain items'; end if;
  if p_payment_method = 'credit' and p_customer_id is null then
    raise exception 'credit sales need a customer';
  end if;

  insert into sales (customer_id, total, total_cost, profit, tax_rate, payment_method, notes, created_by)
  values (p_customer_id, 0, 0, 0, p_tax_rate, p_payment_method, p_notes, auth.uid())
  returning id into v_sale_id;

  for v_item in select * from jsonb_array_elements(p_items) loop
    v_qty   := (v_item->>'quantity')::int;
    v_price := (v_item->>'price_sell')::numeric;
    if v_qty <= 0 or v_price < 0 then raise exception 'invalid quantity or selling price'; end if;

    select * into v_prod from products where id = (v_item->>'product_id')::bigint for update;
    if not found then raise exception 'unknown product'; end if;
    if coalesce((to_jsonb(v_prod)->>'track_inventory')::boolean,true) and v_prod.stock < v_qty then raise exception 'not enough stock for %', v_prod.name; end if;

    if coalesce((to_jsonb(v_prod)->>'track_inventory')::boolean,true) then
      update products set stock = stock - v_qty where id = v_prod.id;
    end if;

    insert into sale_items (sale_id, product_id, quantity, price_sell, actual_price,
                            price_cost, line_total, line_cost, line_profit)
    values (v_sale_id, v_prod.id, v_qty, v_prod.price_sell, v_price, v_prod.price_cost,
            v_price * v_qty, v_prod.price_cost * v_qty, (v_price - v_prod.price_cost) * v_qty);

    v_total := v_total + v_price * v_qty;
    v_cost  := v_cost  + v_prod.price_cost * v_qty;
  end loop;

  update sales set
    total       = v_total,
    total_cost  = v_cost,
    profit      = v_total - v_cost,
    tax_amount  = round(v_total * p_tax_rate / 100, 2),
    grand_total = v_total + round(v_total * p_tax_rate / 100, 2),
    receipt_no  = 'RCPT-' || to_char(now(),'YYYYMMDD') || '-' || lpad(v_sale_id::text, 6, '0')
  where id = v_sale_id;

  if p_payment_method = 'credit' then
    insert into credits (customer_id, sale_id, amount, balance)
    select p_customer_id, id, grand_total, grand_total from sales where id = v_sale_id;
  end if;

  return v_sale_id;
end $$;

-- Purchase, repayment, audit and grants are completed in migrations 0005 and 0007.
