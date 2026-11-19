<?php
// pages/sales_history.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';

$from = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : date('Y-m-01');
$to   = isset($_GET['to'])   && $_GET['to']   !== '' ? $_GET['to']   : date('Y-m-d');

$stmt = $pdo->prepare('
  SELECT s.id, s.created_at, s.payment_method, s.total, s.tax_amount, s.grand_total, c.name as customer
  FROM sales s
  LEFT JOIN customers c ON c.id = s.customer_id
  WHERE DATE(s.created_at) BETWEEN ? AND ?
  ORDER BY s.id DESC
  LIMIT 500
');
$stmt->execute([$from, $to]);
$rows = $stmt->fetchAll();
?>
<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h5 class="card-title">Sales History</h5>
    <form class="row g-2">
      <div class="col-md-3">
        <label class="form-label">From</label>
        <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">To</label>
        <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100">Filter</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead>
          <tr>
            <th>ID</th><th>Date</th><th>Customer</th><th>Payment</th>
            <th class="text-end">Subtotal</th>
            <th class="text-end">Tax</th>
            <th class="text-end">Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>#<?= (int)$r['id'] ?></td>
              <td><?= e($r['created_at']) ?></td>
              <td><?= e($r['customer'] ?? 'Walk-in') ?></td>
              <td><?= e(ucfirst($r['payment_method'])) ?></td>
              <td class="text-end">UGX <?= money($r['total']) ?></td>
              <td class="text-end">UGX <?= money($r['tax_amount']) ?></td>
              <td class="text-end"><strong>UGX <?= money($r['grand_total']) ?></strong></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/?page=receipt&sale_id=<?= (int)$r['id'] ?>">Receipt</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-center text-muted">No sales found for selected dates.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
