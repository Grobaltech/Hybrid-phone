<?php
// pages/activity.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

require_login();
if (!is_admin()) { http_response_code(403); echo '<div class="alert alert-danger m-3">Admins only.</div>'; return; }

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d');
$user = isset($_GET['user_id']) && $_GET['user_id']!=='' ? (int)$_GET['user_id'] : null;
$action = trim($_GET['action'] ?? '');

$where = 'DATE(created_at) BETWEEN ? AND ?';
$params = [$from, $to];
if ($user)   { $where .= ' AND user_id = ?'; $params[] = $user; }
if ($action) { $where .= ' AND action = ?';  $params[] = $action; }

$users = $pdo->query('SELECT id, username FROM users ORDER BY username')->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $pdo->prepare("
  SELECT id, user_id, username, action, entity_type, entity_id, message, page, ip, user_agent, created_at
  FROM audit_logs
  WHERE $where
  ORDER BY id DESC
  LIMIT 500
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h5 class="card-title">Activity Monitor</h5>
    <form class="row g-2">
      <div class="col-md-3">
        <label class="form-label">From</label>
        <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">To</label>
        <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">User</label>
        <select class="form-select" name="user_id">
          <option value="">All</option>
          <?php foreach ($users as $id=>$uname): ?>
            <option value="<?= (int)$id ?>" <?= $user===$id?'selected':'' ?>><?= e($uname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Action</label>
        <input class="form-control" name="action" placeholder="e.g. user.create" value="<?= e($action) ?>">
      </div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/?page=activity">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive" style="max-height:650px">
      <table class="table table-sm table-hover align-middle">
        <thead>
          <tr>
            <th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Message</th><th>Page</th><th>IP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="small text-muted"><?= e($r['created_at']) ?></td>
              <td><strong><?= e($r['username'] ?? '—') ?></strong></td>
              <td><span class="badge bg-light text-dark"><?= e($r['action']) ?></span></td>
              <td class="small"><?= e($r['entity_type'] ?? '') ?> <?= $r['entity_id'] ? '#'.(int)$r['entity_id'] : '' ?></td>
              <td class="small"><?= e($r['message'] ?? '') ?></td>
              <td class="small"><?= e($r['page'] ?? '') ?></td>
              <td class="small"><?= e($r['ip'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="text-center text-muted">No activity found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
