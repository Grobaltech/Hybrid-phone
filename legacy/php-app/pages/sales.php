<?php
// pages/sales.php — simple sale builder with optional customer capture
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/customer.php';

require_login(); require_page_access('sales');

$errors=[]; $ok=null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['payload'])) {
  if (function_exists('csrf_check')) csrf_check();

  $data = json_decode($_POST['payload'], true);
  if (!$data || empty($data['items'])) {
    $errors[] = 'No items added.';
  } else {
    $customerName  = trim((string)($data['customer_name'] ?? ''));     // optional
    $paymentMethod = ($data['payment'] ?? 'cash') === 'credit' ? 'credit':'cash';
    $taxRate       = max(0,(float)($data['tax_rate'] ?? 0));

    $items = $data['items']; // each: {id, qty, price_sell, price_cost, actual_price?}

    // compute totals
    $subtotal=0; $totalCost=0; $lines=[];
    foreach ($items as $it) {
      $pid=(int)$it['id']; $qty=max(1,(int)$it['qty']);
      $sell=(float)($it['actual_price'] ?? $it['price_sell']);
      $cost=(float)$it['price_cost'];
      $lineTotal = $sell*$qty; $lineCost=$cost*$qty; $lineProfit=$lineTotal-$lineCost;
      $subtotal += $lineTotal; $totalCost += $lineCost;
      $lines[] = compact('pid','qty','sell','cost','lineTotal','lineCost','lineProfit');
    }
    $taxAmount = round($subtotal * ($taxRate/100), 2);
    $grand     = $subtotal + $taxAmount;
    $profit    = $subtotal - $totalCost;

    // customer (optional)
    $customerId = resolve_customer_id_by_name($customerName); // null if empty

    $pdo->beginTransaction();
    try {
      // Receipt number
      $rcpt = 'RCPT-'.date('Ymd').'-'.str_pad((string)( (int)($pdo->query("SELECT IFNULL(MAX(id),0)+1 FROM sales")->fetchColumn()) ), 6, '0', STR_PAD_LEFT);

      // Insert sale
      $ins = $pdo->prepare("INSERT INTO sales (receipt_no, customer_id, total, total_cost, profit, tax_rate, tax_amount, grand_total, payment_method, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
      $ins->execute([$rcpt, $customerId, $subtotal, $totalCost, $profit, $taxRate, $taxAmount, $grand, $paymentMethod]);
      $saleId = (int)$pdo->lastInsertId();

      // Insert lines + reduce stock
      $pi = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, price_sell, actual_price, price_cost, line_total, line_cost, line_profit) VALUES (?,?,?,?,?,?,?,?,?)");
      foreach ($lines as $ln) {
        $pi->execute([$saleId,$ln['pid'],$ln['qty'],$ln['sell'],$ln['sell'],$ln['cost'],$ln['lineTotal'],$ln['lineCost'],$ln['lineProfit']]);
        // stock down
        $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id=?")->execute([$ln['qty'],$ln['pid']]);
      }

      // credit ledger
      if ($paymentMethod === 'credit') {
        $pdo->prepare("INSERT INTO credits (customer_id, sale_id, amount, balance, status) VALUES (?,?,?,?, 'open')")
            ->execute([$customerId, $saleId, $grand, $grand]);
      }

      $pdo->commit();
      audit('sale.create','sale',$saleId,'receipt='.$rcpt,'sales');
      safe_redirect(BASE_URL.'/?page=receipt&id='.$saleId);
    } catch (Throwable $e) {
      $pdo->rollBack();
      $errors[] = 'Failed to save: '.$e->getMessage();
    }
  }
}
?>
<div class="card shadow-sm">
  <div class="card-body">
    <h5 class="card-title">New Sale</h5>
    <?php foreach ($errors as $m): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endforeach; ?>
    <?php if ($ok): ?><div class="alert alert-success"><?= e($ok) ?></div><?php endif; ?>

    <div class="row g-2">
      <div class="col-md-5">
        <label class="form-label">Search Product</label>
        <input id="addProd" class="form-control" placeholder="Type name or SKU">
        <div id="suggest" class="search-suggest shadow"></div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Customer (optional)</label>
        <input id="customer" class="form-control" placeholder="Walk-in if empty">
      </div>
      <div class="col-md-3">
        <label class="form-label">Payment</label>
        <select id="payment" class="form-select">
          <option value="cash">Cash</option>
          <option value="credit">Credit</option>
        </select>
      </div>
    </div>

    <div class="table-responsive mt-3">
      <table class="table table-sm align-middle" id="sTable">
        <thead class="table-light">
          <tr>
            <th>#</th><th>SKU</th><th>Product</th>
            <th class="text-end" style="width:120px;">Qty</th>
            <th class="text-end" style="width:150px;">Actual Price</th>
            <th class="text-end" style="width:140px;">Line Total</th>
            <th class="text-end" style="width:60px;">×</th>
          </tr>
        </thead>
        <tbody id="sBody"><tr class="empty"><td colspan="7" class="text-center text-muted">No items.</td></tr></tbody>
      </table>
    </div>

    <div class="row g-2">
      <div class="col-md-3">
        <label class="form-label">Tax %</label>
        <input id="tax" type="number" min="0" step="0.01" class="form-control" value="0">
      </div>
      <div class="col-md-9">
        <table class="table table-sm mb-0">
          <tr><th>Subtotal</th><td class="text-end" id="tSub">UGX 0.00</td></tr>
          <tr><th>Tax</th><td class="text-end" id="tTax">UGX 0.00</td></tr>
          <tr><th>Grand Total</th><td class="text-end h5" id="tGrand">UGX 0.00</td></tr>
        </table>
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-2">
      <button class="btn btn-outline-secondary" id="clearBtn">Clear</button>
      <button class="btn btn-primary" id="saveBtn">Complete Sale</button>
    </div>

    <form id="postForm" method="post" class="d-none">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="payload" id="payload">
    </form>
  </div>
</div>

<script>
(function(){
  const $=s=>document.querySelector(s);
  const add=$('#addProd'), suggest=$('#suggest'), body=$('#sBody');
  const fmt = n => 'UGX ' + (Number(n)||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
  let items=[];
  function esc(s){return (s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
  function lineTotal(it){ return (Number(it.actual)||Number(it.sell)||0) * (Number(it.qty)||0); }
  function totals(){
    const sub=items.reduce((s,it)=>s+lineTotal(it),0);
    const taxRate = Number($('#tax').value)||0;
    const tax = sub * (taxRate/100);
    $('#tSub').textContent = fmt(sub);
    $('#tTax').textContent = fmt(tax);
    $('#tGrand').textContent = fmt(sub+tax);
  }
  function render(){
    body.innerHTML='';
    if(!items.length){ body.innerHTML='<tr class="empty"><td colspan="7" class="text-center text-muted">No items.</td></tr>'; totals(); return; }
    items.forEach((it,i)=>{
      const tr=document.createElement('tr'); tr.dataset.i=i;
      tr.innerHTML=`
        <td class="text-muted small">${i+1}</td>
        <td>${esc(it.sku||'')}</td>
        <td>${esc(it.name)}</td>
        <td class="text-end">
          <div class="input-group input-group-sm">
            <button class="btn btn-outline-secondary minus" type="button">-</button>
            <input class="form-control text-end qty" value="${it.qty}">
            <button class="btn btn-outline-secondary plus" type="button">+</button>
          </div>
        </td>
        <td class="text-end"><input class="form-control form-control-sm text-end actual" value="${(it.actual||it.sell).toFixed(2)}"></td>
        <td class="text-end">${fmt(lineTotal(it))}</td>
        <td class="text-end"><button class="btn btn-sm btn-outline-danger del">✕</button></td>`;
      body.appendChild(tr);
    });
    totals();
  }
  let t; add.addEventListener('input', ()=>{
    clearTimeout(t);
    const q=add.value.trim(); if(!q){suggest.style.display='none';return;}
    t=setTimeout(async ()=>{
      const res=await fetch('<?= BASE_URL ?>/search.php?q='+encodeURIComponent(q));
      const data=await res.json(); const arr=(data.products||[]).slice(0,8);
      if(!arr.length){suggest.style.display='none';return;}
      suggest.innerHTML='<div class="search-head">Products</div>';
      arr.forEach(o=>{
        const a=document.createElement('a'); a.href='#'; a.className='search-item';
        a.innerHTML=`<span class="badge me-2">P</span> ${(o.sku?esc(o.sku)+' — ':'')+esc(o.name)} <span class="float-end small">UGX ${(o.sell||o.price_sell||0).toLocaleString()}</span>`;
        a.addEventListener('mousedown',e=>{
          e.preventDefault(); addItem(o); add.value=''; suggest.style.display='none';
        });
        suggest.appendChild(a);
      });
      suggest.style.display='block';
    },140);
  });
  function addItem(o){
    const i=items.findIndex(x=>x.id===o.id);
    if(i>=0){ items[i].qty+=1; render(); return; }
    items.push({id:o.id, sku:o.sku, name:o.name, qty:1, sell:Number(o.sell||o.price_sell||0), cost:Number(o.cost||o.price_cost||0), actual:Number(o.sell||o.price_sell||0)});
    render();
  }
  body.addEventListener('click', e=>{
    const tr=e.target.closest('tr'); if(!tr) return; const i=Number(tr.dataset.i);
    if(e.target.classList.contains('plus')){ items[i].qty+=1; render(); }
    if(e.target.classList.contains('minus')){ items[i].qty=Math.max(1,items[i].qty-1); render(); }
    if(e.target.classList.contains('del')){ items.splice(i,1); render(); }
  });
  body.addEventListener('input', e=>{
    const tr=e.target.closest('tr'); if(!tr) return; const i=Number(tr.dataset.i);
    if(e.target.classList.contains('qty')) items[i].qty=Math.max(1,parseInt(e.target.value||1,10));
    if(e.target.classList.contains('actual')) items[i].actual=Math.max(0,Number(e.target.value)||0);
    tr.children[5].innerHTML = fmt(lineTotal(items[i]));
    totals();
  });

  $('#clearBtn').addEventListener('click', ()=>{items=[]; render();});
  $('#saveBtn').addEventListener('click', ()=>{
    if(!items.length){ alert('Add items.'); return; }
    const payload={
      customer_name: $('#customer').value.trim(), // optional
      payment: $('#payment').value,
      tax_rate: Number($('#tax').value)||0,
      items: items.map(it=>({id:it.id, qty:it.qty, price_sell:it.sell, price_cost:it.cost, actual_price:it.actual}))
    };
    document.getElementById('payload').value=JSON.stringify(payload);
    document.getElementById('postForm').submit();
  });
})();
</script>
