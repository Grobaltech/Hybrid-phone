<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/db.php';
$q = '%' . ($_GET['q'] ?? '') . '%';
$stmt = $pdo->prepare('SELECT id, sku, name, stock FROM products WHERE name LIKE ? OR sku LIKE ? ORDER BY name LIMIT 10');
$stmt->execute([$q, $q]);
echo json_encode($stmt->fetchAll());
?>