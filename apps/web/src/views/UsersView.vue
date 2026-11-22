<script setup lang="ts">
import {onMounted,ref} from 'vue'
import {api} from '@/lib/api'
import {PAGES} from '@hybrid/shared'
import {supabase} from '@/lib/supabase'

type Branch={id:string;name:string;status:string}
type User={id:string;username:string;role:string;is_director?:boolean;phone:string|null;email:string|null;disabled:boolean;branch_id:string|null;photo_path?:string|null;id_photo_path?:string|null;cv_path?:string|null;created_at:string}
const users=ref<User[]>([]),branches=ref<Branch[]>([]),error=ref(''),busy=ref(false),uploading=ref(false),show=ref(false),username=ref(''),password=ref(''),role=ref('cashier'),phone=ref(''),email=ref(''),branchId=ref(''),selected=ref<User|null>(null),caps=ref<string[]>([]),overrides=ref<Record<string,boolean>>({})
const needsBranch=(value:string)=>value==='manager'||value==='cashier'
async function load(){try{const r=await api<{users:User[];branches:Branch[]}>('/v1/users');users.value=r.users;branches.value=r.branches??[]}catch(e:any){error.value=e.message}}
async function create(){busy.value=true;error.value='';try{await api('/v1/users',{method:'POST',body:JSON.stringify({username:username.value,password:password.value,role:role.value,phone:phone.value,email:email.value,branch_id:branchId.value||null})});show.value=false;username.value='';password.value='';phone.value='';email.value='';role.value='cashier';branchId.value='';await load()}catch(e:any){error.value=e.message}finally{busy.value=false}}
async function toggle(u:User){try{await api(`/v1/users/${u.id}`,{method:'PATCH',body:JSON.stringify({disabled:!u.disabled})});await load()}catch(e:any){error.value=e.message}}
async function changeRole(u:User,event:Event){const value=(event.target as HTMLSelectElement).value;try{await api(`/v1/users/${u.id}`,{method:'PATCH',body:JSON.stringify({role:value,branch_id:u.branch_id})});await load()}catch(e:any){error.value=e.message}}
async function changeBranch(u:User,event:Event){const value=(event.target as HTMLSelectElement).value;try{await api(`/v1/users/${u.id}`,{method:'PATCH',body:JSON.stringify({branch_id:value||null})});await load()}catch(e:any){error.value=e.message}}
async function editAccess(u:User){selected.value=u;error.value='';try{const r=await api<{caps:string[];pageOverrides:Record<string,boolean>}>(`/v1/users/${u.id}/access`);caps.value=r.caps;overrides.value=r.pageOverrides}catch(e:any){error.value=e.message}}
async function saveAccess(){if(!selected.value)return;busy.value=true;try{await api(`/v1/users/${selected.value.id}`,{method:'PATCH',body:JSON.stringify({caps:caps.value,pageOverrides:overrides.value})});selected.value=null;await load()}catch(e:any){error.value=e.message}finally{busy.value=false}}
function setPage(page:string,event:Event){const checked=(event.target as HTMLInputElement).checked;if(checked)overrides.value={...overrides.value,[page]:true};else{const next={...overrides.value};delete next[page];overrides.value=next}}
function resetOverrides(){overrides.value={}}
async function uploadDocument(kind:'photo'|'id'|'cv',event:Event){if(!selected.value)return;const file=(event.target as HTMLInputElement).files?.[0];if(!file)return;if(file.size>10*1024*1024){error.value='File must be 10 MB or smaller.';return}const allowed=kind==='photo'?file.type.startsWith('image/'):kind==='id'?(file.type.startsWith('image/')||file.type==='application/pdf'):(file.type==='application/pdf'||file.type.includes('word'));if(!allowed){error.value='Unsupported file type.';return}uploading.value=true;error.value='';try{const ext=(file.name.split('.').pop()??'bin').toLowerCase().replace(/[^a-z0-9]/g,'')||'bin';const path=`${selected.value.id}/${kind}-${Date.now()}.${ext}`;const bucket=kind==='photo'?'staff-photos':'staff-private';const {error:e}=await supabase.storage.from(bucket).upload(path,file,{contentType:file.type,upsert:false});if(e)throw e;const field=kind==='photo'?'photo_path':kind==='id'?'id_photo_path':'cv_path';await api(`/v1/users/${selected.value.id}`,{method:'PATCH',body:JSON.stringify({[field]:path})});await load();if(selected.value){const refreshed=users.value.find(x=>x.id===selected.value?.id);if(refreshed)selected.value=refreshed}}catch(e:any){error.value=e.message??'Upload failed'}finally{uploading.value=false;(event.target as HTMLInputElement).value=''}}
function denyPage(page:string,event:Event){if((event.target as HTMLInputElement).checked)overrides.value={...overrides.value,[page]:false};else{const next={...overrides.value};delete next[page];overrides.value=next}}
onMounted(load)
</script>
<template>
<section class="page-wrap">
 <div class="page-heading"><div><h1>Users & access</h1><p>Create staff accounts, assign roles, branches, capabilities and page access.</p></div><button class="btn primary" @click="show=true">＋ Add user</button></div>
 <p v-if="error" class="alert">{{error}}</p>
 <div class="table-scroll"><table><thead><tr><th>Username</th><th>Contact</th><th>Role</th><th>Branch</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
 <tbody><tr v-for="u in users" :key="u.id">
 <td>{{u.username}}</td><td>{{u.phone??'—'}}<br>{{u.email??''}}</td>
 <td><span v-if="u.is_director" class="role-label">Director</span><select v-else :value="u.role" class="control" @change="changeRole(u,$event)"><option value="admin">Admin</option><option value="manager">Manager</option><option value="cashier">Cashier</option></select></td>
 <td><select :value="u.branch_id??''" class="control" :required="needsBranch(u.role)" @change="changeBranch(u,$event)"><option value="">{{needsBranch(u.role)?'Select branch':'No branch'}}</option><option v-for="b in branches" :key="b.id" :value="b.id">{{b.name}}</option></select></td>
 <td>{{u.disabled?'Disabled':'Active'}}</td><td>{{new Date(u.created_at).toLocaleDateString()}}</td>
 <td class="actions"><button class="link" @click="editAccess(u)">Access</button><button class="link" @click="toggle(u)">{{u.disabled?'Enable':'Disable'}}</button></td>
 </tr></tbody></table></div>
 <div v-if="show" class="modal-backdrop" @click.self="show=false"><form class="modal" @submit.prevent="create">
 <h2>Create staff account</h2>
 <label>Username<input v-model="username" class="control" minlength="3" required></label>
 <label>Initial password<input v-model="password" type="password" class="control" minlength="10" required></label>
 <label>Role<select v-model="role" class="control"><option value="cashier">Cashier</option><option value="manager">Manager</option><option value="admin">Admin</option></select></label>
 <label>Branch
  <select v-model="branchId" class="control" :required="needsBranch(role)">
   <option value="">{{needsBranch(role)?'Select an active branch':'No branch (central account)'}}</option>
   <option v-for="b in branches" :key="b.id" :value="b.id">{{b.name}}</option>
  </select>
  <small v-if="needsBranch(role)">A branch is compulsory for managers and cashiers.</small>
  <small v-else>Branch assignment is optional for administrators.</small>
 </label>
 <label>Phone<input v-model="phone" class="control"></label><label>Email<input v-model="email" type="email" class="control"></label>
 <div class="modal-actions"><button type="button" class="btn" @click="show=false">Cancel</button><button class="btn primary" :disabled="busy||!branches.length&&needsBranch(role)">{{busy?'Creating…':'Create user'}}</button></div>
 </form></div>
 <div v-if="selected" class="modal-backdrop" @click.self="selected=null"><form class="modal" @submit.prevent="saveAccess">
 <h2>Access settings — {{selected.username}}</h2>
 <b>Staff documents</b><small>Uploads are private. Images are stored in the staff photo bucket; ID and CV files are restricted to administrators.</small>
 <label>Profile photo<input type="file" accept="image/*" :disabled="uploading" @change="uploadDocument('photo',$event)"></label><small>{{selected.photo_path?'Photo stored':'No photo uploaded'}}</small>
 <label>National ID image / PDF<input type="file" accept="image/*,application/pdf" :disabled="uploading" @change="uploadDocument('id',$event)"></label><small>{{selected.id_photo_path?'ID document stored':'No ID document uploaded'}}</small>
 <label>CV / résumé<input type="file" accept=".pdf,.doc,.docx,application/pdf" :disabled="uploading" @change="uploadDocument('cv',$event)"></label><small>{{selected.cv_path?'CV stored':'No CV uploaded'}}</small>
 <b>Capabilities</b><label class="check-row"><input type="checkbox" value="view_costs" v-model="caps"> View cost and profit data</label>
 <b>Page access overrides</b><button type="button" class="link" @click="resetOverrides">Reset all to role defaults</button><small>Unchecked pages use the selected role's normal access. Check a page to explicitly allow it; use the Deny checkbox to explicitly deny it.</small>
 <div v-for="page in PAGES" :key="page" class="access-row"><span>{{page.replaceAll('_',' ')}}</span><label><input type="checkbox" :checked="overrides[page]===true" @change="setPage(page,$event)"> Allow</label><label><input type="checkbox" :checked="overrides[page]===false" @change="denyPage(page,$event)"> Deny</label></div>
 <div class="modal-actions"><button type="button" class="btn" @click="selected=null">Cancel</button><button class="btn primary" :disabled="busy">{{busy?'Saving…':'Save access'}}</button></div>
 </form></div>
</section>
</template>
