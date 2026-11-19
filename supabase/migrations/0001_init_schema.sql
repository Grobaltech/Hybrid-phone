-- Core PostgreSQL schema for the Hybrid Shop cloud application.

create extension if not exists pgcrypto;

-- ---------------------------------------------------------------- enums
create type user_role      as enum ('admin', 'manager', 'cashier');
create type payment_method as enum ('cash', 'credit');
create type credit_status  as enum ('open', 'closed');
create type purchase_status as enum ('ordered', 'received');

-- ---------------------------------------------------------------- users
-- Auth lives in auth.users (Supabase). This is the app-level profile.
create table profiles (
  id             uuid primary key references auth.users (id) on delete cascade,
  legacy_user_id integer unique,              -- old users.id, for the data import
  username       text not null unique,
  role           user_role not null default 'cashier',
  phone          text,
  email          text,
  national_id    text,                        -- sensitive: admin-only via RLS
  about          text,
  photo_path     text,                        -- Storage paths (private buckets)
  id_photo_path  text,
  cv_path        text,
  disabled       boolean not null default false,
  created_at     timestamptz not null default now()
);

create table user_caps (
  user_id uuid not null references profiles (id) on delete cascade,
  cap     text not null,
  allowed boolean not null default true,
  primary key (user_id, cap)
);

create table user_page_access (
  user_id uuid not null references profiles (id) on delete cascade,
  page    text not null,
  allowed boolean not null default true,
  primary key (user_id, page)
);

-- ---------------------------------------------------------------- catalog
create table suppliers (
  id         bigint generated always as identity primary key,
  name       text not null,
  phone      text,
  email      text,
  created_at timestamptz not null default now()
);

-- `stock` is the live inventory counter; legacy `quantity` metadata is retained in migration 0007.
create table products (
  id         bigint generated always as identity primary key,
  sku        text not null unique,
  name       text not null,
  category   text,
  barcode    text,
  price_cost numeric(14,2) not null default 0,   -- restricted by column grants and cost-reporting RPCs
  price_sell numeric(14,2) not null default 0,
  stock      integer not null default 0 check (stock >= 0),
  track_inventory boolean not null default true,
  created_at timestamptz not null default now()
);
create index products_name_idx on products (lower(name));

create table customers (
  id         bigint generated always as identity primary key,
  name       text not null,
  phone      text,
  email      text,
  created_at timestamptz not null default now()
);
create index customers_name_idx  on customers (lower(name));
create index customers_phone_idx on customers (phone);

-- ---------------------------------------------------------------- purchasing
create table purchases (
  id          bigint generated always as identity primary key,
  supplier_id bigint references suppliers (id),
  reference   text,
  total_cost  numeric(14,2) not null default 0,
  status      purchase_status not null default 'received',
  received_at timestamptz,
  created_at  timestamptz not null default now()
);

create table purchase_items (
  id          bigint generated always as identity primary key,
  purchase_id bigint not null references purchases (id) on delete cascade,
  product_id  bigint not null references products (id),
  quantity    integer not null,
  packs       integer,
  pack_size   integer,
  unit_cost   numeric(14,2) not null,
  line_cost   numeric(14,2) not null
);

-- ---------------------------------------------------------------- sales
create table sales (
  id             bigint generated always as identity primary key,
  receipt_no     text unique,
  customer_id    bigint references customers (id),
  total          numeric(14,2) not null,
  total_cost     numeric(14,2) not null,
  profit         numeric(14,2) not null,
  tax_rate       numeric(5,2)  not null default 0,
  tax_amount     numeric(14,2) not null default 0,
  grand_total    numeric(14,2) not null default 0,
  payment_method payment_method not null default 'cash',
  notes          text,
  created_by     uuid references profiles (id),   -- NEW: who made the sale
  created_at     timestamptz not null default now()
);
create index sales_created_idx  on sales (created_at);
create index sales_customer_idx on sales (customer_id);

create table sale_items (
  id           bigint generated always as identity primary key,
  sale_id      bigint not null references sales (id) on delete cascade,
  product_id   bigint not null references products (id),
  quantity     integer not null,
  price_sell   numeric(14,2) not null,
  actual_price numeric(14,2) not null default 0,   -- actual price charged
  price_cost   numeric(14,2) not null,
  line_total   numeric(14,2) not null,
  line_cost    numeric(14,2) not null,
  line_profit  numeric(14,2) not null
);
create index sale_items_sale_idx on sale_items (sale_id);

-- ---------------------------------------------------------------- credit (customers owe us)
create table credits (
  id          bigint generated always as identity primary key,
  customer_id bigint references customers (id),
  sale_id     bigint not null references sales (id),
  amount      numeric(14,2) not null,
  balance     numeric(14,2) not null,
  status      credit_status not null default 'open',
  created_at  timestamptz not null default now(),
  updated_at  timestamptz
);

create table credit_payments (
  id        bigint generated always as identity primary key,
  credit_id bigint not null references credits (id) on delete cascade,
  amount    numeric(14,2) not null,
  note      text,
  paid_at   timestamptz not null default now()
);

-- ---------------------------------------------------------------- creditors (we owe others)
create table creditors (
  id           bigint generated always as identity primary key,
  name         text not null,
  phone        text,
  description  text,
  total_credit numeric(14,2) not null default 0,
  amount_paid  numeric(14,2) not null default 0,
  balance      numeric(14,2) not null default 0,
  created_at   timestamptz not null default now()
);

create table creditor_payments (
  id          bigint generated always as identity primary key,
  creditor_id bigint not null references creditors (id) on delete cascade,
  amount      numeric(14,2) not null,
  note        text,
  paid_at     timestamptz not null default now()
);

-- ---------------------------------------------------------------- misc
create table expenses (
  id         bigint generated always as identity primary key,
  date       date not null,
  category   text not null,
  amount     numeric(14,2) not null,
  note       text,
  created_at timestamptz not null default now()
);

create table settings (
  k text primary key,
  v text
);

-- Legacy audit_log and audit_logs are imported into this unified table.
create table audit_logs (
  id          bigint generated always as identity primary key,
  user_id     uuid references profiles (id),
  username    text,
  action      text not null,
  entity_type text,
  entity_id   bigint,
  message     text,
  details     jsonb,
  page        text,
  ip          text,
  user_agent  text,
  created_at  timestamptz not null default now()
);
create index audit_logs_created_idx on audit_logs (created_at);
