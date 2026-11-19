<?php
// pages/receipt.php — robust receipt viewer (id/no), safe barcode width, working Print & PDF
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/auth.php';

require_login(); require_page_access('receipt');

/* Fallback: if barcode helper wasn’t loaded for any reason */
if (!function_exists('barcode_code128_svg')) {
  function barcode_code128_svg(string $code, int $height = 40, int $scale = 2): string {
    $w = max(120, strlen($code) * $scale * 7);
    return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$height.'"><rect x="0" y="0" width="'.$w.'" height="'.$height.'" fill="#fff"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="monospace" font-size="'.($height*0.45).'" fill="#333">'.e($code).'</text></svg>';
  }
}

function pget(string $k): ?string { return isset($_GET[$k]) && $_GET[$k] !== '' ? trim((string)$_GET[$k]) : null; }

$byId = pget('id') ?? pget('sale_id') ?? pget('sid');
$byNo = pget('no') ?? pget('receipt_no');

$header = null; $lines  = []; $receipt_no = '';
try {
  if ($byId !== null && ctype_digit($byId)) {
    $st = $pdo->prepare("SELECT * FROM sales WHERE id=? LIMIT 1"); $st->execute([(int)$byId]);
    $header = $st->fetch(PDO::FETCH_ASSOC);
  } elseif ($byNo !== null) {
    $st = $pdo->prepare("SELECT * FROM sales WHERE receipt_no=? LIMIT 1"); $st->execute([$byNo]);
    $header = $st->fetch(PDO::FETCH_ASSOC);
  }
  if (!$header) { $st = $pdo->query("SELECT * FROM sales ORDER BY id DESC LIMIT 1"); $header = $st->fetch(PDO::FETCH_ASSOC) ?: null; }
  if ($header) {
    $sid = (int)$header['id'];
    $receipt_no = $header['receipt_no'] ?: ('RCPT-'.$sid);
    $it = $pdo->prepare("
      SELECT si.*, p.sku, p.name
      FROM sale_items si
      JOIN products p ON p.id = si.product_id
      WHERE si.sale_id=?
      ORDER BY si.id ASC
    ");
    $it->execute([$sid]); $lines = $it->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) { /* show not found */ }

$company = [
  'name'  => setting_get('company_name', APP_NAME),
  'phone' => setting_get('company_phone', ''),
  'email' => setting_get('company_email', ''),
  'addr'  => setting_get('company_address', '')
];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0">Receipt</h5>
  <div class="d-print-none">
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/?page=sales_history">Back</a>
    <?php if ($header): ?>
      <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>/?page=receipt_pdf&id=<?= (int)$header['id'] ?>">Download PDF</a>
    <?php endif; ?>
    <button class="btn btn-primary btn-sm" type="button" onclick="printReceipt()">Print</button>
  </div>
</div>

<?php if (!$header): ?>
  <div class="alert alert-warning">Receipt not found.</div>
<?php else: ?>
  <div class="receipt paper mx-auto" id="receipt">
    <div class="text-center">
      <div class="fw-bold"><?= e($company['name']) ?></div>
      <?php if ($company['addr']): ?><div class="small"><?= e($company['addr']) ?></div><?php endif; ?>
      <?php if ($company['phone']): ?><div class="small">Tel: <?= e($company['phone']) ?></div><?php endif; ?>
      <?php if ($company['email']): ?><div class="small"><?= e($company['email']) ?></div><?php endif; ?>
    </div>
    <hr>
    <div class="d-flex justify-content-between">
      <div class="small">Receipt</div>
      <div class="small"><?= e($receipt_no) ?></div>
    </div>
    <div class="d-flex justify-content-between">
      <div class="small">Date</div>
      <div class="small"><?= e($header['created_at']) ?></div>
    </div>
    <hr>
    <table class="w-100 small">
      <thead>
        <tr>
          <th class="text-start">Item</th>
          <th class="text-end">Qty</th>
          <th class="text-end">Price</th>
          <th class="text-end">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $li): ?>
        <tr>
          <td class="text-start"><?= e($li['name']) ?></td>
          <td class="text-end"><?= (int)$li['quantity'] ?></td>
          <td class="text-end"><?= e(money($li['actual_price'] ?: $li['price_sell'])) ?></td>
          <td class="text-end"><?= e(money($li['line_total'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <hr>
    <div class="d-flex justify-content-between small">
      <div>Subtotal</div><div><?= e(money($header['total'])) ?></div>
    </div>
    <?php if ((float)$header['tax_amount'] > 0): ?>
    <div class="d-flex justify-content-between small">
      <div>Tax (<?= e($header['tax_rate']) ?>%)</div><div><?= e(money($header['tax_amount'])) ?></div>
    </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between fw-bold">
      <div>Grand Total</div><div><?= e(money($header['grand_total'] ?: $header['total'])) ?></div>
    </div>
    <div class="d-flex justify-content-between small">
      <div>Payment</div><div class="text-uppercase"><?= e($header['payment_method']) ?></div>
    </div>

    <div class="text-center mt-3 barcode-wrap">
      <!-- scale = 1 to keep it narrow -->
      <?= barcode_code128_svg($receipt_no ?: ('RCPT-'.(int)$header['id']), 40, 1) ?>
      <div class="small text-muted mt-1"><?= e($receipt_no) ?></div>
    </div>

    <div class="text-center mt-2 small">
      Thank you for your business
    </div>
  </div>
<?php endif; ?>

<style>
#receipt.paper{
  width: 320px; max-width: 100%;
  padding: 10px 12px; background:#fff; border:1px dashed #ccc; border-radius:6px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Courier New", monospace;
}
#receipt table th, #receipt table td { padding: 4px 0; }
#receipt .barcode-wrap svg{ max-width:100%; height:auto; }
@media print{
  body * { visibility: hidden; }
  #receipt, #receipt * { visibility: visible; }
  #receipt { position: absolute; left: 0; top: 0; width: 280px; }
  .d-print-none { display: none !important; }
}
</style>

<script>
function printReceipt(){ window.print(); }
</script>
