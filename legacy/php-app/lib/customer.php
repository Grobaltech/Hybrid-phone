<?php
// lib/customer.php — resolve or create customer by name (optional)
require_once __DIR__ . '/db.php';

function resolve_customer_id_by_name(?string $name): ?int {
  global $pdo;
  $n = trim((string)$name);
  if ($n === '') return null;
  // try existing
  $s = $pdo->prepare("SELECT id FROM customers WHERE name = ? LIMIT 1");
  $s->execute([$n]); $id = $s->fetchColumn();
  if ($id) return (int)$id;
  // create new lightweight record
  $ins = $pdo->prepare("INSERT INTO customers (name) VALUES (?)");
  $ins->execute([$n]);
  return (int)$pdo->lastInsertId();
}
