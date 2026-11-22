import {Hono} from 'hono'
import {createClient} from '@supabase/supabase-js'
import type {Env} from '../env'
import {requireRole} from '../middleware/auth'

const r=new Hono<{Bindings:Env}>()
r.use('*',requireRole('admin'))
function db(env:Env){return createClient(env.SUPABASE_URL,env.SUPABASE_SERVICE_ROLE_KEY,{auth:{persistSession:false,autoRefreshToken:false}})}
const roles=['admin','manager','cashier'] as const
const branchRequired=(role:string)=>role==='manager'||role==='cashier'

r.get('/',async c=>{
 const client=db(c.env)
 const [{data,error},{data:branches, error:branchError}]=await Promise.all([
  client.from('profiles').select('id,username,role,phone,email,photo_path,id_photo_path,cv_path,disabled,created_at,branch_id').order('created_at',{ascending:false}),
  client.from('branches').select('id,name,status').eq('status','active').order('name')
 ])
 if(error||branchError)return c.json({error:error?.message??branchError?.message},400)
 return c.json({users:data,branches:branches??[]})
})
r.get('/branches',async c=>{
 const {data,error}=await db(c.env).from('branches').select('id,name,status').eq('status','active').order('name')
 if(error)return c.json({error:error.message},400)
 return c.json({branches:data??[]})
})
r.post('/',async c=>{
 const body=await c.req.json<{username:string;password:string;role:'admin'|'manager'|'cashier';phone?:string;email?:string;branch_id?:string|null}>()
 const username=String(body.username??'').trim().toLowerCase()
 if(!/^[a-z0-9._-]{3,40}$/.test(username)||String(body.password??'').length<10)return c.json({error:'Username must be 3–40 characters and password at least 10 characters.'},400)
 if(!roles.includes(body.role))return c.json({error:'Invalid role'},400)
 const branchId=String(body.branch_id??'').trim()
 if(branchRequired(body.role)&&!branchId)return c.json({error:'Select an active branch for this role before creating the account.'},400)
 const client=db(c.env)
 if(branchId){
  const {data:branch,error}=await client.from('branches').select('id').eq('id',branchId).eq('status','active').maybeSingle()
  if(error||!branch)return c.json({error:'The selected branch is unavailable or inactive. Choose an active branch.'},400)
 }
 const {data,error}=await client.auth.admin.createUser({email:`${username}@shop.local`,password:body.password,email_confirm:true,user_metadata:{username}})
 if(error||!data.user)return c.json({error:error?.message??'Unable to create auth user'},400)
 const {error:pe}=await client.from('profiles').insert({id:data.user.id,username,role:body.role,phone:body.phone??null,email:body.email??null,branch_id:branchId||null})
 if(pe){await client.auth.admin.deleteUser(data.user.id);return c.json({error:pe.message},400)}
 return c.json({id:data.user.id,username,branch_id:branchId||null},201)
})
r.get('/:id/access',async c=>{
 const client=db(c.env),id=c.req.param('id')
 const [caps,pages]=await Promise.all([client.from('user_caps').select('cap,allowed').eq('user_id',id),client.from('user_page_access').select('page,allowed').eq('user_id',id)])
 if(caps.error||pages.error)return c.json({error:caps.error?.message??pages.error?.message},400)
 return c.json({caps:(caps.data??[]).filter(x=>x.allowed).map(x=>x.cap),pageOverrides:Object.fromEntries((pages.data??[]).map(x=>[x.page,x.allowed]))})
})
r.patch('/:id',async c=>{
 const id=c.req.param('id')
 const body=await c.req.json<{role?:string;branch_id?:string|null;disabled?:boolean;phone?:string;email?:string;photo_path?:string;id_photo_path?:string;cv_path?:string;caps?:string[];pageOverrides?:Record<string,boolean>}>()
 const client=db(c.env)
 if(body.role&&!roles.includes(body.role as typeof roles[number]))return c.json({error:'Invalid role'},400)
 const {data:current,error:currentError}=await client.from('profiles').select('id,role,branch_id').eq('id',id).maybeSingle()
 if(currentError||!current)return c.json({error:'User account was not found.'},404)
 const nextRole=body.role??current.role
 const nextBranch=body.branch_id===undefined?current.branch_id:body.branch_id
 if(branchRequired(nextRole)&&!String(nextBranch??'').trim())return c.json({error:'A branch assignment is required for managers and cashiers.'},400)
 if(nextBranch){
  const {data:branch,error}=await client.from('branches').select('id').eq('id',nextBranch).eq('status','active').maybeSingle()
  if(error||!branch)return c.json({error:'The selected branch is unavailable or inactive. Choose an active branch.'},400)
 }
 const patch:Record<string,unknown>={}
 for(const k of ['role','disabled','phone','email','photo_path','id_photo_path','cv_path'] as const)if(body[k]!==undefined)patch[k]=body[k]
 if(body.branch_id!==undefined||body.role!==undefined)patch.branch_id=nextBranch||null
 if(Object.keys(patch).length){
  const {error}=await client.from('profiles').update(patch).eq('id',id)
  if(error)return c.json({error:error.message},400)
 }
 if(body.caps){await client.from('user_caps').delete().eq('user_id',id);if(body.caps.length){const {error}=await client.from('user_caps').insert(body.caps.map(cap=>({user_id:id,cap,allowed:true})));if(error)return c.json({error:error.message},400)}}
 if(body.pageOverrides){await client.from('user_page_access').delete().eq('user_id',id);const entries=Object.entries(body.pageOverrides).map(([page,allowed])=>({user_id:id,page,allowed}));if(entries.length){const {error}=await client.from('user_page_access').insert(entries);if(error)return c.json({error:error.message},400)}}
 if(body.disabled!==undefined){const {error}=await client.auth.admin.updateUserById(id,{ban_duration:body.disabled?'876000h':'none'});if(error)return c.json({error:error.message},400)}
 return c.json({ok:true})
})
r.post('/:id/password',async c=>{
 const body=await c.req.json<{password:string}>()
 if(String(body.password??'').length<10)return c.json({error:'Password must be at least 10 characters'},400)
 const {error}=await db(c.env).auth.admin.updateUserById(c.req.param('id'),{password:body.password})
 if(error)return c.json({error:error.message},400)
 return c.json({ok:true})
})
export default r
