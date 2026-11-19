<?php
// pages/dashboard.php — quick KPIs
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';
require_login();

$today = date('Y-m-d');
$sumSales = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM sales WHERE DATE(created_at)=?");
$sumSales->execute([$today]); $todaySales = (float)$sumSales->fetchColumn();

$sumProfit = $pdo->prepare("SELECT COALESCE(SUM(profit),0) FROM sales WHERE DATE(created_at)=?");
$sumProfit->execute([$today]); $todayProfit = (float)$sumProfit->fetchColumn();

$lowStock = $pdo->query("SELECT COUNT(*) FROM products WHERE stock <= 5")->fetchColumn();
?>
<div class="row g-3">
  <div class="col-md-4">
    <div class="border rounded p-3">
      <div class="text-muted small">Today's Sales</div>
      <div class="h4 mb-0"><?= e(money($todaySales)) ?></div>
    </div>
  </div>

  <?php if (is_admin()): ?>
  <div class="col-md-4">
    <div class="border rounded p-3">
      <div class="text-muted small">Today's Profit</div>
      <div class="h4 mb-0"><?= e(money($todayProfit)) ?></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-md-4">
    <div class="border rounded p-3">
      <div class="text-muted small">Low Stock Items</div>
      <div class="h4 mb-0"><?= (int)$lowStock ?></div>
    </div>
  </div>
</div>
