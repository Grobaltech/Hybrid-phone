<?php
// pages/purchases.php — Order -> Receive workflow with bundles (packs -> retail units)
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

require_login(); require_page_access('purchases');

$errors=[]; $ok=null;

/* Load suppliers + quick product list for suggestions */
$suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* Create / Update purchase */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['payload'])) {
  if (function_exists('csrf_check')) csrf_check();
  $data = json_decode($_POST['payload'], true);

  if (!$data || empty($data['items'])) {
    $errors[] = 'No items submitted.'; 
  } else {
    $supplier_id = !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null;
    $ref  = trim($data['reference'] ?? '');
    $mode = ($data['mode'] ?? 'received'); // 'ordered' or 'received'
    $mode = in_array($mode, ['ordered','received'], true) ? $mode : 'received';

    $items = $data['items']; // each: {id, pack_size, packs, loose, pack_cost OR unit_cost}
    $total = 0.0;

    // Normalize: compute retail units & unit_cost
    $norm = [];
    foreach ($items as $it) {
      $pid = (int)($it['id'] ?? 0);
      $packSize = max(1, (int)($it['pack_size'] ?? 1));
      $packs = max(0, (int)($it['packs'] ?? 0));
      $loose = max(0, (int)($it['loose'] ?? 0));
      $retailUnits = $packs * $packSize + $loose;

      // If pack_cost provided, derive unit_cost
      $packCost = isset($it['pack_cost']) ? max(0,(float)$it['pack_cost']) : null;
      $unitCost = isset($it['unit_cost']) ? max(0,(float)$it['unit_cost']) : null;

      if ($packCost !== null && $packSize > 0) {
        $unitCost = $packCost / $packSize;
      }
      if ($unitCost === null) $unitCost = 0.0;

      $lineTotal = $unitCost * $retailUnits;
      $total += $lineTotal;

      $norm[] = [
        'id'         => $pid,
        'retail_qty' => $retailUnits,
        'unit_cost'  => $unitCost,
        'packs'      => $packs,
        'pack_size'  => $packSize,
        'line_total' => $lineTotal
      ];
    }

    $pdo->beginTransaction();
    try {
      // Header
      $ins = $pdo->prepare("INSERT INTO purchases (supplier_id, reference, total_cost, total, status, created_at, received_at) VALUES (?,?,?,?,?,NOW(),NULL)");
      $ins->execute([$supplier_id, $ref, $total, $total, $mode]);
      $pid = (int)$pdo->lastInsertId();

      // Lock products if receiving now
      $ids = array_values(array_unique(array_map(fn($x)=> (int)$x['id'], $norm)));
      $stockMap=[];
      if ($mode === 'received' && $ids) {
        $ph = implode(',', array_fill(0,count($ids),'?'));
        $sel = $pdo->prepare("SELECT id, stock FROM products WHERE id IN ($ph) FOR UPDATE");
        $sel->execute($ids);
        while ($r = $sel->fetch(PDO::FETCH_ASSOC)) $stockMap[(int)$r['id']] = (int)$r['stock'];
      }

      // Lines
      // with SQL patch: packs, pack_size are stored; without it, these extra values will be ignored by DB
      $hasPackCols = false;
      try {
        $pdo->query("SHOW COLUMNS FROM purchase_items LIKE 'packs'");
        $hasPackCols = true;
      } catch (Throwable $e) { $hasPackCols = false; }

      if ($hasPackCols) {
        $pi = $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity, packs, pack_size, unit_cost, line_cost) VALUES (?,?,?,?,?,?,?)");
      } else {
        $pi = $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_cost, line_cost) VALUES (?,?,?,?,?)");
      }

      foreach ($norm as $it) {
        if ($hasPackCols) {
          $pi->execute([$pid, $it['id'], $it['retail_qty'], $it['packs'], $it['pack_size'], $it['unit_cost'], $it['line_total']]);
        } else {
          $pi->execute([$pid, $it['id'], $it['retail_qty'], $it['unit_cost'], $it['line_total']]);
        }

        // Receive now -> stock++
        if ($mode === 'received') {
          $newStock = ($stockMap[$it['id']] ?? 0) + $it['retail_qty'];
          // also update last cost to unit cost
          $pdo->prepare("UPDATE products SET price_cost=?, stock=? WHERE id=?")->execute([$it['unit_cost'], $newStock, $it['id']]);
        }
      }

      if ($mode === 'received') {
        $pdo->prepare("UPDATE purchases SET received_at = NOW() WHERE id=?")->execute([$pid]);
      }

      $pdo->commit();
      audit('purchase.'.$mode, 'purchase', $pid, null, 'purchases');
      $ok = $mode === 'received' ? 'Purchase received and stock updated.' : 'Purchase order saved. Receive later to update stock.';
    } catch (Throwable $e) {
      $pdo->rollBack();
      $errors[] = 'Failed: '.$e->getMessage();
    }
  }
}

/* Receive existing order */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['receive_id'])) {
  if (function_exists('csrf_check')) csrf_check();
  $id = (int)$_POST['receive_id'];

  $pdo->beginTransaction();
  try {
    $h = $pdo->prepare("SELECT id, status FROM purchases WHERE id=? FOR UPDATE");
    $h->execute([$id]); $header = $h->fetch(PDO::FETCH_ASSOC);
    if (!$header) throw new Exception('Order not found');
    if ($header['status'] === 'received') throw new Exception('Already received');

    $it = $pdo->prepare("SELECT product_id, quantity, unit_cost FROM purchase_items WHERE purchase_id=?");
    $it->execute([$id]); $items = $it->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) throw new Exception('No items on this order');
    $ids = array_map(fn($r)=> (int)$r['product_id'], $items);
    $ph  = implode(',', array_fill(0,count($ids),'?'));
    $sel = $pdo->prepare("SELECT id, stock FROM products WHERE id IN ($ph) FOR UPDATE");
    $sel->execute($ids); $stockMap=[]; while ($r=$sel->fetch(PDO::FETCH_ASSOC)) $stockMap[(int)$r['id']] = (int)$r['stock'];

    foreach ($items as $r) {
      $pid = (int)$r['product_id']; $qty=(int)$r['quantity']; $unitCost=(float)$r['unit_cost'];
      $new = ($stockMap[$pid] ?? 0) + $qty;
      $pdo->prepare("UPDATE products SET price_cost=?, stock=? WHERE id=?")->execute([$unitCost, $new, $pid]);
    }

    $pdo->prepare("UPDATE purchases SET status='received', received_at=NOW() WHERE id=?")->execute([$id]);
    $pdo->commit();
    audit('purchase.receive','purchase',$id,null,'purchases');
    $ok = 'Order received. Stock updated.';
  } catch (Throwable $e) {
    $pdo->rollBack();
    $errors[] = 'Failed: '.$e->getMessage();
  }
}

/* Lists */
$recent_received = $pdo->query("
  SELECT p.id, s.name supplier, p.reference, p.total, p.received_at
  FROM purchases p
  LEFT JOIN suppliers s ON s.id = p.supplier_id
  WHERE p.status='received'
  ORDER BY p.id DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$open_orders = $pdo->query("
  SELECT p.id, s.name supplier, p.reference, p.total, p.created_at
  FROM purchases p
  LEFT JOIN suppliers s ON s.id = p.supplier_id
  WHERE p.status='ordered'
  ORDER BY p.id DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="card-title mb-0">Purchases</h5>
      <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/?page=suppliers">Manage Suppliers</a>
    </div>

    <?php foreach ($errors as $m): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endforeach; ?>
    <?php if ($ok): ?><div class="alert alert-success"><?= e($ok) ?></div><?php endif; ?>

    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Supplier</label>
        <select id="supplier" class="form-select">
          <option value="">— None —</option>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Reference (optional)</label>
        <input id="reference" class="form-control" placeholder="Invoice/PO number">
      </div>
      <div class="col-md-3">
        <label class="form-label">Mode</label>
        <select id="mode" class="form-select">
          <option value="ordered">Create Order (receive later)</option>
          <option value="received">Receive Now (update stock)</option>
        </select>
      </div>
    </div>

    <div class="mt-3">
      <label class="form-label">Add Product</label>
      <input id="addProd" class="form-control" placeholder="Type name or SKU">
      <div id="suggest" class="search-suggest shadow"></div>
    </div>

    <div class="table-responsive mt-3">
      <table class="table table-sm align-middle" id="pTable">
        <thead class="table-light">
          <tr>
            <th>#</th><th>SKU</th><th>Product</th>
            <th class="text-end" style="width:110px;" title="Whole packs">Packs</th>
            <th class="text-end" style="width:120px;" title="Units per pack">Pack Size</th>
            <th class="text-end" style="width:120px;" title="Extra single units">Loose</th>
            <th class="text-end" style="width:150px;" title="Cost per pack (optional)">Pack Cost</th>
            <th class="text-end" style="width:150px;" title="Cost per unit (auto if pack cost)">Unit Cost</th>
            <th class="text-end" style="width:140px;">Line Total</th>
            <th class="text-end" style="width:60px;">×</th>
          </tr>
        </thead>
        <tbody id="pBody"><tr class="empty"><td colspan="10" class="text-center text-muted">No items.</td></tr></tbody>
      </table>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-md-8">
        <label class="form-label">Notes (optional)</label>
        <textarea id="notes" class="form-control" rows="2"></textarea>
      </div>
      <div class="col-md-4">
        <table class="table table-sm">
          <tr><th>Total</th><td class="text-end h5" id="totTxt"><?= e(money(0)) ?></td></tr>
        </table>
        <div class="d-flex justify-content-end gap-2">
          <button class="btn btn-outline-secondary" id="clearBtn">Clear</button>
          <button class="btn btn-primary" id="saveBtn">Save</button>
        </div>
      </div>
    </div>

    <form id="postForm" method="post" class="d-none">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="payload" id="payload">
    </form>

    <hr class="my-4">

    <div class="row g-4">
      <div class="col-lg-6">
        <h6 class="mb-2">Open Orders</h6>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle">
            <thead class="table-light"><tr><th>#</th><th>Supplier</th><th>Reference</th><th class="text-end">Total</th><th>Created</th><th class="text-end">Action</th></tr></thead>
            <tbody>
              <?php foreach ($open_orders as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= e($r['supplier'] ?: '—') ?></td>
                <td><?= e($r['reference'] ?: '—') ?></td>
                <td class="text-end"><?= e(money($r['total'])) ?></td>
                <td class="small text-muted"><?= e($r['created_at']) ?></td>
                <td class="text-end">
                  <form method="post" onsubmit="return confirm('Mark as received and update stock?');" class="d-inline">
                    <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
                    <input type="hidden" name="receive_id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-success">Receive</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; if (!$open_orders): ?>
                <tr><td colspan="6" class="text-center text-muted">No open orders.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="col-lg-6">
        <h6 class="mb-2">Recently Received</h6>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle">
            <thead class="table-light"><tr><th>#</th><th>Supplier</th><th>Reference</th><th class="text-end">Total</th><th>Received</th></tr></thead>
            <tbody>
              <?php foreach ($recent_received as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= e($r['supplier'] ?: '—') ?></td>
                <td><?= e($r['reference'] ?: '—') ?></td>
                <td class="text-end"><?= e(money($r['total'])) ?></td>
                <td class="small text-muted"><?= e($r['received_at']) ?></td>
              </tr>
              <?php endforeach; if (!$recent_received): ?>
                <tr><td colspan="5" class="text-center text-muted">None received yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const $=s=>document.querySelector(s);
  const add=$('#addProd'), suggest=$('#suggest'), body=$('#pBody'), totTxt=$('#totTxt');

  let items=[];
  const CURRENCY='UGX';
  const fmt = n => CURRENCY + ' ' + (Number(n)||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});

  function esc(s){return (s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
  function retailUnits(it){ return (Number(it.packs)||0)*(Number(it.pack_size)||1) + (Number(it.loose)||0); }
  function lineTotal(it){
    let unitCost = Number(it.unit_cost)||0;
    if ((it.pack_cost||'')!=='' && Number(it.pack_size)>0){
      unitCost = Number(it.pack_cost)/Number(it.pack_size);
    }
    return unitCost * retailUnits(it);
  }
  function render(){
    body.innerHTML='';
    if(!items.length){ body.innerHTML='<tr class="empty"><td colspan="10" class="text-center text-muted">No items.</td></tr>'; tot(); return; }
    items.forEach((it,i)=>{
      const unitCost = (it.pack_cost!=='' && Number(it.pack_size)>0) ? (Number(it.pack_cost)/Number(it.pack_size)) : Number(it.unit_cost)||0;
      const tr=document.createElement('tr'); tr.dataset.i=i;
      tr.innerHTML=`
        <td class="text-muted small">${i+1}</td>
        <td>${esc(it.sku||'')}</td>
        <td>${esc(it.name)}</td>
        <td class="text-end"><input class="form-control form-control-sm text-end packs" value="${it.packs}"></td>
        <td class="text-end"><input class="form-control form-control-sm text-end pack_size" value="${it.pack_size}"></td>
        <td class="text-end"><input class="form-control form-control-sm text-end loose" value="${it.loose}"></td>
        <td class="text-end"><input class="form-control form-control-sm text-end pack_cost" value="${it.pack_cost}"></td>
        <td class="text-end"><input class="form-control form-control-sm text-end unit_cost" value="${it.unit_cost}" ${ (it.pack_cost!=='' ? 'readonly' : '') }></td>
        <td class="text-end">${fmt(lineTotal(it))}</td>
        <td class="text-end"><button class="btn btn-sm btn-outline-danger del">✕</button></td>`;
      body.appendChild(tr);
    });
    tot();
  }
  function tot(){ const t=items.reduce((s,it)=>s+lineTotal(it),0); totTxt.textContent=fmt(t); }

  // Suggest search
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
        a.innerHTML=`<span class="badge me-2">P</span> ${(o.sku?esc(o.sku)+' — ':'')+esc(o.name)} <span class="float-end small">${fmt(o.cost||0)}</span>`;
        a.addEventListener('mousedown',e=>{e.preventDefault(); addItem(o); add.value=''; suggest.style.display='none';});
        suggest.appendChild(a);
      });
      suggest.style.display='block';
    },140);
  });

  function addItem(o){
    const i=items.findIndex(x=>x.id===o.id);
    if(i>=0){ items[i].packs += 1; render(); return; }
    items.push({id:o.id, sku:o.sku, name:o.name, packs:1, pack_size:1, loose:0, pack_cost:'', unit_cost:(o.cost||0).toFixed(2)});
    render();
  }
  body.addEventListener('click', e=>{
    const tr=e.target.closest('tr'); if(!tr) return; const i=Number(tr.dataset.i);
    if(e.target.classList.contains('del')){ items.splice(i,1); render(); }
  });
  body.addEventListener('input', e=>{
    const tr=e.target.closest('tr'); if(!tr) return; const i=Number(tr.dataset.i);
    const cls = e.target.classList;
    if(cls.contains('packs')) items[i].packs=Math.max(0,parseInt(e.target.value||0,10));
    if(cls.contains('pack_size')) items[i].pack_size=Math.max(1,parseInt(e.target.value||1,10));
    if(cls.contains('loose')) items[i].loose=Math.max(0,parseInt(e.target.value||0,10));
    if(cls.contains('pack_cost')) { items[i].pack_cost=e.target.value; }
    if(cls.contains('unit_cost')) { items[i].unit_cost=(Number(e.target.value)||0).toFixed(2); }
    // if pack_cost present, freeze unit_cost display
    const uInput = tr.querySelector('.unit_cost');
    if (String(items[i].pack_cost).trim()!=='') { uInput.setAttribute('readonly','readonly'); }
    else { uInput.removeAttribute('readonly'); }
    tot(); // updates total live
    // update line cell
    tr.children[8].innerHTML = fmt(lineTotal(items[i]));
  });

  $('#clearBtn').addEventListener('click', ()=>{items=[]; render();});
  $('#saveBtn').addEventListener('click', ()=>{
    if(!items.length){ alert('Add items.'); return; }
    const payload={
      supplier_id: Number($('#supplier').value)||null,
      reference: $('#reference').value.trim(),
      mode: $('#mode').value,
      notes: ($('#notes')?.value||'').trim(),
      items: items.map(it=>{
        return {
          id: it.id,
          packs: Number(it.packs)||0,
          pack_size: Number(it.pack_size)||1,
          loose: Number(it.loose)||0,
          pack_cost: (String(it.pack_cost).trim()===''? null : Number(it.pack_cost)||0),
          unit_cost: (String(it.pack_cost).trim()!==''? null : (Number(it.unit_cost)||0))
        };
      })
    };
    document.getElementById('payload').value=JSON.stringify(payload);
    document.getElementById('postForm').submit();
  });
})();
</script>
