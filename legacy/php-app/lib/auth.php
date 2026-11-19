<?php
// lib/auth.php — tailored to your schema (users.password_hash)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/* Load caps & page overrides */
function load_user_caps(int $user_id): array {
  global $pdo;
  try { $s=$pdo->prepare("SELECT cap FROM user_caps WHERE user_id=? AND allowed=1"); $s->execute([$user_id]);
    return array_map(fn($r)=>$r['cap'],$s->fetchAll(PDO::FETCH_ASSOC)); } catch(Throwable $e){ return []; }
}
function load_page_overrides(int $user_id): array {
  global $pdo;
  try { $s=$pdo->prepare("SELECT page,allowed FROM user_page_access WHERE user_id=?"); $s->execute([$user_id]);
    $m=[]; foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){ $m[$r['page']]=(bool)$r['allowed']; } return $m; } catch(Throwable $e){ return []; }
}

/* Login */
function auth_login($user, $password = null): void {
  if (is_string($user) && $password !== null) {
    global $pdo;
    $username = trim($user); $passIn = trim((string)$password);
    $stmt = $pdo->prepare("SELECT id, username, password_hash, role, phone, email, photo_path FROM users WHERE username=? LIMIT 1");
    $stmt->execute([$username]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('User not found');
    if (empty($row['password_hash']) || !password_verify($passIn, $row['password_hash'])) {
      throw new Exception('Invalid password');
    }
    $_SESSION['user'] = [
      'id'         => (int)$row['id'],
      'username'   => $row['username'],
      'name'       => $row['username'],
      'role'       => $row['role'] ?? 'cashier',
      'perms'      => load_user_caps((int)$row['id']),
      'page_allow' => load_page_overrides((int)$row['id']),
      'phone'      => $row['phone'] ?? null,
      'email'      => $row['email'] ?? null,
      'photo'      => $row['photo_path'] ?? null,
    ];
    session_regenerate_id(true);
    return;
  }
  if (is_array($user)) {
    $uid = (int)($user['id'] ?? 0);
    $_SESSION['user'] = [
      'id'         => $uid,
      'username'   => $user['username'] ?? ($user['name'] ?? 'user'),
      'name'       => $user['name'] ?? ($user['username'] ?? 'User'),
      'role'       => $user['role'] ?? 'cashier',
      'perms'      => load_user_caps($uid),
      'page_allow' => load_page_overrides($uid),
      'phone'      => $user['phone'] ?? null,
      'email'      => $user['email'] ?? null,
      'photo'      => $user['photo_path'] ?? null,
    ];
    session_regenerate_id(true);
    return;
  }
  throw new Exception('Invalid login payload');
}

/* Logout */
function auth_logout(): void {
  $_SESSION=[]; if (ini_get('session.use_cookies')) { $p=session_get_cookie_params();
    setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); }
  @session_destroy();
}

/* User helpers */
if (!function_exists('current_user')) { function current_user(): ?array { return isset($_SESSION['user'])?$_SESSION['user']:null; } }
if (!function_exists('current_user_id')) { function current_user_id(): ?int { return $_SESSION['user']['id']??null; } }
if (!function_exists('is_admin')) { function is_admin(): bool { return (($_SESSION['user']['role']??'')==='admin'); } }

/* Access control */
function access_map(): array {
  return [
    'dashboard'=>['*'],'search'=>['*'],
    'sales'=>['admin','cashier','manager'],'receipt'=>['admin','cashier','manager'],'receipts'=>['admin','cashier','manager'],'sales_history'=>['admin','manager'],
    'products'=>['admin','manager'],'product_edit'=>['admin','manager'],
    'purchases'=>['admin','manager'],
    'credits'=>['admin','cashier','manager'],'creditors'=>['admin','manager'],
    'expenses'=>['admin','manager'],'reports'=>['admin','manager'],
    'balance'=>['admin','manager'],'balance_pdf'=>['admin','manager'],'receipt_pdf'=>['admin','cashier','manager'],
    'settings'=>['admin'],'users'=>['admin'],'account'=>['admin','cashier','manager'],'activity'=>['admin'],
    'login'=>['*'],'logout'=>['*'],
  ];
}
function can_access(string $page): bool {
  $role = $_SESSION['user']['role'] ?? 'guest';
  if ($role==='admin') return true;
  $allowed = access_map()[$page] ?? ['admin'];
  $roleOK = in_array('*',$allowed,true) || in_array($role,$allowed,true);
  $over = $_SESSION['user']['page_allow'] ?? [];
  if (array_key_exists($page,$over)) return (bool)$over[$page];
  return $roleOK;
}
function require_page_access(string $page): void {
  $role = $_SESSION['user']['role'] ?? 'guest';
  if ($role==='admin') {
    $over = $_SESSION['user']['page_allow'] ?? [];
    if (array_key_exists($page,$over) && !$over[$page]) { http_response_code(403); die('<div style="padding:1rem">Access denied.</div>'); }
    return;
  }
  $allowed = access_map()[$page] ?? ['admin'];
  $roleOK = in_array('*',$allowed,true) || in_array($role,$allowed,true);
  $over = $_SESSION['user']['page_allow'] ?? [];
  if (array_key_exists($page,$over)) $roleOK = (bool)$over[$page];
  if (!$roleOK) { http_response_code(403); die('<div style="padding:1rem">Access denied.</div>'); }
}
function require_login(): void { if (!current_user()) { safe_redirect(BASE_URL.'/?page=login'); } }
