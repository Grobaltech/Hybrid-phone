import type {MiddlewareHandler} from 'hono'
import {createClient} from '@supabase/supabase-js'
import type {Env} from '../env'
export type AppUser={id:string;username:string;role:string;disabled:boolean}
declare module 'hono' { interface ContextVariableMap { user:AppUser } }
export const requireAuth:MiddlewareHandler<{Bindings:Env}>=async(c,next)=>{
 const header=c.req.header('Authorization')??'';const token=header.match(/^Bearer\s+(.+)$/i)?.[1];if(!token)return c.json({error:'unauthorized'},401)
 const supabase=createClient(c.env.SUPABASE_URL,c.env.SUPABASE_SERVICE_ROLE_KEY,{auth:{persistSession:false,autoRefreshToken:false}})
 const {data:{user},error}=await supabase.auth.getUser(token);if(error||!user)return c.json({error:'invalid_session'},401)
 const {data:profile}=await supabase.from('profiles').select('id,username,role,disabled').eq('id',user.id).maybeSingle()
 if(!profile||profile.disabled)return c.json({error:'account_disabled'},403)
 c.set('user',profile as AppUser);await next()
}
export function requireRole(...roles:string[]):MiddlewareHandler<{Bindings:Env}>{return async(c,next)=>{if(!roles.includes(c.get('user')?.role??''))return c.json({error:'forbidden'},403);await next()}}
