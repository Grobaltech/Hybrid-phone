-- Private staff documents and public brand assets. Buckets are created idempotently.
insert into storage.buckets(id,name,public) values
 ('branding','branding',true),
 ('staff-photos','staff-photos',false),
 ('staff-private','staff-private',false)
on conflict(id) do update set public=excluded.public;

drop policy if exists branding_public_read on storage.objects;
drop policy if exists branding_admin_write on storage.objects;
drop policy if exists staff_photos_owner_admin on storage.objects;
drop policy if exists staff_photos_write on storage.objects;
drop policy if exists staff_private_admin on storage.objects;
create policy branding_public_read on storage.objects for select to anon,authenticated using(bucket_id='branding');
create policy branding_admin_write on storage.objects for all to authenticated using(bucket_id='branding' and app_role()='admin') with check(bucket_id='branding' and app_role()='admin');
create policy staff_photos_owner_admin on storage.objects for select to authenticated using(bucket_id='staff-photos' and (app_role()='admin' or (storage.foldername(name))[1]=auth.uid()::text));
create policy staff_photos_write on storage.objects for all to authenticated using(bucket_id='staff-photos' and (app_role()='admin' or (storage.foldername(name))[1]=auth.uid()::text)) with check(bucket_id='staff-photos' and (app_role()='admin' or (storage.foldername(name))[1]=auth.uid()::text));
create policy staff_private_admin on storage.objects for all to authenticated using(bucket_id='staff-private' and app_role()='admin') with check(bucket_id='staff-private' and app_role()='admin');
