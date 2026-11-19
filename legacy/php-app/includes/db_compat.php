<?php
// Finds a PDO handle exposed as $db or $pdo by your bootstrap.
function get_pdo() {
  if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) return $GLOBALS['db'];
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  throw new RuntimeException('No PDO instance found. Ensure bootstrap.php sets $db or $pdo.');
}

// Simple capability shim; wire to your real RBAC if you have it.
function current_user_can($cap) {
  if (function_exists('app_current_user_can')) return app_current_user_can($cap);
  // Default: allow everything so it won’t block you while wiring.
  return true;
}

// Check if a column exists on a table (cached per request)
function column_exists(PDO $pdo, $table, $column) {
  static $cache = [];
  $key = "$table::$column";
  if (array_key_exists($key, $cache)) return $cache[$key];
  $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
  $stmt->execute([':c'=>$column]);
  $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
  return $cache[$key];
}
