import { defineStore } from 'pinia'
import type { Role, Cap, PageKey } from '@hybrid/shared'
import { supabase } from '@/lib/supabase'
interface Profile { id:string; username:string; role:Role; caps:Cap[]; pageOverrides:Partial<Record<PageKey,boolean>>; phone?:string|null; email?:string|null }
export const useAuth=defineStore('auth',{state:()=>({profile:null as Profile|null,ready:false}),getters:{isAdmin:s=>s.profile?.role==='admin'},actions:{
 async login(username:string,password:string){const {error}=await supabase.auth.signInWithPassword({email:`${username.trim().toLowerCase()}@shop.local`,password});if(error)throw error;await this.loadProfile();if(!this.profile){await supabase.auth.signOut();throw new Error('This account has no active shop profile. Ask an administrator to provision it.')}},
 async loadProfile(){const {data:{session}}=await supabase.auth.getSession();if(!session){this.profile=null;this.ready=true;return}const {data:p,error}=await supabase.from('profiles').select('id,username,role,phone,email,disabled').eq('id',session.user.id).maybeSingle();if(error||!p||p.disabled){this.profile=null;this.ready=true;return}const [{data:c},{data:a}]=await Promise.all([supabase.from('user_caps').select('cap').eq('user_id',p.id).eq('allowed',true),supabase.from('user_page_access').select('page,allowed').eq('user_id',p.id)]);this.profile={...p,caps:(c??[]).map(x=>x.cap as Cap),pageOverrides:Object.fromEntries((a??[]).map(x=>[x.page,x.allowed])) as Partial<Record<PageKey,boolean>>};this.ready=true},
 async logout(){await supabase.auth.signOut();this.profile=null;this.ready=true},
 can(page:PageKey,roles:readonly (Role|'*')[]){const p=this.profile;if(!p)return false;const override=p.pageOverrides[page];if(override!==undefined)return override;if(p.role==='admin')return true;return roles.includes('*')||roles.includes(p.role)}
}})
