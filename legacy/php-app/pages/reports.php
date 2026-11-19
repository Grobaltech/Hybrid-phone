<?php
// pages/reports.php — date filter stays on this page, shows summaries
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
require_login(); require_page_access('reports');

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$type = $_GET['type'] ?? 'sales'; // sales|expenses|profits

// Pull data
$sumSales = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM sales WHERE DATE(created_at) BETWEEN ? AND ?");
$sumSales->execute([$from,$to]); $salesTotal = (float)$sumSales->fetchColumn();

$sumExp = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE date BETWEEN ? AND ?");
$sumExp->execute([$from,$to]); $expTotal = (float)$sumExp->fetchColumn();

$sumProfit = $pdo->prepare("SELECT COALESCE(SUM(profit),0) FROM sales WHERE DATE(created_at) BETWEEN ? AND ?");
$sumProfit->execute([$from,$to]); $profitTotal = (float)$sumProfit->fetchColumn();

// Table rows per type
$rows = [];
if ($type === 'sales') {
  $q = $pdo->prepare("SELECT id, receipt_no, total, profit, created_at FROM sales WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC");
  $q->execute([$from,$to]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
} elseif ($type === 'expenses') {
  $q = $pdo->prepare("SELECT id, date, category, amount, note FROM expenses WHERE date BETWEEN ? AND ? ORDER BY id DESC");
  $q->execute([$from,$to]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
} else { // profits
  $q = $pdo->prepare("SELECT id, receipt_no, total, profit, created_at FROM sales WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC");
  $q->execute([$from,$to]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
}
?>
<div class="card shadow-sm">
  <div class="card-body">
    <h5 class="card-title">Reports</h5>

    <form class="row g-2 align-items-end" method="get" action="">
      <input type="hidden" name="page" value="reports">
      <div class="col-md-3">
        <label class="form-label">From</label>
        <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">To</label>
        <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Report Type</label>
        <select class="form-select" name="type">
          <option value="sales"    <?= $type==='sales'?'selected':'' ?>>Sales</option>
          <option value="expenses" <?= $type==='expenses'?'selected':'' ?>>Expenses</option>
          <option value="profits"  <?= $type==='profits'?'selected':'' ?>>Profits</option>
        </select>
      </div>
      <div class="col-md-3">
        <button class="btn btn-primary w-100">Apply</button>
      </div>
    </form>

    <hr>

    <div class="row g-3">
      <div class="col-md-4">
        <div class="border rounded p-3">
          <div class="text-muted small">Sales Total</div>
          <div class="h5 mb-0"><?= e(money($salesTotal)) ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-3">
          <div class="text-muted small">Expenses Total</div>
          <div class="h5 mb-0"><?= e(money($expTotal)) ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-3">
          <div class="text-muted small">Profit Total</div>
          <div class="h5 mb-0"><?= e(money($profitTotal)) ?></div>
        </div>
      </div>
    </div>

    <div class="table-responsive mt-3">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <?php if ($type === 'expenses'): ?>
          <tr><th>#</th><th>Date</th><th>Category</th><th class="text-end">Amount</th><th>Note</th></tr>
          <?php else: ?>
          <tr><th>#</th><th>Receipt</th><th class="text-end">Total</th><th class="text-end">Profit</th><th>Created</th></tr>
          <?php endif; ?>
        </thead>
        <tbody>
          <?php if ($type === 'expenses'): foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= e($r['date']) ?></td>
              <td><?= e($r['category']) ?></td>
              <td class="text-end"><?= e(money($r['amount'])) ?></td>
              <td class="small"><?= e($r['note'] ?? '') ?></td>
            </tr>
          <?php endforeach; else: foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><a href="<?= BASE_URL ?>/?page=receipt&id=<?= (int)$r['id'] ?>"><?= e($r['receipt_no']) ?></a></td>
              <td class="text-end"><?= e(money($r['total'])) ?></td>
              <td class="text-end"><?= e(money($r['profit'])) ?></td>
              <td class="small text-muted"><?= e($r['created_at']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          <?php if (!$rows): ?>
          <tr><td colspan="5" class="text-center text-muted">No data in range.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
