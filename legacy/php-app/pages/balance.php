<?php
// pages/balance.php — Balance Sheet (clear cash-at-hand math + quick ranges + PDF button)
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

require_login(); require_page_access('reports');

/* -------- Date range handling ---------- */
$period = $_GET['period'] ?? 'monthly'; // daily | weekly | monthly | annual | custom
$today  = new DateTimeImmutable('today');

switch ($period) {
  case 'daily':
    $from = $_GET['from'] ?? $today->format('Y-m-d');
    $to   = $_GET['to']   ?? $today->format('Y-m-d');
    break;
  case 'weekly':
    $monday = (new DateTimeImmutable('monday this week'));
    $sunday = $monday->modify('+6 day');
    $from = $_GET['from'] ?? $monday->format('Y-m-d');
    $to   = $_GET['to']   ?? $sunday->format('Y-m-d');
    break;
  case 'annual':
    $y1 = (new DateTimeImmutable('first day of january'))->format('Y-m-d');
    $y2 = (new DateTimeImmutable('last day of december'))->format('Y-m-d');
    $from = $_GET['from'] ?? $y1;
    $to   = $_GET['to']   ?? $y2;
    break;
  case 'custom':
    $from = $_GET['from'] ?? $today->format('Y-m-01');
    $to   = $_GET['to']   ?? $today->format('Y-m-d');
    break;
  case 'monthly':
  default:
    $m1 = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
    $m2 = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
    $from = $_GET['from'] ?? $m1;
    $to   = $_GET['to']   ?? $m2;
    $period = 'monthly';
    break;
}

$from_ok = preg_match('/^\d{4}-\d{2}-\d{2}$/',$from);
$to_ok   = preg_match('/^\d{4}-\d{2}-\d{2}$/',$to);
if (!$from_ok || !$to_ok) {
  $from = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
  $to   = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
  $period = 'monthly';
}

/* -------- Safe SUM helper ---------- */
function sum_or_zero(PDO $pdo, string $sql, array $params): float {
  try { $st=$pdo->prepare($sql); $st->execute($params); $v=(float)$st->fetchColumn(); return $v?:0.0; }
  catch (Throwable $e) { return 0.0; }
}

/* -------- Core numbers ---------- */
$total_sales  = sum_or_zero($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE DATE(created_at) BETWEEN ? AND ?", [$from,$to]);
$credit_sales = sum_or_zero($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE payment_method='credit' AND DATE(created_at) BETWEEN ? AND ?", [$from,$to]);
$expenses_sum = sum_or_zero($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(created_at) BETWEEN ? AND ?", [$from,$to]);
$profit_sum   = sum_or_zero($pdo, "SELECT COALESCE(SUM(profit),0) FROM sales WHERE DATE(created_at) BETWEEN ? AND ?", [$from,$to]);

$cash_sales   = $total_sales - $credit_sales;
$cash_at_hand = $total_sales - $credit_sales - $expenses_sum; // == cash_sales - expenses

/* -------- Daily table ---------- */
try {
  $daily = $pdo->prepare("
    SELECT DATE(created_at) AS d,
           SUM(CASE WHEN payment_method='cash' THEN grand_total ELSE 0 END)   AS cash_sales,
           SUM(CASE WHEN payment_method='credit' THEN grand_total ELSE 0 END) AS credit_sales,
           SUM(profit) AS profit
    FROM sales
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at) DESC
  ");
  $daily->execute([$from,$to]);
  $daily_rows = $daily->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $daily_rows = [];
}
?>
<!-- Title tile / breadcrumb-style header -->
<div class="card shadow-sm mb-3">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
      <div>
        <div class="text-muted small">Reports ▸ Balance</div>
        <h4 class="mb-1">Balance Sheet</h4>
        <div class="small text-muted">as of <?= e($from) ?> to <?= e($to) ?> (<?= e(ucfirst($period)) ?>)</div>
      </div>

      <div class="d-flex flex-wrap gap-2">
        <div class="btn-group" role="group">
          <a class="btn btn-outline-secondary <?= $period==='daily'?'active':'' ?>"   href="<?= BASE_URL ?>/?page=balance&period=daily">Daily</a>
          <a class="btn btn-outline-secondary <?= $period==='weekly'?'active':'' ?>"  href="<?= BASE_URL ?>/?page=balance&period=weekly">Weekly</a>
          <a class="btn btn-outline-secondary <?= $period==='monthly'?'active':'' ?>" href="<?= BASE_URL ?>/?page=balance&period=monthly">Monthly</a>
          <a class="btn btn-outline-secondary <?= $period==='annual'?'active':'' ?>"  href="<?= BASE_URL ?>/?page=balance&period=annual">Annual</a>
        </div>
        <a class="btn btn-primary"
           href="<?= BASE_URL ?>/?page=balance_pdf&from=<?= e($from) ?>&to=<?= e($to) ?>&period=<?= e($period) ?>">
          Download PDF
        </a>
      </div>
    </div>

    <form class="row g-2 mt-3" method="get" action="">
      <input type="hidden" name="page" value="balance">
      <input type="hidden" name="period" value="custom">
      <div class="col-auto">
        <label class="form-label small mb-1">From</label>
        <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label small mb-1">To</label>
        <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
      </div>
      <div class="col-auto align-self-end">
        <button class="btn btn-outline-secondary">Apply</button>
      </div>
    </form>
  </div>
</div>

<!-- KPIs -->
<div class="row g-3">
  <div class="col-md-3"><div class="card kpi-card shadow-sm"><div class="card-body">
    <div class="text-muted small">Total Sales</div>
    <div class="h4 mb-0">UGX <?= money($total_sales) ?></div>
  </div></div></div>

  <div class="col-md-3"><div class="card kpi-card shadow-sm"><div class="card-body">
    <div class="text-muted small">Credit Sales (not cash)</div>
    <div class="h4 mb-0">UGX <?= money($credit_sales) ?></div>
  </div></div></div>

  <div class="col-md-3"><div class="card kpi-card shadow-sm"><div class="card-body">
    <div class="text-muted small">Expenses</div>
    <div class="h4 mb-0">UGX <?= money($expenses_sum) ?></div>
  </div></div></div>

  <div class="col-md-3"><div class="card kpi-card shadow-sm"><div class="card-body">
    <div class="text-muted small">Cash at hand</div>
    <div class="h4 mb-0">UGX <?= money($cash_at_hand) ?></div>
    <div class="small text-muted mt-1">= Total Sales − Credit Sales − Expenses</div>
  </div></div></div>
</div>

<!-- Breakdown & Daily -->
<div class="row g-3 mt-1">
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="card-title">Breakdown (period)</h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <tbody>
              <tr><th>Total sales</th><td class="text-end">UGX <?= money($total_sales) ?></td></tr>
              <tr><th>Less: Credit sales</th><td class="text-end">UGX <?= money($credit_sales) ?></td></tr>
              <tr class="table-light"><th>Cash sales (received)</th><td class="text-end fw-bold">UGX <?= money($cash_sales) ?></td></tr>
              <tr><th>Less: Expenses</th><td class="text-end">UGX <?= money($expenses_sum) ?></td></tr>
              <tr class="table-active"><th>Cash at hand</th><td class="text-end fw-bold">UGX <?= money($cash_at_hand) ?></td></tr>
              <tr><th>Profit (info)</th><td class="text-end">UGX <?= money($profit_sum) ?></td></tr>
            </tbody>
          </table>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-2">
          <div class="small text-muted">“Cash at hand” = money made − credit sales − expenses.</div>
          <a class="btn btn-sm btn-outline-primary"
             href="<?= BASE_URL ?>/?page=balance_pdf&from=<?= e($from) ?>&to=<?= e($to) ?>&period=<?= e($period) ?>">
            Download PDF
          </a>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="card-title mb-0">Daily Summary</h6>
          <div class="small text-muted"><?= e($from) ?> → <?= e($to) ?></div>
        </div>
        <div class="table-responsive" style="max-height:420px;">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Date</th>
                <th class="text-end">Cash Sales</th>
                <th class="text-end">Credit Sales</th>
                <th class="text-end">Profit</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($daily_rows as $r): ?>
              <tr>
                <td><?= e($r['d']) ?></td>
                <td class="text-end">UGX <?= money($r['cash_sales']) ?></td>
                <td class="text-end">UGX <?= money($r['credit_sales']) ?></td>
                <td class="text-end">UGX <?= money($r['profit']) ?></td>
              </tr>
              <?php endforeach; if (!$daily_rows): ?>
              <tr><td colspan="4" class="text-center text-muted">No sales in this period.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
.kpi-card .h4 { font-weight:800; letter-spacing:.2px; }
</style>
