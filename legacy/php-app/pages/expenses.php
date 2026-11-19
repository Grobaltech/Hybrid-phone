<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
  $pdo->prepare('INSERT INTO expenses (date, category, amount, note) VALUES (?,?,?,?)')
      ->execute([$_POST['date'], $_POST['category'], $_POST['amount'], $_POST['note']]);
  echo '<div class="alert alert-success">Expense saved.</div>';
}
$rows = $pdo->query('SELECT * FROM expenses ORDER BY date DESC, id DESC LIMIT 200')->fetchAll();
?>
<div class="card shadow-sm">
  <div class="card-body">
    <h5 class="card-title">Add Expense</h5>
    <form method="post" class="row g-2">
      <input type="hidden" name="action" value="add">
      <div class="col-md-3"><input type="date" class="form-control" name="date" value="<?= date('Y-m-d') ?>"></div>
      <div class="col-md-3"><input class="form-control" name="category" placeholder="Category"></div>
      <div class="col-md-3"><input type="number" step="0.01" class="form-control" name="amount" placeholder="Amount"></div>
      <div class="col-md-3"><input class="form-control" name="note" placeholder="Note (optional)"></div>
      <div class="col-12 d-grid"><button class="btn btn-primary">Save</button></div>
    </form>
  </div>
</div>

<div class="card shadow-sm mt-3">
  <div class="card-body">
    <h5 class="card-title">Recent Expenses</h5>
    <div class="table-responsive">
      <table class="table table-sm">
        <thead><tr><th>Date</th><th>Category</th><th>Amount</th><th>Note</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e($r['date']) ?></td>
              <td><?= e($r['category']) ?></td>
              <td>UGX <?= money($r['amount']) ?></td>
              <td><?= e($r['note']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
