-- FAKE DEVELOPMENT DATA ONLY. Never paste real customers, sales or staff here.

insert into settings (k, v) values
  ('company_name',    'Demo Shop'),
  ('company_address', '1 Example Street'),
  ('company_phone',   '000000000'),
  ('company_email',   'demo@example.com');

insert into suppliers (name, phone) values ('Demo Supplier', '000000001');

insert into products (sku, name, category, price_cost, price_sell, stock) values
  ('DEMO-001', 'Demo Phone Case',   'Accessories',  5000,  12000, 25),
  ('DEMO-002', 'Demo Charger',      'Accessories',  8000,  20000, 15),
  ('DEMO-003', 'Demo Screen Repair','Service',          0,  50000,  0);

insert into customers (name, phone) values ('Demo Customer', '000000002');

-- Users are created through Supabase Auth (dashboard or the API), then a `profiles` row is added.
