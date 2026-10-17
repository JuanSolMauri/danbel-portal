-- Tablas del portal. Las crean automáticamente los scripts upload-erp y create-user.
create table if not exists erp_data (
  key text primary key,          -- families | products | discounts
  data jsonb not null,
  updated_at timestamptz not null default now()
);

create table if not exists users (
  id uuid primary key,
  login text not null unique,    -- email o código de cliente, en minúsculas
  role text not null check (role in ('admin', 'customer')),
  customer_code text,
  password_hash text not null,
  created_at timestamptz not null default now()
);
