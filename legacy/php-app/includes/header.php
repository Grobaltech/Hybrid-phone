<?php
// includes/header.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/auth.php';

// Safety fallbacks
if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
if (!function_exists('current_user')) { function current_user(){ return $_SESSION['user'] ?? null; } }
if (!function_exists('is_admin')) { function is_admin(){ return (($_SESSION['user']['role'] ?? '')==='admin'); } }
if (!function_exists('can_access')) {
  function can_access(string $page): bool {
    $role = $_SESSION['user']['role'] ?? 'guest';
    $map = [
      'dashboard'=>['*'],'search'=>['*'],
      'sales'=>['admin','cashier','manager'],'receipt'=>['admin','cashier','manager'],'receipts'=>['admin','cashier','manager'],'sales_history'=>['admin','manager'],
      'products'=>['admin','manager'],'product_edit'=>['admin','manager'],
      'purchases'=>['admin','manager'],
      'credits'=>['admin','cashier','manager'],'creditors'=>['admin','manager'],
      'expenses'=>['admin','manager'],
      'reports'=>['admin','manager'],'balance'=>['admin','manager'],
      'settings'=>['admin'],'users'=>['admin'],
      'account'=>['admin','cashier','manager'],'activity'=>['admin'],
      'login'=>['*'],'logout'=>['*'],
    ];
    $allowed = $map[$page] ?? ['admin'];
    return in_array('*',$allowed,true) || in_array($role,$allowed,true);
  }
}

$company_name = setting_get('company_name', APP_NAME);
$logo_rel = file_exists(__DIR__.'/../assets/logo.png') ? (BASE_URL . '/../assets/logo.png') : null;
$me = current_user();
$loggedIn = (bool)$me;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($company_name) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= BASE_URL ?>/../assets/style.css" rel="stylesheet">
</head>
<body class="layout">

<nav class="navbar navbar-expand-lg navbar-dark mb-4 sticky-top fancy-nav">
  <div class="container-fluid">
    <!-- Brand -->
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?= BASE_URL ?>/?page=dashboard">
      <?php if ($logo_rel): ?><img src="<?= $logo_rel ?>" alt="Logo" class="brand-logo"><?php endif; ?>
      <span><?= e($company_name) ?></span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse justify-content-between" id="nav">
      <!-- Center search (only when logged in) -->
      <?php if ($loggedIn): ?>
      <form class="d-none d-lg-flex mx-auto nav-search" role="search" onsubmit="return false;">
        <input class="form-control text-center" list="product-list" type="search" placeholder="Search products..." id="searchBox">
        <datalist id="product-list"></datalist>
      </form>
      <?php endif; ?>

      <!-- Right side -->
      <ul class="navbar-nav mb-2 mb-lg-0 ms-auto align-items-lg-center">
        <?php if ($loggedIn): ?>
          <!-- Logged-in menus -->
          <?php if (can_access('products') || can_access('purchases')): ?>
          <li class="nav-item dropdown dropdown-hover">
            <a class="nav-link dropdown-toggle dd-toggle" href="#" data-bs-toggle="dropdown">Products</a>
            <ul class="dropdown-menu glassy-dd dd-arrow">
              <?php if (can_access('products')): ?><li><a class="dropdown-item" href="<?= BASE_URL ?>/?page=products">All Products</a></li><?php endif; ?>
              <?php if (can_access('purchases')): ?><li><a class="dropdown-item" href="<?= BASE_URL ?>/?page=purchases">Purchases</a></li><?php endif; ?>
            </ul>
          </li>
          <?php endif; ?>

          <?php if (can_access('sales') || can_access('sales_history') || can_access('reports') || can_access('balance')): ?>
          <li class="nav-item dropdown dropdown-hover">
            <a class="nav-link dropdown-toggle dd-toggle" href="#" data-bs-toggle="dropdown">Sales</a>
            <ul class="dropdown-menu glassy-dd dd-arrow">
              <?php if (can_access('sales')): ?><li><a class="dropdown-item" href="<?= BASE_URL ?>/?page=sales">New Sale</a></li><?php endif; ?>
              <?php if (can_access('sales_history')): ?><li><a class="dropdown-item" href="<?= BASE_URL ?>/?page=sales_history">History</a></li><?php endif; ?>
              <?php if (can_access('reports')): ?><li><a class="dropdown-item" href="<?= BASE_URL ?>/?page=reports">Reports</a></li><?php endif; ?>
              <?php if (can_access('balance')): ?><li><a class="dropdown-item" href="<?= BASE_URL ?>/?page=balance">Balance Sheet</a></li><?php endif; ?>
            </ul>
          </li>
          <?php endif; ?>

          <?php if (can_access('credits') || can_access('creditors')): ?>
          <li class="nav-item dropdown dropdown-hover">
            <a class="nav-link dropdown-toggle dd-toggle" href="#" data-bs-toggle="dropdown">Credit</a>
            <ul class="dropdown-menu glassy-dd dd-arrow">
              <?php if (can_access('credits')): ?><li><a class="dropdown-item" href="<?= BASE_URL ?>/?page=credits">Credits</a></li><?php endif; ?>
              <?php if (can_access('creditors')): ?><li><a class="dropdown-item" href="<?= BASE_URL ?>/?page=creditors">Creditors</a></li><?php endif; ?>
            </ul>
          </li>
          <?php endif; ?>

          <?php if (can_access('expenses')): ?>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/?page=expenses">Expenses</a></li>
          <?php endif; ?>

          <!-- User dropdown -->
          <li class="nav-item dropdown dropdown-hover ms-lg-2">
            <a class="nav-link dropdown-toggle dd-user d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown" title="Account">
              <span class="user-badge"><?= strtoupper(substr(($me['username'] ?? $me['name'] ?? 'U'),0,1)) ?></span>
              <span class="d-none d-lg-inline small"><?= e($me['username'] ?? $me['name'] ?? 'User') ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end glassy-dd dd-arrow">
              <li class="dropdown-header small text-muted px-3">Role: <?= e($me['role'] ?? 'user') ?></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/?page=account">My Account</a></li>
              <?php if (is_admin() && can_access('users')): ?><li><a class="dropdown-item" href="<?= BASE_URL ?>/?page=users">Users</a></li><?php endif; ?>
              <?php if (is_admin() && can_access('settings')): ?><li><a class="dropdown-item" href="<?= BASE_URL ?>/?page=settings">Settings</a></li><?php endif; ?>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/?page=logout">Logout</a></li>
            </ul>
          </li>
        <?php else: ?>
          <!-- Logged-out: show only a Login button with user icon -->
          <li class="nav-item">
            <a class="btn btn-outline-light btn-sm" href="<?= BASE_URL ?>/?page=login" title="Login">
              <span class="me-1">👤</span> Login
            </a>
          </li>
        <?php endif; ?>

        <li class="nav-item">
          <button class="btn btn-sm btn-outline-primary ms-2" id="themeToggle" type="button" title="Toggle theme">🌙</button>
        </li>
      </ul>

      <!-- Mobile search shown only when logged in -->
      <?php if ($loggedIn): ?>
      <form class="d-lg-none mt-3" role="search" onsubmit="return false;">
        <input class="form-control text-center" list="product-list" type="search" placeholder="Search products..." id="searchBoxMobile">
      </form>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div class="container page-container">
