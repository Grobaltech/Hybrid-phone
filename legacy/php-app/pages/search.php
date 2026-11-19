<?php
// pages/search.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

require_login();

$q = trim($_GET['q'] ?? '');
$canSeeCost = has_cap('view_costs');

$products = $customers = $sales = $credits = [];

/* Multi-word helper: each word must appear somewhere */
function multi_like_clause($column) {
  // returns "LOWER(column) LIKE ? AND LOWER(column) LIKE ? ..." built by caller
  return "LOWER($column) LIKE ?";
}
$words = array_values(array_filter(array_map('trim', preg_split('/\s+/', strtolower($q))), fn($w)=>$w!==''));
$tokens = array_map(fn($w)=>"%$w%", $words);

if ($q !== '') {
  /* PRODUCTS: null-safe SKU + name, multi-word match */
  $whereP = [];
  $paramsP = [];
  foreach ($tokens as $t) { $whereP[] = "(LOWER(COALESCE(sku,'')) LIKE ? OR LOWER(name) LIKE ?)"; $paramsP[]=$t; $paramsP[]=$t; }
  if (!$whereP) { $whereP[] = "1=1"; }
  $sqlP = "
    SELECT id, sku, name, price_sell, price_cost, stock
    FROM products
    WHERE ".implode(' AND ', $whereP)."
    ORDER BY
      (sku = ?) DESC,
      (name LIKE ?) DESC,
      id DESC
    LIMIT 200
  ";
  $paramsP[] = $q;          // exact SKU boost
  $paramsP[] = "%$q%";      // name contains boost
  $ps = $pdo->prepare($sqlP);
  $ps->execute($paramsP);
  $products = $ps->fetchAll(PDO::FETCH_ASSOC);

  /* CUSTOMERS */
  $whereC = [];
  $paramsC = [];
  foreach ($tokens as $t) { $whereC[] = "(LOWER(name) LIKE ? OR LOWER(phone) LIKE ? OR LOWER(email) LIKE ?)"; $paramsC[]=$t; $paramsC[]=$t; $paramsC[]=$t; }
  if (!$whereC) { $whereC[] = "1=1"; }
  $cs = $pdo->prepare("SELECT id, name, phone, email, created_at FROM customers WHERE ".implode(' AND ', $whereC)." ORDER BY id DESC LIMIT 200");
  $cs->execute($paramsC);
  $customers = $cs->fetchAll(PDO::FETCH_ASSOC);

  /* SALES */
  if (ctype_digit($q)) {
    $ss = $pdo->prepare("SELECT id, grand_total, created_at FROM sales WHERE id = ? ORDER BY id DESC LIMIT 200");
    $ss->execute([(int)$q]);
  } else {
    $ss = $pdo->prepare("SELECT id, grand_total, created_at FROM sales WHERE DATE(created_at) LIKE ? ORDER BY id DESC LIMIT 200");
    $ss->execute(["%$q%"]);
  }
  $sales = $ss->fetchAll(PDO::FETCH_ASSOC);

  /* CREDITS */
  $whereR = [];
  $paramsR = [];
  foreach ($tokens as $t) { $whereR[] = "(CAST(cr.sale_id AS CHAR) LIKE ? OR LOWER(cu.name) LIKE ?)"; $paramsR[]=$t; $paramsR[]=$t; }
  if (!$whereR) { $whereR[] = "1=1"; }
  $cr = $pdo->prepare("
    SELECT cr.id, cr.sale_id, cr.balance, cr.status, cu.name AS customer
    FROM credits cr
    LEFT JOIN customers cu ON cu.id=cr.customer_id
    WHERE ".implode(' AND ', $whereR)."
    ORDER BY cr.id DESC
    LIMIT 200
  ");
  $cr->execute($paramsR);
  $credits = $cr->fetchAll(PDO::FETCH_ASSOC);
}
?>
<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h5 class="card-title">Search</h5>
    <form class="row g-2" method="get">
      <input type="hidden" name="page" value="search">
      <div class="col-md-10"><input name="q" class="form-control" placeholder="Search products, customers, sales..." value="<?= e($q) ?>" autofocus></div>
      <div class="col-md-2 d-grid"><button class="btn btn-primary">Search</button></div>
    </form>
  </div>
</div>

<?php if ($q===''): ?>
  <div class="alert alert-info">Type something to search.</div>
<?php else: ?>
<ul class="nav nav-pills mb-3" id="srTabs" role="tablist">
  <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#srProducts" type="button">Products (<?= count($products) ?>)</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#srCustomers" type="button">Customers (<?= count($customers) ?>)</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#srSales" type="button">Sales (<?= count($sales) ?>)</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#srCredits" type="button">Credits (<?= count($credits) ?>)</button></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="srProducts">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr>
          <th>SKU</th><th>Name</th><th class="text-end">Sell</th><?php if ($canSeeCost): ?><th class="text-end">Cost</th><?php endif; ?><th class="text-end">Stock</th><th class="text-end">Action</th>
        </tr></thead>
        <tbody>
          <?php foreach ($products as $p): ?>
            <tr>
              <td><?= e($p['sku']) ?></td>
              <td><?= e($p['name']) ?></td>
              <td class="text-end">UGX <?= money($p['price_sell']) ?></td>
              <?php if ($canSeeCost): ?><td class="text-end">UGX <?= money($p['price_cost']) ?></td><?php endif; ?>
              <td class="text-end"><?= (int)$p['stock'] ?></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/?page=product_edit&id=<?= (int)$p['id'] ?>">Edit</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!count($products)): ?><tr><td colspan="<?= $canSeeCost?6:5 ?>" class="text-center text-muted">No matches.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tab-pane fade" id="srCustomers">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Since</th><th class="text-end">Action</th></tr></thead>
        <tbody>
          <?php foreach ($customers as $c): ?>
            <tr>
              <td><?= e($c['name']) ?></td>
              <td><?= e($c['phone']) ?></td>
              <td><?= e($c['email']) ?></td>
              <td class="small text-muted"><?= e($c['created_at']) ?></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/?page=credits&customer_id=<?= (int)$c['id'] ?>">View Credits</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!count($customers)): ?><tr><td colspan="5" class="text-center text-muted">No matches.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tab-pane fade" id="srSales">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Sale #</th><th class="text-end">Total</th><th>When</th><th class="text-end">Action</th></tr></thead>
        <tbody>
          <?php foreach ($sales as $s): ?>
            <tr>
              <td>#<?= (int)$s['id'] ?></td>
              <td class="text-end">UGX <?= money($s['grand_total']) ?></td>
              <td class="small text-muted"><?= e($s['created_at']) ?></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/?page=receipt&sale_id=<?= (int)$s['id'] ?>">Receipt</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!count($sales)): ?><tr><td colspan="4" class="text-center text-muted">No matches.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tab-pane fade" id="srCredits">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Credit #</th><th>Sale #</th><th>Customer</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($credits as $r): ?>
            <tr>
              <td>#<?= (int)$r['id'] ?></td>
              <td>#<?= (int)$r['sale_id'] ?></td>
              <td><?= e($r['customer'] ?? '—') ?></td>
              <td class="text-end">UGX <?= money($r['balance']) ?></td>
              <td><?= e(ucfirst($r['status'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!count($credits)): ?><tr><td colspan="5" class="text-center text-muted">No matches.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
