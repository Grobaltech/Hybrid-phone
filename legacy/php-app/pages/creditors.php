<?php
// pages/creditors.php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';

/**
 * Bootstrap schema (safe, idempotent)
 */
$pdo->exec("
CREATE TABLE IF NOT EXISTS creditors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(50) NULL,
  description TEXT NULL,
  total_credit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS creditor_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  creditor_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cp_creditor FOREIGN KEY (creditor_id) REFERENCES creditors(id) ON DELETE CASCADE,
  INDEX (creditor_id, paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$errors = [];
$success = null;

/**
 * Add a new creditor
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_creditor') {
  $name  = trim($_POST['name'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $desc  = trim($_POST['description'] ?? '');
  $credit= (float)($_POST['total_credit'] ?? 0);
  $paid  = (float)($_POST['amount_paid'] ?? 0);

  if ($name === '') $errors[] = 'Name is required.';
  if ($credit < 0)  $errors[] = 'Total credit cannot be negative.';
  if ($paid   < 0)  $errors[] = 'Amount paid cannot be negative.';
  if ($paid > $credit) $paid = $credit; // cap

  if (!$errors) {
    $balance = $credit - $paid;
    $stmt = $pdo->prepare("INSERT INTO creditors (name, phone, description, total_credit, amount_paid, balance) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$name, $phone, $desc, $credit, $paid, $balance]);
    $success = "Creditor '{$name}' saved successfully.";
  }
}

/**
 * Record a repayment for an existing creditor
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_payment') {
  $cid   = (int)($_POST['creditor_id'] ?? 0);
  $amt   = (float)($_POST['amount'] ?? 0);
  $note  = trim($_POST['note'] ?? '');
  $paid_at_raw = trim($_POST['paid_at'] ?? ''); // datetime-local
  $paid_at = $paid_at_raw !== '' ? date('Y-m-d H:i:s', strtotime($paid_at_raw)) : date('Y-m-d H:i:s');

  if ($cid <= 0)      $errors[] = 'Invalid creditor.';
  if ($amt <= 0)      $errors[] = 'Repayment amount must be greater than zero.';

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // Lock the creditor row for update
      $cStmt = $pdo->prepare("SELECT id, name, total_credit, amount_paid FROM creditors WHERE id = ? FOR UPDATE");
      $cStmt->execute([$cid]);
      $c = $cStmt->fetch();
      if (!$c) throw new Exception('Creditor not found.');

      $total_credit = (float)$c['total_credit'];
      $old_paid     = (float)$c['amount_paid'];

      // New paid cannot exceed total_credit
      $new_paid = $old_paid + $amt;
      if ($new_paid > $total_credit) $new_paid = $total_credit;
      $new_balance = $total_credit - $new_paid;

      // Insert payment record (store the user-entered amount; that's your audit trail)
      $pStmt = $pdo->prepare("INSERT INTO creditor_payments (creditor_id, amount, paid_at, note) VALUES (?,?,?,?)");
      $pStmt->execute([$cid, $amt, $paid_at, $note !== '' ? $note : null]);

      // Update creditor aggregate
      $uStmt = $pdo->prepare("UPDATE creditors SET amount_paid = ?, balance = ? WHERE id = ?");
      $uStmt->execute([$new_paid, $new_balance, $cid]);

      $pdo->commit();
      $success = "Repayment of " . number_format($amt, 2) . " recorded for '{$c['name']}'.";
    } catch (Throwable $ex) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = $ex->getMessage();
    }
  }
}

$creditors = $pdo->query("SELECT * FROM creditors ORDER BY id DESC")->fetchAll();

/** Fetch latest 50 payments overall (for a global history table) */
$payments = $pdo->query("
  SELECT cp.id, cp.creditor_id, cr.name AS creditor_name, cp.amount, cp.paid_at, cp.note
  FROM creditor_payments cp
  JOIN creditors cr ON cr.id = cp.creditor_id
  ORDER BY cp.paid_at DESC, cp.id DESC
  LIMIT 50
")->fetchAll();

/** Helper: fetch last N payments for a specific creditor (shown inline) */
$getPaymentsByCreditor = $pdo->prepare("
  SELECT id, amount, paid_at, note
  FROM creditor_payments
  WHERE creditor_id = ?
  ORDER BY paid_at DESC, id DESC
  LIMIT 5
");
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Creditors & Repayments</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:system-ui,Arial,sans-serif;margin:20px;}
.card{border:1px solid #ddd;border-radius:10px;padding:16px;margin-bottom:16px;}
.btn{padding:8px 12px;border-radius:8px;border:1px solid #999;background:#f7f7f7;cursor:pointer;}
.btn.primary{background:#e9f3ff;border-color:#6aa6ff;}
table{width:100%;border-collapse:collapse;}
th,td{border:1px solid #eee;padding:8px;text-align:left;}
.err{background:#ffecec;border:1px solid #ffb4b4;padding:10px;border-radius:8px;color:#a33;}
.ok{background:#ecffef;border:1px solid #b4ffbf;padding:10px;border-radius:8px;color:#176a2f;}
.right{text-align:right;}
.small{font-size:12px;color:#666;}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
input[type="number"]{max-width:160px;}
input[type="datetime-local"]{max-width:220px;}
summary{cursor:pointer;font-weight:600;}
</style>
</head>
<body>
<h2>Credit Records & Repayments</h2>

<?php if ($errors): ?>
<div class="err"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="ok"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="card">
  <h3>Add Creditor</h3>
  <form method="post">
    <input type="hidden" name="action" value="add_creditor">
    <div class="row">
      <label>Name<br><input name="name" required></label>
      <label>Phone<br><input name="phone"></label>
    </div>
    <label>Description<br><textarea name="description" rows="2" cols="40"></textarea></label><br>
    <div class="row" style="margin-top:8px">
      <label>Total Credit<br><input type="number" name="total_credit" step="0.01" value="0"></label>
      <label>Amount Paid (optional initial)<br><input type="number" name="amount_paid" step="0.01" value="0"></label>
    </div>
    <br>
    <button class="btn primary" type="submit">Save</button>
  </form>
</div>

<div class="card">
  <h3>Existing Creditors</h3>
  <?php if ($creditors): ?>
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Name</th><th>Phone</th><th>Description</th>
        <th class="right">Credit</th><th class="right">Paid</th><th class="right">Balance</th>
        <th>Repay</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($creditors as $c): ?>
      <tr>
        <td><?= (int)$c['id'] ?></td>
        <td><?= htmlspecialchars($c['name']) ?></td>
        <td><?= htmlspecialchars($c['phone']) ?></td>
        <td><?= htmlspecialchars($c['description']) ?></td>
        <td class="right"><?= number_format((float)$c['total_credit'],2) ?></td>
        <td class="right"><?= number_format((float)$c['amount_paid'],2) ?></td>
        <td class="right"><?= number_format((float)$c['balance'],2) ?></td>
        <td>
          <form method="post" style="display:flex; gap:6px; align-items:center;">
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="creditor_id" value="<?= (int)$c['id'] ?>">
            <input type="number" name="amount" step="0.01" min="0.01" placeholder="Amount" required>
            <input type="datetime-local" name="paid_at">
            <input type="text" name="note" placeholder="Note (optional)">
            <button class="btn" type="submit">Record</button>
          </form>
          <details class="small" style="margin-top:6px">
            <summary>Recent payments</summary>
            <?php
              $getPaymentsByCreditor->execute([(int)$c['id']]);
              $cp = $getPaymentsByCreditor->fetchAll();
              if ($cp):
            ?>
              <table class="small" style="width:100%; margin-top:6px;">
                <thead><tr><th>Date</th><th class="right">Amount</th><th>Note</th></tr></thead>
                <tbody>
                <?php foreach ($cp as $p): ?>
                  <tr>
                    <td><?= htmlspecialchars($p['paid_at']) ?></td>
                    <td class="right"><?= number_format((float)$p['amount'],2) ?></td>
                    <td><?= htmlspecialchars($p['note'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="small">No recent payments.</div>
            <?php endif; ?>
          </details>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <em>No creditor records yet.</em>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Latest Repayments (50)</h3>
  <?php if ($payments): ?>
    <table>
      <thead>
        <tr><th>ID</th><th>Creditor</th><th>Date</th><th class="right">Amount</th><th>Note</th></tr>
      </thead>
      <tbody>
        <?php foreach ($payments as $p): ?>
        <tr>
          <td><?= (int)$p['id'] ?></td>
          <td><?= htmlspecialchars($p['creditor_name']) ?> <span class="small">(#<?= (int)$p['creditor_id'] ?>)</span></td>
          <td><?= htmlspecialchars($p['paid_at']) ?></td>
          <td class="right"><?= number_format((float)$p['amount'], 2) ?></td>
          <td><?= htmlspecialchars($p['note'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <em>No repayments recorded yet.</em>
  <?php endif; ?>
</div>
</body>
</html>
