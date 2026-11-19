-- CRM and CMS data used by the admin workspace.
create table if not exists crm_notes(
 id bigint generated always as identity primary key,
 customer_id bigint not null references customers(id) on delete cascade,
 note text not null,
 created_by uuid references profiles(id),
 created_at timestamptz not null default now()
);
create table if not exists crm_followups(
 id bigint generated always as identity primary key,
 customer_id bigint not null references customers(id) on delete cascade,
 due_at timestamptz not null,
 subject text not null,
 notes text,
 status text not null default 'open' check(status in ('open','done','cancelled')),
 created_by uuid references profiles(id),
 created_at timestamptz not null default now()
);
create table if not exists site_content(
 id bigint generated always as identity primary key,
 slug text not null unique,
 title text not null,
 body text,
 image_url text,
 published boolean not null default false,
 updated_by uuid references profiles(id),
 updated_at timestamptz not null default now()
);
alter table crm_notes enable row level security;alter table crm_followups enable row level security;alter table site_content enable row level security;
create policy crm_notes_access on crm_notes for all to authenticated using(app_page_allowed('crm',array['admin']::user_role[])) with check(app_page_allowed('crm',array['admin']::user_role[]));
create policy crm_followups_access on crm_followups for all to authenticated using(app_page_allowed('crm',array['admin']::user_role[])) with check(app_page_allowed('crm',array['admin']::user_role[]));
create policy site_content_read on site_content for select to authenticated using(app_page_allowed('cms',array['admin']::user_role[]));
create policy site_content_write on site_content for all to authenticated using(app_page_allowed('cms',array['admin']::user_role[])) with check(app_page_allowed('cms',array['admin']::user_role[]));
