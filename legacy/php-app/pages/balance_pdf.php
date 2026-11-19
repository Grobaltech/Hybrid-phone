<?php
// pages/balance_pdf.php — Downloadable Balance Sheet (A4) for any range
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

require_login(); require_page_access('reports');

$period = $_GET['period'] ?? 'custom';
$from   = $_GET['from'] ?? date('Y-m-01');
$to     = $_GET['to']   ?? date('Y-m-d');
$from_ok = preg_match('/^\d{4}-\d{2}-\d{2}$/',$from);
$to_ok   = preg_match('/^\d{4}-\d{2}-\d{2}$/',$to);
if (!$from_ok || !$to_ok) { $from = date('Y-m-01'); $to = date('Y-m-d'); }

function sum_or_zero(PDO $pdo, string $sql, array $params): float {
  try { $st=$pdo->prepare($sql); $st->execute($params); $v=(float)$st->fetchColumn(); return $v?:0.0; }
  catch(Throwable $e){ return 0.0; }
}

$total_sales  = sum_or_zero($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE DATE(created_at) BETWEEN ? AND ?", [$from,$to]);
$credit_sales = sum_or_zero($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE payment_method='credit' AND DATE(created_at) BETWEEN ? AND ?", [$from,$to]);
$expenses_sum = sum_or_zero($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(created_at) BETWEEN ? AND ?", [$from,$to]);
$profit_sum   = sum_or_zero($pdo, "SELECT COALESCE(SUM(profit),0) FROM sales WHERE DATE(created_at) BETWEEN ? AND ?", [$from,$to]);

$cash_sales   = $total_sales - $credit_sales;
$cash_at_hand = $total_sales - $credit_sales - $expenses_sum;

try {
  $daily = $pdo->prepare("
    SELECT DATE(created_at) AS d,
           SUM(CASE WHEN payment_method='cash' THEN grand_total ELSE 0 END) AS cash_sales,
           SUM(CASE WHEN payment_method='credit' THEN grand_total ELSE 0 END) AS credit_sales,
           SUM(profit) AS profit
    FROM sales
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at) DESC
  ");
  $daily->execute([$from,$to]);
  $daily_rows = $daily->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) { $daily_rows = []; }

ob_start();
?>
<!doctype html>
<html><head><meta charset="utf-8">
<style>
  @page { size: A4 portrait; margin: 24px; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color:#111; }
  h1 { font-size: 18px; margin: 0 0 6px; }
  .muted { color:#555; font-size: 11px; }
  .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 14px 0; }
  .card { border: 1px solid #ddd; border-radius: 6px; padding: 10px; }
  .title { font-size: 11px; color:#666; }
  .value { font-size: 16px; font-weight: 700; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  th, td { padding: 6px 8px; border-bottom: 1px solid #eee; }
  th { text-align: left; background: #fafafa; }
  td.num { text-align: right; }
</style>
</head>
<body>
  <div class="muted">Reports ▸ Balance</div>
  <h1>Balance Sheet</h1>
  <div class="muted">as of <?= e($from) ?> to <?= e($to) ?> (<?= e(ucfirst($period)) ?>)</div>

  <div class="kpis">
    <div class="card"><div class="title">Total Sales</div><div class="value">UGX <?= money($total_sales) ?></div></div>
    <div class="card"><div class="title">Credit Sales (not cash)</div><div class="value">UGX <?= money($credit_sales) ?></div></div>
    <div class="card"><div class="title">Expenses</div><div class="value">UGX <?= money($expenses_sum) ?></div></div>
    <div class="card"><div class="title">Cash at hand</div><div class="value">UGX <?= money($cash_at_hand) ?></div></div>
  </div>

  <div class="card">
    <div class="title" style="margin-bottom:6px;">Breakdown</div>
    <table>
      <tbody>
        <tr><th>Total sales</th><td class="num">UGX <?= money($total_sales) ?></td></tr>
        <tr><th>Less: Credit sales</th><td class="num">UGX <?= money($credit_sales) ?></td></tr>
        <tr><th>Cash sales (received)</th><td class="num"><strong>UGX <?= money($cash_sales) ?></strong></td></tr>
        <tr><th>Less: Expenses</th><td class="num">UGX <?= money($expenses_sum) ?></td></tr>
        <tr><th>Cash at hand</th><td class="num"><strong>UGX <?= money($cash_at_hand) ?></strong></td></tr>
        <tr><th>Profit (info)</th><td class="num">UGX <?= money($profit_sum) ?></td></tr>
      </tbody>
    </table>
  </div>

  <div class="card" style="margin-top:12px;">
    <div class="title" style="margin-bottom:6px;">Daily Summary</div>
    <table>
      <thead><tr><th>Date</th><th class="num">Cash Sales</th><th class="num">Credit Sales</th><th class="num">Profit</th></tr></thead>
      <tbody>
      <?php foreach ($daily_rows as $r): ?>
        <tr>
          <td><?= e($r['d']) ?></td>
          <td class="num">UGX <?= money($r['cash_sales']) ?></td>
          <td class="num">UGX <?= money($r['credit_sales']) ?></td>
          <td class="num">UGX <?= money($r['profit']) ?></td>
        </tr>
      <?php endforeach; if (!$daily_rows): ?>
        <tr><td colspan="4" class="muted" style="text-align:center;">No sales in this period.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</body></html>
<?php
$html = ob_get_clean();

require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;

$dompdf = new Dompdf(['isRemoteEnabled'=>true]);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

if (ob_get_length()) { @ob_end_clean(); } // avoid headers already sent
$dompdf->stream('BalanceSheet_'.$from.'_to_'.$to.'.pdf', ['Attachment'=>true]);
