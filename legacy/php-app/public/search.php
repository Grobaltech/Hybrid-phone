<?php
// public/search.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

header('Content-Type: application/json; charset=utf-8');

require_login(); // any logged-in user can search

$q = trim($_GET['q'] ?? '');
$limit = 8;
$out = ['products'=>[], 'customers'=>[], 'sales'=>[], 'credits'=>[]];

if ($q === '') { echo json_encode($out); exit; }

/* ---- helper: build LIKE with wildcards & null-safe ---- */
function likeWrap($s) { return "%{$s}%"; }
$raw = $q;                       // raw for exact boosts
$like = likeWrap($q);            // %query%
$canSeeCost = function_exists('has_cap') ? has_cap('view_costs') : false;

/* ---- PRODUCTS: match by SKU and Name (case-insensitive), null-safe SKU ---- */
$ps = $pdo->prepare("
  SELECT id, sku, name, price_sell, price_cost, stock
  FROM products
  WHERE (COALESCE(sku,'') LIKE ? OR name LIKE ?)
  ORDER BY
    (sku = ?) DESC,               -- exact SKU boost
    (name LIKE ?) DESC,           -- prefix/contains boost
    id DESC
  LIMIT $limit
");
$ps->execute([$like, $like, $raw, $like]);
while ($p = $ps->fetch(PDO::FETCH_ASSOC)) {
  $out['products'][] = [
    'id'    => (int)$p['id'],
    'label' => trim(($p['sku'] ? $p['sku'].' — ' : '') . $p['name']),
    'sku'   => $p['sku'],
    'name'  => $p['name'],
    'sell'  => (float)$p['price_sell'],
    'cost'  => $canSeeCost ? (float)$p['price_cost'] : null,
    'stock' => (int)$p['stock'],
    'url'   => BASE_URL . '/?page=product_edit&id=' . (int)$p['id'],
    'type'  => 'product'
  ];
}

/* ---- CUSTOMERS: name / phone / email ---- */
$cs = $pdo->prepare("
  SELECT id, name, phone, email
  FROM customers
  WHERE (name LIKE ? OR phone LIKE ? OR email LIKE ?)
  ORDER BY (name LIKE ?) DESC, id DESC
  LIMIT $limit
");
$cs->execute([$like, $like, $like, $like]);
while ($c = $cs->fetch(PDO::FETCH_ASSOC)) {
  $out['customers'][] = [
    'id'    => (int)$c['id'],
    'label' => trim($c['name'] . ($c['phone'] ? ' • '.$c['phone'] : '')),
    'name'  => $c['name'],
    'phone' => $c['phone'],
    'email' => $c['email'],
    'url'   => BASE_URL . '/?page=credits&customer_id=' . (int)$c['id'],
    'type'  => 'customer'
  ];
}

/* ---- SALES: by numeric id or date (YYYY-MM-DD) ---- */
if (ctype_digit($raw)) {
  $ss = $pdo->prepare("SELECT id, grand_total, created_at FROM sales WHERE id = ? LIMIT $limit");
  $ss->execute([(int)$raw]);
} else {
  $ss = $pdo->prepare("SELECT id, grand_total, created_at FROM sales WHERE DATE(created_at) LIKE ? ORDER BY id DESC LIMIT $limit");
  $ss->execute([$like]);
}
while ($s = $ss->fetch(PDO::FETCH_ASSOC)) {
  $out['sales'][] = [
    'id'    => (int)$s['id'],
    'label' => 'Sale #'.$s['id'].' — UGX '.number_format($s['grand_total'],2).' • '.$s['created_at'],
    'url'   => BASE_URL . '/?page=receipt&sale_id=' . (int)$s['id'],
    'type'  => 'sale'
  ];
}

/* ---- CREDITS: by sale id or customer name ---- */
$cr = $pdo->prepare("
  SELECT cr.id, cr.sale_id, cr.balance, cr.status, cu.name AS customer
  FROM credits cr
  LEFT JOIN customers cu ON cu.id=cr.customer_id
  WHERE (CAST(cr.sale_id AS CHAR) LIKE ? OR cu.name LIKE ?)
  ORDER BY cr.id DESC
  LIMIT $limit
");
$cr->execute([$like, $like]);
while ($r = $cr->fetch(PDO::FETCH_ASSOC)) {
  $out['credits'][] = [
    'id'      => (int)$r['id'],
    'label'   => 'Credit #'.$r['id'].' — Sale #'.$r['sale_id'].' — '.($r['customer'] ?: 'Unknown').' • Bal: UGX '.number_format($r['balance'],2).' ('.$r['status'].')',
    'url'     => BASE_URL . '/?page=credits',
    'type'    => 'credit'
  ];
}

echo json_encode($out);
