<?php
// pages/products.php — Clean 2-card layout (Add Product + Product List)
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

require_login(); 
require_page_access('products');

$flash = '';

/* Add product */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
  if (function_exists('csrf_check')) csrf_check();

  $sku        = trim($_POST['sku'] ?? '');
  $name       = trim($_POST['name'] ?? '');
  $category   = trim($_POST['category'] ?? '');
  $price_cost = (float)($_POST['price_cost'] ?? 0);
  $price_sell = (float)($_POST['price_sell'] ?? 0);
  $stock      = (int)($_POST['stock'] ?? 0);

  if ($sku && $name) {
    $pdo->prepare("INSERT INTO products (sku, name, category, price_cost, price_sell, stock) VALUES (?,?,?,?,?,?)")
        ->execute([$sku, $name, $category, $price_cost, $price_sell, $stock]);
    $flash = '<div class="alert alert-success">✅ Product added successfully.</div>';
  } else {
    $flash = '<div class="alert alert-warning">⚠️ SKU and Name are required.</div>';
  }
}

/* Update stock */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_stock') {
  if (function_exists('csrf_check')) csrf_check();

  $id    = (int)($_POST['id'] ?? 0);
  $stock = (int)($_POST['stock'] ?? 0);

  if ($id > 0) {
    $pdo->prepare("UPDATE products SET stock = ? WHERE id = ?")->execute([$stock, $id]);
    $flash = '<div class="alert alert-success">✅ Stock updated successfully.</div>';
  }
}

/* Load products */
$rows = $pdo->query("SELECT id, sku, name, category, price_cost, price_sell, stock FROM products ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- ADD PRODUCT CARD -->
<div class="card shadow-sm mb-4">
  <div class="card-body">
    <h5 class="card-title mb-3">Add Product</h5>
    <?= $flash ?>

    <form method="post" class="row g-2">
      <input type="hidden" name="action" value="create">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>

      <div class="col-md-2"><input required class="form-control" name="sku" placeholder="SKU"></div>
      <div class="col-md-4"><input required class="form-control" name="name" placeholder="Product Name"></div>
      <div class="col-md-3"><input class="form-control" name="category" placeholder="Category"></div>
      <div class="col-md-3"><input type="number" class="form-control" name="stock" placeholder="Stock"></div>

      <div class="col-md-3"><input required type="number" step="0.01" class="form-control" name="price_cost" placeholder="Cost Price"></div>
      <div class="col-md-3"><input required type="number" step="0.01" class="form-control" name="price_sell" placeholder="Sell Price"></div>
      <div class="col-md-3 d-grid"><button class="btn btn-primary">Add Product</button></div>
    </form>
  </div>
</div>

<!-- PRODUCT LIST CARD -->
<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="card-title mb-0">Product List</h5>
      <form class="d-flex" role="search" id="productSearchForm" onsubmit="return false;">
        <input class="form-control form-control-sm" id="productSearch" placeholder="Search products..." style="width:220px;">
      </form>
    </div>

    <div class="table-responsive" style="max-height: 500px;">
      <table class="table table-sm table-hover align-middle table-products" id="productTable">
        <thead class="table-light">
          <tr>
            <th>SKU</th>
            <th>Name</th>
            <th>Category</th>
            <th class="text-end">Cost</th>
            <th class="text-end">Sell</th>
            <th class="text-end">Stock</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e($r['sku']) ?></td>
              <td><?= e($r['name']) ?></td>
              <td><?= e($r['category']) ?></td>
              <td class="price">UGX <?= number_format((float)$r['price_cost'], 2) ?></td>
              <td class="price">UGX <?= number_format((float)$r['price_sell'], 2) ?></td>
              <td class="stock"><?= (int)$r['stock'] ?></td>
              <td class="text-end cell-actions">
                <div class="d-flex justify-content-end gap-2">
                  <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/?page=product_edit&id=<?= (int)$r['id'] ?>">Edit</a>
                  <form method="post" class="d-flex gap-2">
                    <input type="hidden" name="action" value="update_stock">
                    <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="number" class="form-control form-control-sm text-end" name="stock" value="<?= (int)$r['stock'] ?>" style="width: 80px;">
                    <button class="btn btn-sm btn-outline-secondary">Update</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="text-center text-muted">No products added yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
/* Simple table filter (live search) */
document.getElementById('productSearch').addEventListener('keyup', function(){
  const term = this.value.toLowerCase();
  document.querySelectorAll('#productTable tbody tr').forEach(tr=>{
    const text = tr.innerText.toLowerCase();
    tr.style.display = text.includes(term) ? '' : 'none';
  });
});
</script>
