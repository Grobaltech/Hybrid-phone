<?php
// public/index.php
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

// Allowlist of pages
$allowed = [
  'login','dashboard','products','product_edit','purchases','sales','sales_history',
  'receipt','receipts','receipt_pdf', // ← added
  'credits','creditors','expenses','reports','settings','users','account','activity','search','logout',
  'balance','balance_pdf','suppliers',            // ← added
];

$page = $_GET['page'] ?? 'dashboard';
if (!in_array($page, $allowed, true)) {
  $page = 'dashboard';
}

/* EARLY: handle logout BEFORE any output */
if ($page === 'logout') {
  auth_logout();
  header('Location: ' . BASE_URL . '/?page=login');
  exit;
}

/* Auth gates */
if ($page !== 'login') {
  require_login();
  require_page_access($page);
}

/*
 | Some pages stream files (PDF). They MUST NOT include the site header/footer,
 | or you'll get "Cannot modify header information" errors.
*/
$streaming_pages = ['receipt_pdf', 'balance_pdf'];

if (in_array($page, $streaming_pages, true)) {
  // Directly include and exit — no layout.
  include __DIR__ . '/../pages/' . $page . '.php';
  exit;
}

/* Render normal pages with layout */
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../pages/' . $page . '.php';
include __DIR__ . '/../includes/footer.php';
