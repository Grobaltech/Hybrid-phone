<?php
// pages/product_edit.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
require_login(); // restrict by role
// We'll allow admin/manager to edit:
$user = current_user();
if (!$user || !in_array($user['role'], ['admin','manager'], true)) {
  http_response_code(403);
  echo '<div class="alert alert-danger m-3">Only admin/manager can edit products.</div>';
  return;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo '<div class="alert alert-warning m-3">Invalid product.</div>'; return; }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $sku   = trim($_POST['sku'] ?? '');
  $name  = trim($_POST['name'] ?? '');
  $sell  = (float)($_POST['price_sell'] ?? 0);
  $cost  = (float)($_POST['price_cost'] ?? 0);
  $stock = (int)($_POST['stock'] ?? 0);

  if ($name === '') {
    $msg = '<div class="alert alert-warning">Name is required.</div>';
  } else {
    $st = $pdo->prepare('UPDATE products SET sku=?, name=?, price_sell=?, price_cost=?, stock=? WHERE id=?');
    $st->execute([$sku ?: null, $name, $sell, $cost, $stock, $id]);
    $msg = '<div class="alert alert-success">Product updated.</div>';
  }
}

$st = $pdo->prepare('SELECT * FROM products WHERE id=?');
$st->execute([$id]);
$p = $st->fetch();
if (!$p) { echo '<div class="alert alert-warning m-3">Product not found.</div>'; return; }
?>
<div class="card shadow-sm">
  <div class="card-body">
    <h5 class="card-title">Edit Product</h5>
    <?= $msg ?>
    <form method="post" class="row g-3">
      <div class="col-md-4">
        <label class="form-label">SKU</label>
        <input class="form-control" name="sku" value="<?= e($p['sku']) ?>">
      </div>
      <div class="col-md-8">
        <label class="form-label">Name</label>
        <input class="form-control" name="name" value="<?= e($p['name']) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Sell Price</label>
        <input type="number" step="0.01" class="form-control" name="price_sell" value="<?= e($p['price_sell']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Cost Price</label>
        <input type="number" step="0.01" class="form-control" name="price_cost" value="<?= e($p['price_cost']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Stock</label>
        <input type="number" class="form-control" name="stock" value="<?= (int)$p['stock'] ?>">
      </div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary">Save changes</button>
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/?page=products">Back</a>
      </div>
    </form>
  </div>
</div>
