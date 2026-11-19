<?php
// pages/receipt_pdf.php — stream/download a PDF of a sale receipt
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/auth.php';

require_login(); require_page_access('receipt_pdf');

function pget(string $k): ?string {
  return isset($_GET[$k]) && $_GET[$k] !== '' ? trim((string)$_GET[$k]) : null;
}

$byId = pget('id') ?? pget('sale_id') ?? pget('sid');
$byNo = pget('no') ?? pget('receipt_no');

$header = null; $lines = []; $receipt_no = '';

try {
  if ($byId !== null && ctype_digit($byId)) {
    $st = $pdo->prepare("SELECT * FROM sales WHERE id=? LIMIT 1");
    $st->execute([(int)$byId]);
    $header = $st->fetch(PDO::FETCH_ASSOC);
  } elseif ($byNo !== null) {
    $st = $pdo->prepare("SELECT * FROM sales WHERE receipt_no=? LIMIT 1");
    $st->execute([$byNo]);
    $header = $st->fetch(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) { /* ignore */ }

if (!$header) {
  http_response_code(400);
  echo '<div style="font-family:system-ui;padding:1rem">Missing or invalid sale id/number.</div>';
  exit;
}

$sid = (int)$header['id'];
$receipt_no = $header['receipt_no'] ?: ('RCPT-'.$sid);

$it = $pdo->prepare("
  SELECT si.*, p.sku, p.name
  FROM sale_items si
  JOIN products p ON p.id = si.product_id
  WHERE si.sale_id=?
  ORDER BY si.id ASC
");
$it->execute([$sid]);
$lines = $it->fetchAll(PDO::FETCH_ASSOC);

$company = [
  'name'  => setting_get('company_name', APP_NAME),
  'phone' => setting_get('company_phone', ''),
  'email' => setting_get('company_email', ''),
  'addr'  => setting_get('company_address', '')
];

/* --- Build HTML for Dompdf --- */
$rows = '';
foreach ($lines as $li) {
  $rows .= '<tr>'.
    '<td>'.e($li['name']).'</td>'.
    '<td class="r">'.(int)$li['quantity'].'</td>'.
    '<td class="r">'.e(money($li['actual_price'] ?: $li['price_sell'])).'</td>'.
    '<td class="r">'.e(money($li['line_total'])).'</td>'.
    '</tr>';
}

$taxHtml = '';
if ((float)$header['tax_amount'] > 0) {
  $taxHtml = '<tr><td colspan="3" class="r small">Tax ('.e($header['tax_rate']).'%):</td><td class="r">'.e(money($header['tax_amount'])).'</td></tr>';
}

// Basic CSS for a thermal-like A6 page
$html = '
<html><head><meta charset="utf-8">
<style>
*{font-family: DejaVu Sans, sans-serif;}
.small{font-size:11px;color:#444}
.r{text-align:right}
h3{margin:0 0 6px 0;padding:0;text-align:center}
.card{width: 76mm; padding:6mm 5mm; border:1px dashed #bbb;}
table{width:100%; border-collapse:collapse}
th,td{padding:3px 0; font-size:12px}
hr{border:none;border-top:1px solid #ddd;margin:6px 0}
.center{text-align:center}
.mono{font-family: DejaVu Sans Mono, monospace}
</style></head><body>
<div class="card">
  <div class="center">
    <div><strong>'.e($company['name']).'</strong></div>'.
    ($company['addr']?'<div class="small">'.e($company['addr']).'</div>':'').
    ($company['phone']?'<div class="small">Tel: '.e($company['phone']).'</div>':'').
    ($company['email']?'<div class="small">'.e($company['email']).'</div>':'').
  '</div>
  <hr>
  <table>
    <tr><td class="small">Receipt:</td><td class="r mono">'.e($receipt_no).'</td></tr>
    <tr><td class="small">Date:</td><td class="r">'.e($header['created_at']).'</td></tr>
  </table>
  <hr>
  <table>
    <thead><tr><th class="l">Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Total</th></tr></thead>
    <tbody>'.$rows.'</tbody>
  </table>
  <hr>
  <table>
    <tr><td colspan="3" class="r small">Subtotal:</td><td class="r">'.e(money($header['total'])).'</td></tr>'.
    $taxHtml.
    '<tr><td colspan="3" class="r"><strong>Grand Total:</strong></td><td class="r"><strong>'.e(money($header['grand_total'] ?: $header['total'])).'</strong></td></tr>
    <tr><td colspan="3" class="r small">Payment:</td><td class="r">'.e(strtoupper($header['payment_method'])).'</td></tr>
  </table>
  <hr>
  <div class="center small">Thank you for your business</div>
</div>
</body></html>';

/* --- Stream with Dompdf --- */
try {
  require_once __DIR__ . '/../vendor/autoload.php';
  $dompdf = new Dompdf\Dompdf([
    'isRemoteEnabled' => true,
    'isHtml5ParserEnabled' => true
  ]);
  $dompdf->loadHtml($html);
  $dompdf->setPaper('A6', 'portrait'); // thermal-ish
  $dompdf->render();
  $dompdf->stream($receipt_no.'.pdf', ['Attachment' => true]);
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  echo '<div style="font-family:system-ui;padding:1rem">PDF error: '.e($e->getMessage()).'</div>';
  exit;
}
