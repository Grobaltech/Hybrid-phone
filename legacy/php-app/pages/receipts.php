<?php
// pages/receipts.php — filterable list (date range, payment method, search)
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

require_login(); require_page_access('sales'); // or a dedicated 'receipts' permission

$search = trim($_GET['q'] ?? '');
$pm     = $_GET['pm'] ?? '';
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';

$sql = "SELECT s.id, s.receipt_no, s.created_at, s.payment_method, s.grand_total, c.name AS customer
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE 1=1";
$bind = [];

if ($search !== '') {
  $sql .= " AND (s.receipt_no LIKE ? OR s.id = ? OR c.name LIKE ?)";
  $like = '%'.$search.'%';
  $idEq = ctype_digit($search) ? (int)$search : -1;
  $bind[] = $like; $bind[] = $idEq; $bind[] = $like;
}
if ($pm === 'cash' || $pm === 'credit') {
  $sql .= " AND s.payment_method = ?";
  $bind[] = $pm;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) {
  $sql .= " AND DATE(s.created_at) >= ?";
  $bind[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) {
  $sql .= " AND DATE(s.created_at) <= ?";
  $bind[] = $to;
}
$sql .= " ORDER BY s.id DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($bind);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center">
      <h5 class="card-title mb-0">Receipts</h5>
      <form class="row g-2 align-items-end" method="get" action="">
        <input type="hidden" name="page" value="receipts">
        <div class="col-md-3">
          <label class="form-label mb-1">Search</label>
          <input class="form-control" name="q" value="<?= e($search) ?>" placeholder="Receipt no / ID / Customer">
        </div>
        <div class="col-md-2">
          <label class="form-label mb-1">Payment</label>
          <select class="form-select" name="pm">
            <option value="">Any</option>
            <option value="cash" <?= $pm==='cash'?'selected':'' ?>>Cash</option>
            <option value="credit" <?= $pm==='credit'?'selected':'' ?>>Credit</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label mb-1">From</label>
          <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label mb-1">To</label>
          <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
        </div>
        <div class="col-md-3 d-grid">
          <label class="form-label mb-1">&nbsp;</label>
          <button class="btn btn-primary">Filter</button>
        </div>
      </form>
    </div>

    <div class="table-responsive mt-3">
      <table class="table table-sm table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Receipt No</th>
            <th>Customer</th>
            <th>Payment</th>
            <th class="text-end">Total</th>
            <th>Date</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><code><?= e($r['receipt_no']) ?></code></td>
              <td><?= e($r['customer']) ?></td>
              <td><?= e(ucfirst($r['payment_method'])) ?></td>
              <td class="text-end">UGX <?= number_format((float)$r['grand_total'],2) ?></td>
              <td class="small text-muted"><?= e($r['created_at']) ?></td>
              <td class="text-end">
                <div class="btn-group">
                  <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/?page=receipt&sale_id=<?= (int)$r['id'] ?>">View</a>
                  <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/?page=receipt_pdf&sale_id=<?= (int)$r['id'] ?>&size=58">PDF 58</a>
                  <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/?page=receipt_pdf&sale_id=<?= (int)$r['id'] ?>&size=80">PDF 80</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="text-center text-muted">No receipts found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
