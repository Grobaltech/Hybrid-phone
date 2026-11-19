-- Preserve legacy product quantity metadata and add database-side audit events.
alter table products add column if not exists quantity integer not null default 0;

create or replace function audit_business_change() returns trigger
language plpgsql security definer set search_path=public as $$
declare v_id bigint; v_kind text; v_action text; v_username text;
begin
 v_kind:=tg_table_name;
 if tg_op='DELETE' then v_id:=old.id;v_action:=lower(tg_op);else v_id:=new.id;v_action:=lower(tg_op);end if;
 select username into v_username from profiles where id=auth.uid();
 insert into audit_logs(user_id,username,action,entity_type,entity_id,message,details,page)
 values(auth.uid(),v_username,v_kind||'.'||v_action,v_kind,v_id,null,jsonb_build_object('operation',tg_op),'database');
 if tg_op='DELETE' then return old; else return new; end if;
end $$;

do $$ declare t text; begin
 foreach t in array array['products','suppliers','purchases','sales','credits','creditors','expenses'] loop
  execute format('drop trigger if exists audit_%I on %I',t,t);
  execute format('create trigger audit_%I after insert or update or delete on %I for each row execute function audit_business_change()',t,t);
 end loop;
end $$;

-- Creditors are created from the opening-balance form and repaid only by the RPC.
revoke update,delete on creditors from authenticated;
grant insert on creditors to authenticated;
