<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { supabase } from '@/lib/supabase'
import { useAuth } from '@/stores/auth'

type RepairJob = {
  id:number; job_no:string; customer_type:'hybrid'|'external'; customer_id:number|null
  customer_name:string; customer_phone:string; customer_email:string|null
  brand:string; model:string; imei:string|null; serial_number:string|null
  reported_fault:string; intake_condition:string|null; accessories_received:string|null
  warranty_status:string; warranty_reference:string|null; warranty_notes:string|null
  purchase_sale_id:number|null; diagnosis:string|null; quote_labour:number; quote_parts:number
  quoted_total:number; actual_parts_cost:number; amount_paid:number; balance:number
  status:string; assigned_to:string|null; repair_warranty_days:number
  created_at:string; updated_at:string
}
type Product = {id:number;name:string;sku:string;stock:number;track_inventory:boolean}
type Staff = {id:string;username:string;role:string}
const auth=useAuth()
const jobs=ref<RepairJob[]>([]), products=ref<Product[]>([]), staff=ref<Staff[]>([])
const selected=ref<RepairJob|null>(null), loading=ref(true), saving=ref(false), error=ref(''), success=ref('')
const query=ref(''), statusFilter=ref('all'), showIntake=ref(false), showPayment=ref(false), showPart=ref(false)
const intake=ref({customer_type:'external' as 'hybrid'|'external',customer_id:'',customer_name:'',customer_phone:'',customer_email:'',brand:'',model:'',imei:'',serial_number:'',reported_fault:'',intake_condition:'',accessories_received:'',warranty_status:'not_applicable',warranty_reference:'',warranty_notes:''})
const edit=ref({diagnosis:'',status:'received',assigned_to:'',quote_labour:0,quote_parts:0,repair_warranty_days:30,internal_notes:''})
const payment=ref({amount:0,method:'cash',reference:'',note:''})
const part=ref({product_id:'',quantity:1})
const customers=ref<{id:number;name:string;phone:string|null;email:string|null}[]>([])
const statuses=['received','diagnosing','awaiting_approval','approved','repairing','testing','ready','collected','unrepairable','cancelled']
const statusLabel=(s:string)=>s.replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase())
const money=(n:number|string)=>'UGX '+Number(n||0).toLocaleString('en-UG')
const filtered=computed(()=>jobs.value.filter(j=>(statusFilter.value==='all'||j.status===statusFilter.value)&&[j.job_no,j.customer_name,j.customer_phone,j.brand,j.model,j.imei??''].join(' ').toLowerCase().includes(query.value.toLowerCase())))
const selectedParts=ref<any[]>([]), selectedPayments=ref<any[]>([]), selectedEvents=ref<any[]>([])
const availableParts=computed(()=>products.value.filter(p=>p.track_inventory&&p.stock>0))
const canIssueParts=computed(()=>auth.profile?.role==='admin'||auth.profile?.role==='manager')
async function load(){
  loading.value=true;error.value=''
  try{
    const [j,p,s,c]=await Promise.all([
      supabase.from('repair_jobs').select('*').order('created_at',{ascending:false}).limit(300),
      supabase.from('products').select('id,name,sku,stock,track_inventory').order('name'),
      supabase.from('profiles').select('id,username,role').eq('disabled',false).order('username'),
      supabase.from('customers').select('id,name,phone,email').order('name').limit(500)
    ])
    for(const r of [j,p,s,c])if(r.error)throw r.error
    jobs.value=(j.data??[]) as RepairJob[];products.value=(p.data??[]) as Product[]
    staff.value=(s.data??[]) as Staff[];customers.value=(c.data??[]) as typeof customers.value
  }catch(e){error.value=e instanceof Error?e.message:'Could not load repair workspace'}
  finally{loading.value=false}
}
async function openJob(job:RepairJob){
  selected.value=job;edit.value={diagnosis:job.diagnosis??'',status:job.status,assigned_to:job.assigned_to??'',quote_labour:Number(job.quote_labour),quote_parts:Number(job.quote_parts),repair_warranty_days:job.repair_warranty_days,internal_notes:''}
  const [p,pay,ev]=await Promise.all([
    supabase.from('repair_job_parts').select('id,quantity,unit_cost,line_cost,products(name,sku)').eq('job_id',job.id).order('created_at'),
    supabase.from('repair_payments').select('*').eq('job_id',job.id).order('created_at',{ascending:false}),
    supabase.from('repair_job_events').select('*').eq('job_id',job.id).order('created_at',{ascending:false})
  ])
  selectedParts.value=p.data??[];selectedPayments.value=pay.data??[];selectedEvents.value=ev.data??[]
}
async function createJob(){
  error.value='';success.value=''
  if(!intake.value.customer_name.trim()||!intake.value.customer_phone.trim()||!intake.value.brand.trim()||!intake.value.model.trim()||!intake.value.reported_fault.trim()){error.value='Complete the customer, phone and reported-fault details.';return}
  saving.value=true
  try{
    const v=intake.value
    const {data,error:e}=await supabase.from('repair_jobs').insert({
      customer_type:v.customer_type,customer_id:v.customer_id?Number(v.customer_id):null,
      customer_name:v.customer_name.trim(),customer_phone:v.customer_phone.trim(),customer_email:v.customer_email||null,
      brand:v.brand.trim(),model:v.model.trim(),imei:v.imei.trim()||null,serial_number:v.serial_number.trim()||null,
      reported_fault:v.reported_fault.trim(),intake_condition:v.intake_condition||null,accessories_received:v.accessories_received||null,
      warranty_status:v.customer_type==='hybrid'?v.warranty_status:'not_applicable',
      warranty_reference:v.warranty_reference||null,warranty_notes:v.warranty_notes||null,
      received_by:auth.profile?.id
    }).select('*').single()
    if(e)throw e
    showIntake.value=false;intake.value={customer_type:'external',customer_id:'',customer_name:'',customer_phone:'',customer_email:'',brand:'',model:'',imei:'',serial_number:'',reported_fault:'',intake_condition:'',accessories_received:'',warranty_status:'not_applicable',warranty_reference:'',warranty_notes:''}
    await load();await openJob(data as RepairJob);success.value='Repair job registered successfully.'
  }catch(e){error.value=e instanceof Error?e.message:'Could not register repair job'}
  finally{saving.value=false}
}
async function saveJob(){
  if(!selected.value)return
  saving.value=true;error.value='';success.value=''
  try{
    const v=edit.value, total=Number(v.quote_labour||0)+Number(v.quote_parts||0)
    const {error:e}=await supabase.from('repair_jobs').update({
      diagnosis:v.diagnosis||null,status:v.status,assigned_to:v.assigned_to||null,
      quote_labour:v.quote_labour,quote_parts:v.quote_parts,quoted_total:total,
      balance:Math.max(total-Number(selected.value.amount_paid),0),
      repair_warranty_days:v.repair_warranty_days,internal_notes:v.internal_notes||null
    }).eq('id',selected.value.id)
    if(e)throw e
    success.value='Repair job updated.';await load();const fresh=jobs.value.find(x=>x.id===selected.value?.id);if(fresh)await openJob(fresh)
  }catch(e){error.value=e instanceof Error?e.message:'Could not update repair job'}
  finally{saving.value=false}
}
async function recordPayment(){
  if(!selected.value)return
  saving.value=true;error.value='';success.value=''
  try{
    const {error:e}=await supabase.rpc('record_repair_payment',{p_job_id:selected.value.id,p_amount:Number(payment.value.amount),p_method:payment.value.method,p_reference:payment.value.reference,p_note:payment.value.note})
    if(e)throw e
    showPayment.value=false;payment.value={amount:0,method:'cash',reference:'',note:''}
    success.value='Payment recorded.';await load();const fresh=jobs.value.find(x=>x.id===selected.value?.id);if(fresh)await openJob(fresh)
  }catch(e){error.value=e instanceof Error?e.message:'Could not record payment'}
  finally{saving.value=false}
}
async function issuePart(){
  if(!selected.value)return
  saving.value=true;error.value='';success.value=''
  try{
    const {error:e}=await supabase.rpc('add_repair_part',{p_job_id:selected.value.id,p_product_id:Number(part.value.product_id),p_quantity:Number(part.value.quantity)})
    if(e)throw e
    showPart.value=false;part.value={product_id:'',quantity:1}
    success.value='Spare part issued and inventory updated.';await load();const fresh=jobs.value.find(x=>x.id===selected.value?.id);if(fresh)await openJob(fresh)
  }catch(e){error.value=e instanceof Error?e.message:'Could not issue spare part'}
  finally{saving.value=false}
}
function printJob(){
  if(!selected.value)return
  window.print()
}
function selectCustomer(){
  const c=customers.value.find(x=>x.id===Number(intake.value.customer_id))
  if(c){intake.value.customer_name=c.name;intake.value.customer_phone=c.phone??'';intake.value.customer_email=c.email??''}
}
onMounted(load)
</script>

<template>
<section class="page-wrap repairs-page">
  <div class="page-heading">
    <div><h1>Repairs &amp; Service</h1><p>Mobile phones of every brand and model · Warranty and external repairs</p></div>
    <button class="btn primary" @click="showIntake=true">＋ Receive a phone</button>
  </div>
  <p v-if="error" class="alert">{{error}}</p><p v-if="success" class="success">{{success}}</p>
  <div class="stats-grid repair-stats">
    <article class="stat"><span>Open repair jobs</span><strong>{{jobs.filter(j=>!['collected','cancelled','unrepairable'].includes(j.status)).length}}</strong></article>
    <article class="stat"><span>Being repaired</span><strong>{{jobs.filter(j=>['repairing','testing'].includes(j.status)).length}}</strong></article>
    <article class="stat"><span>Ready for collection</span><strong>{{jobs.filter(j=>j.status==='ready').length}}</strong></article>
  </div>
  <div class="toolbar repair-toolbar mt">
    <input v-model="query" class="control search" placeholder="Search job, customer, phone, IMEI…">
    <select v-model="statusFilter" class="control status-select"><option value="all">All job statuses</option><option v-for="s in statuses" :key="s" :value="s">{{statusLabel(s)}}</option></select>
    <button class="btn" @click="load">Refresh</button>
  </div>
  <div v-if="loading" class="empty">Loading repair jobs…</div>
  <div v-else class="repair-layout">
    <div class="table-scroll repair-table">
      <table><thead><tr><th>Job</th><th>Customer</th><th>Phone</th><th>Received</th><th>Status</th><th>Balance</th></tr></thead>
        <tbody><tr v-for="j in filtered" :key="j.id" :class="{'repair-selected':selected?.id===j.id}" @click="openJob(j)">
          <td><button class="link">{{j.job_no}}</button><small class="cell-sub">{{j.customer_type==='hybrid'?'Hybrid purchase':'External customer'}}</small></td>
          <td>{{j.customer_name}}<small class="cell-sub">{{j.customer_phone}}</small></td>
          <td>{{j.brand}} {{j.model}}<small class="cell-sub">IMEI: {{j.imei||'Not recorded'}}</small></td>
          <td>{{new Date(j.created_at).toLocaleDateString()}}</td>
          <td><span class="repair-status" :class="'status-'+j.status">{{statusLabel(j.status)}}</span></td>
          <td>{{money(j.balance)}}</td>
        </tr><tr v-if="!filtered.length"><td colspan="6" class="empty">No repair jobs found. Receive the first phone to get started.</td></tr></tbody>
      </table>
    </div>
    <aside v-if="selected" class="panel repair-detail">
      <div class="detail-top"><div><span class="eyebrow">REPAIR JOB</span><h2>{{selected.job_no}}</h2></div><button class="link" @click="selected=null">Close</button></div>
      <div class="device-heading"><strong>{{selected.brand}} {{selected.model}}</strong><span>{{selected.customer_name}} · {{selected.customer_phone}}</span></div>
      <div class="repair-facts"><div><small>IMEI / Serial</small><b>{{selected.imei||selected.serial_number||'Not provided'}}</b></div><div><small>Customer type</small><b>{{selected.customer_type==='hybrid'?'Hybrid purchase':'External'}}</b></div><div><small>Warranty check</small><b>{{statusLabel(selected.warranty_status)}}</b></div><div><small>Reported fault</small><b>{{selected.reported_fault}}</b></div></div>
      <label>Job status<select v-model="edit.status" class="control"><option v-for="s in statuses" :key="s" :value="s">{{statusLabel(s)}}</option></select></label>
      <label>Assigned technician<select v-model="edit.assigned_to" class="control"><option value="">Unassigned</option><option v-for="s in staff" :key="s.id" :value="s.id">{{s.username}} · {{s.role}}</option></select></label>
      <label>Diagnosis<textarea v-model="edit.diagnosis" class="control" placeholder="Technician diagnosis and recommended repair…"/></label>
      <div class="quote-grid"><label>Labour quotation (UGX)<input v-model.number="edit.quote_labour" type="number" min="0" class="control"></label><label>Parts quotation (UGX)<input v-model.number="edit.quote_parts" type="number" min="0" class="control"></label></div>
      <div class="quote-total"><span>Quoted total</span><strong>{{money(Number(edit.quote_labour)+Number(edit.quote_parts))}}</strong></div>
      <label>Internal notes<textarea v-model="edit.internal_notes" class="control" placeholder="Internal-only notes…"/></label>
      <label>Repair warranty after collection (days)<input v-model.number="edit.repair_warranty_days" type="number" min="0" max="365" class="control"></label>
      <button class="btn primary full" :disabled="saving" @click="saveJob">Save repair updates</button>
      <div class="repair-actions">
        <button class="btn" @click="showPayment=true">Record payment</button>
        <button v-if="canIssueParts" class="btn" @click="showPart=true">Issue spare part</button>
        <button class="btn" @click="printJob">Print job sheet</button>
      </div>
      <div class="payment-summary"><div><span>Paid</span><b>{{money(selected.amount_paid)}}</b></div><div><span>Balance</span><b>{{money(selected.balance)}}</b></div><div><span>Parts cost</span><b>{{money(selected.actual_parts_cost)}}</b></div></div>
      <h3>Spare parts issued</h3><div v-if="!selectedParts.length" class="muted">No spare parts issued yet.</div>
      <div v-for="p in selectedParts" :key="p.id" class="history-line"><span>{{p.products?.name||'Inventory item'}} × {{p.quantity}}</span><b>{{money(p.line_cost)}}</b></div>
      <h3>Payment history</h3><div v-if="!selectedPayments.length" class="muted">No payments recorded.</div>
      <div v-for="p in selectedPayments" :key="p.id" class="history-line"><span>{{new Date(p.created_at).toLocaleString()}} · {{p.method}}</span><b>{{money(p.amount)}}</b></div>
      <h3>Activity</h3><div v-if="!selectedEvents.length" class="muted">No activity recorded yet.</div>
      <div v-for="e in selectedEvents" :key="e.id" class="history-line"><span>{{e.details}}</span><small>{{new Date(e.created_at).toLocaleString()}}</small></div>
    </aside>
  </div>

  <div v-if="showIntake" class="modal-backdrop" @click.self="showIntake=false"><form class="modal repair-modal" @submit.prevent="createJob">
    <div><h2>Receive a phone for repair</h2><p class="muted">Register any mobile phone brand or model. Record only information needed to service the device.</p></div>
    <div class="quote-grid"><label>Customer category<select v-model="intake.customer_type" class="control"><option value="external">External customer</option><option value="hybrid">Purchased from Hybrid</option></select></label><label v-if="intake.customer_type==='hybrid'">Existing Hybrid customer<select v-model="intake.customer_id" class="control" @change="selectCustomer"><option value="">Select customer (optional)</option><option v-for="c in customers" :key="c.id" :value="String(c.id)">{{c.name}} · {{c.phone}}</option></select></label></div>
    <div class="quote-grid"><label>Customer name<input v-model="intake.customer_name" required class="control"></label><label>Telephone<input v-model="intake.customer_phone" required class="control" type="tel"></label></div>
    <label>Email (optional)<input v-model="intake.customer_email" type="email" class="control"></label>
    <div class="quote-grid"><label>Phone brand<input v-model="intake.brand" required class="control" placeholder="e.g. Tecno, Samsung, Apple"></label><label>Model<input v-model="intake.model" required class="control" placeholder="e.g. Spark 20, Galaxy A15"></label></div>
    <div class="quote-grid"><label>IMEI<input v-model="intake.imei" class="control" inputmode="numeric" placeholder="Device IMEI"></label><label>Serial number<input v-model="intake.serial_number" class="control"></label></div>
    <label>Customer-reported fault<textarea v-model="intake.reported_fault" required class="control" placeholder="Describe the problem as reported by the customer…"/></label>
    <div class="quote-grid"><label>Physical condition<textarea v-model="intake.intake_condition" class="control" placeholder="Cracks, scratches, existing damage…"/></label><label>Accessories received<textarea v-model="intake.accessories_received" class="control" placeholder="Phone only, box, charger, SIM tray…"/></label></div>
    <template v-if="intake.customer_type==='hybrid'"><label>Warranty verification<select v-model="intake.warranty_status" class="control"><option value="pending">Pending verification</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select></label><div class="quote-grid"><label>Original sale receipt/reference<input v-model="intake.warranty_reference" class="control"></label><label>Warranty notes<input v-model="intake.warranty_notes" class="control"></label></div></template>
    <div class="modal-actions"><button type="button" class="btn" @click="showIntake=false">Cancel</button><button class="btn primary" :disabled="saving">Register repair</button></div>
  </form></div>
  <div v-if="showPayment&&selected" class="modal-backdrop" @click.self="showPayment=false"><form class="modal" @submit.prevent="recordPayment"><h2>Record repair payment</h2><p class="muted">{{selected.job_no}} · Outstanding {{money(selected.balance)}}</p><label>Amount (UGX)<input v-model.number="payment.amount" class="control" type="number" min="1" :max="selected.balance" required></label><label>Payment method<select v-model="payment.method" class="control"><option value="cash">Cash</option><option value="mobile_money">Mobile money</option><option value="bank">Bank transfer</option><option value="card">Card</option></select></label><label>Payment reference<input v-model="payment.reference" class="control"></label><label>Note<input v-model="payment.note" class="control"></label><div class="modal-actions"><button type="button" class="btn" @click="showPayment=false">Cancel</button><button class="btn primary" :disabled="saving">Save payment</button></div></form></div>
  <div v-if="showPart&&selected" class="modal-backdrop" @click.self="showPart=false"><form class="modal" @submit.prevent="issuePart"><h2>Issue spare part</h2><p class="muted">Stock will be deducted immediately and the cost recorded against this repair.</p><label>Inventory spare part<select v-model="part.product_id" class="control" required><option value="">Select part</option><option v-for="p in availableParts" :key="p.id" :value="String(p.id)">{{p.name}} · {{p.sku}} · {{p.stock}} available</option></select></label><label>Quantity<input v-model.number="part.quantity" class="control" type="number" min="1" required></label><div class="modal-actions"><button type="button" class="btn" @click="showPart=false">Cancel</button><button class="btn primary" :disabled="saving||!canIssueParts">Issue from stock</button></div></form></div>
</section>
</template>

<style scoped>
.repairs-page{max-width:1800px}.repair-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.repair-toolbar .status-select{max-width:230px}.repair-layout{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(340px,1fr);gap:18px;align-items:start}.repair-table{min-width:0}.repair-table tbody tr{cursor:pointer}.repair-table tbody tr.repair-selected{background:#eaf0ff}.cell-sub{display:block;color:#8490a4;font-size:11px;margin-top:4px}.repair-status{display:inline-flex;padding:6px 9px;border-radius:999px;background:#e9eef7;color:#52617a;font-size:11px;font-weight:750}.status-ready,.status-collected{background:#e4f6eb;color:#176a3b}.status-repairing,.status-testing,.status-approved{background:#e8efff;color:#3156bd}.status-awaiting_approval,.status-diagnosing{background:#fff4df;color:#98620c}.status-cancelled,.status-unrepairable{background:#ffebed;color:#a52d40}.repair-detail{display:grid;gap:13px;position:sticky;top:14px;max-height:calc(100vh - 28px);overflow:auto}.detail-top{display:flex;justify-content:space-between;align-items:center}.detail-top h2{margin:3px 0 0}.eyebrow{font-size:10px;font-weight:800;letter-spacing:1px;color:#7b8aa4}.device-heading{display:grid;gap:5px;padding:14px;border-radius:13px;background:#eaf0fa}.device-heading strong{font-size:18px}.device-heading span,.repair-facts small{font-size:12px;color:#78869c}.repair-facts{display:grid;grid-template-columns:1fr 1fr;gap:12px}.repair-facts div{display:grid;gap:4px;min-width:0}.repair-facts b{font-size:12px;overflow-wrap:anywhere}.repair-detail label{display:grid;gap:7px;font-size:12px;font-weight:750;color:#53627b}.quote-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.quote-total,.payment-summary{display:flex;justify-content:space-between;gap:10px;padding:13px;background:#eaf0fa;border-radius:12px}.quote-total strong{font-size:17px}.repair-actions{display:flex;flex-wrap:wrap;gap:8px}.payment-summary{flex-wrap:wrap}.payment-summary div{display:grid;gap:4px}.payment-summary span{font-size:11px;color:#77859c}.payment-summary b{font-size:12px}.repair-detail h3{font-size:13px;margin:5px 0 0}.history-line{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid #e4eaf3;font-size:12px}.history-line small{color:#8793a6}.repair-modal{width:min(760px,100%)}@media(max-width:1100px){.repair-layout{grid-template-columns:1fr}.repair-detail{position:static;max-height:none}}@media(max-width:680px){.repair-stats{grid-template-columns:1fr 1fr}.repair-toolbar .status-select{max-width:none;width:100%}.repair-layout{gap:14px}.repair-detail{padding:15px}.quote-grid{grid-template-columns:1fr}.repair-facts{grid-template-columns:1fr 1fr}.repair-actions .btn{flex:1}.repair-table table{min-width:720px}}
@media print{.repairs-page>*:not(.repair-layout),.repair-layout>.repair-table,.repair-detail>*:not(.device-heading):not(.repair-facts):not(.quote-total){display:none!important}.repair-layout{display:block}.repair-detail{position:static;max-height:none;overflow:visible}.repair-detail .control{border:0;background:white;box-shadow:none;padding:0}.repair-detail{border:0;box-shadow:none}.repair-detail:before{content:"HYBRID PHONE REPAIR JOB SHEET";font-weight:800;font-size:20px}}
</style>
