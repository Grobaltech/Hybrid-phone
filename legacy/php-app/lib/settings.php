<?php
// lib/settings.php
require_once __DIR__ . '/db.php';

function setting_get($key, $default = '') {
  global $pdo;
  $s = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $s->execute([$key]);
  $r = $s->fetch();
  return $r ? $r['v'] : $default;
}

function setting_set($key, $value) {
  global $pdo;
  $s = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
  return $s->execute([$key, $value]);
}
