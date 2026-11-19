<?php
// pages/credits.php — Manage credit sales: receive payments, close, view history
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

require_login(); require_page_access('credits');

$errors=[]; $ok=null;

/* POST: record payment */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['pay_credit_id'])) {
  if (function_exists('csrf_check')) csrf_check();
  $cid = (int)$_POST['pay_credit_id'];
  $amt = max(0,(float)($_POST['pay_amount'] ?? 0));
  $method = trim($_POST['pay_method'] ?? 'cash');
  $notes  = trim($_POST['pay_notes'] ?? '');

  $pdo->beginTransaction();
  try {
    $c = $pdo->prepare("SELECT id, sale_id, balance, status FROM credits WHERE id=? FOR UPDATE");
    $c->execute([$cid]);
    $cr = $c->fetch(PDO::FETCH_ASSOC);
    if (!$cr) throw new Exception('Credit not found.');
    if ($cr['status']==='closed') throw new Exception('Credit already closed.');
    if ($amt<=0) throw new Exception('Amount must be greater than zero.');
    if ($amt > (float)$cr['balance']) $amt = (float)$cr['balance']; // clamp

    $pdo->prepare("INSERT INTO credit_payments (credit_id, sale_id, amount, method, notes, created_at) VALUES (?,?,?,?,?,NOW())")
        ->execute([$cid, $cr['sale_id'], $amt, $method, $notes]);

    $newbal = (float)$cr['balance'] - $amt;
    $status = $newbal <= 0.00001 ? 'closed' : 'open';
    $pdo->prepare("UPDATE credits SET balance=?, status=? WHERE id=?")
        ->execute([$newbal, $status, $cid]);

    $pdo->commit();
    audit('credit_payment','credit',$cid,['amount'=>$amt,'method'=>$method,'new_balance'=>$newbal]);
    $ok = 'Payment recorded.';
  } catch (Throwable $e) {
    $pdo->rollBack();
    $errors[] = 'Failed: '.$e->getMessage();
  }
}

/* Filters (simple) */
$only_open = ($_GET['status'] ?? 'open') === 'open';

/* List credits */
$sql = "
  SELECT cr.id, cr.sale_id, cr.amount, cr.balance, cr.status, cr.created_at,
         s.receipt_no, s.customer_id, c.name AS customer
  FROM credits cr
  LEFT JOIN sales s ON s.id = cr.sale_id
  LEFT JOIN customers c ON c.id = s.customer_id
";
$sql .= $only_open ? " WHERE cr.status='open' " : " WHERE 1=1 ";
$sql .= " ORDER BY cr.id DESC LIMIT 100";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* Payment history for a picked credit (modal-like inline) */
$hist = [];
if (isset($_GET['view'])) {
  $vid = (int)$_GET['view'];
  $hx = $pdo->prepare("SELECT * FROM credit_payments WHERE credit_id=? ORDER BY id DESC");
  $hx->execute([$vid]);
  $hist = $hx->fetchAll(PDO::FETCH_ASSOC);
}
?>
<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="card-title mb-0">Credits</h5>
      <div class="btn-group">
        <a class="btn btn-outline-secondary <?= $only_open?'active':'' ?>" href="<?= BASE_URL ?>/?page=credits&status=open">Open</a>
        <a class="btn btn-outline-secondary <?= !$only_open?'active':'' ?>" href="<?= BASE_URL ?>/?page=credits&status=all">All</a>
      </div>
    </div>

    <?php foreach ($errors as $m): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endforeach; ?>
    <?php if ($ok): ?><div class="alert alert-success"><?= e($ok) ?></div><?php endif; ?>

    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Receipt</th>
            <th>Customer</th>
            <th class="text-end">Amount</th>
            <th class="text-end">Balance</th>
            <th>Status</th>
            <th>Date</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><a href="<?= BASE_URL ?>/?page=receipt&sale_id=<?= (int)$r['sale_id'] ?>"><?= e($r['receipt_no'] ?: '#'.$r['sale_id']) ?></a></td>
            <td><?= e($r['customer'] ?: '—') ?></td>
            <td class="text-end">UGX <?= number_format((float)$r['amount'],2) ?></td>
            <td class="text-end fw-bold"><?= number_format((float)$r['balance'],2) ?></td>
            <td><?= e(ucfirst($r['status'])) ?></td>
            <td class="small text-muted"><?= e($r['created_at']) ?></td>
            <td class="text-end">
              <div class="btn-group">
                <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/?page=credits&view=<?= (int)$r['id'] ?>&status=<?= $only_open?'open':'all' ?>">History</a>
                <?php if ($r['status']==='open'): ?>
                  <button class="btn btn-sm btn-primary" data-pay="<?= (int)$r['id'] ?>" data-bal="<?= (float)$r['balance'] ?>">Take Payment</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; if (!$rows): ?>
            <tr><td colspan="8" class="text-center text-muted">No credits found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($hist): ?>
    <hr class="my-3">
    <h6 class="mb-2">Payment History (Credit #<?= (int)$_GET['view'] ?>)</h6>
    <div class="table-responsive">
      <table class="table table-sm">
        <thead class="table-light"><tr><th>#</th><th>Amount</th><th>Method</th><th>Notes</th><th>Date</th></tr></thead>
        <tbody>
          <?php foreach ($hist as $h): ?>
            <tr>
              <td><?= (int)$h['id'] ?></td>
              <td>UGX <?= number_format((float)$h['amount'],2) ?></td>
              <td><?= e($h['method']) ?></td>
              <td><?= e($h['notes']) ?></td>
              <td class="small text-muted"><?= e($h['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<form method="post" id="payForm" class="d-none">
  <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
  <input type="hidden" name="pay_credit_id" id="pay_credit_id">
  <input type="hidden" name="pay_amount" id="pay_amount">
  <input type="hidden" name="pay_method" id="pay_method" value="cash">
  <input type="hidden" name="pay_notes" id="pay_notes">
</form>

<script>
document.addEventListener('click', e=>{
  const btn=e.target.closest('button[data-pay]'); if(!btn) return;
  const id=btn.getAttribute('data-pay'); const bal=parseFloat(btn.getAttribute('data-bal')||'0');
  const amt=prompt('Enter amount to collect (max '+bal.toFixed(2)+'):', bal.toFixed(2));
  if(!amt) return;
  const v=parseFloat(amt); if(!(v>0)){ alert('Invalid amount'); return; }
  document.getElementById('pay_credit_id').value=id;
  document.getElementById('pay_amount').value=v.toFixed(2);
  document.getElementById('payForm').submit();
});
</script>
