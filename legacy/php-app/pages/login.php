<?php
// pages/login.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

$error = null;

// Already logged in? Go home
if (current_user()) {
  safe_redirect(BASE_URL . '/?page=dashboard');
}

// Pull first admin for popover (guard all fields to avoid warnings)
$admin = null;
try {
  $stmt = $pdo->query("SELECT username, name, phone, email, photo_path FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1");
  $row  = $stmt->fetch(PDO::FETCH_ASSOC);
  $admin = is_array($row) ? $row : [];
} catch (Throwable $e) { $admin = []; }

$adminName  = $admin['name'] ?? ($admin['username'] ?? 'Administrator');
$adminPhone = $admin['phone'] ?? '';
$adminEmail = $admin['email'] ?? '';
$adminPhoto = $admin['photo_path'] ?? '';
if ($adminPhoto && !preg_match('~^https?://|^/~', $adminPhoto)) {
  $adminPhoto = BASE_URL . '/../uploads/users/' . $adminPhoto;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (function_exists('csrf_check')) csrf_check();

    $username = trim($_POST['username'] ?? '');
    $password = trim((string)($_POST['password'] ?? ''));

    if ($username === '' || $password === '') {
      throw new Exception('Please enter both username and password.');
    }

    // Will throw on failure
    auth_login($username, $password);
    audit('auth.login', 'user', current_user_id(), null, 'login');

    $next = $_POST['next'] ?? ($_GET['next'] ?? '');
    if (!is_string($next) || $next === '' || stripos($next, '://') !== false) {
      $next = BASE_URL . '/?page=dashboard';
    } else {
      $next = BASE_URL . '/' . ltrim($next, '/');
    }
    safe_redirect($next);
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}
?>
<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">
    <div class="card shadow-sm mt-4">
      <div class="card-body p-4">
        <div class="text-center mb-3">
          <h5 class="mb-1">Sign in</h5>
          <div class="text-muted small">Enter your credentials to continue</div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="">
          <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
          <input type="hidden" name="next" value="<?= e($_GET['next'] ?? '') ?>">

          <div class="mb-3">
            <label class="form-label">Username</label>
            <input name="username" class="form-control" autocomplete="username" autofocus required>
          </div>

          <div class="mb-3">
            <label class="form-label d-flex justify-content-between align-items-center">
              <span>Password</span>
              <button class="btn btn-link btn-sm p-0" type="button" id="togglePw">Show</button>
            </label>
            <div class="input-group">
              <input name="password" type="password" class="form-control" autocomplete="current-password" required id="pwField">
              <span class="input-group-text" id="togglePwIcon" style="cursor:pointer;">👁️</span>
            </div>
          </div>

          <div class="d-grid">
            <button class="btn btn-primary">Sign in</button>
          </div>
        </form>

        <div class="text-center mt-3 small text-muted">
          <button
            type="button"
            class="btn btn-link p-0"
            data-bs-toggle="popover"
            data-bs-trigger="hover focus"
            data-bs-html="true"
            data-bs-title="Contact Administrator"
            data-bs-content="<?= e(
              '<div style=&quot;display:flex;gap:10px;align-items:center;&quot;>' .
              ($adminPhoto ? '<img src=&quot;'.$adminPhoto.'&quot; style=&quot;width:42px;height:42px;border-radius:50%;object-fit:cover;&quot;>' : '') .
              '<div><div><strong>'.e($adminName).'</strong></div>'.
              ($adminPhone ? '<div>📞 '.e($adminPhone).'</div>' : '') .
              ($adminEmail ? '<div>✉️ '.e($adminEmail).'</div>' : '') .
              '</div></div>'
            ) ?>">
            Forgot your password? Hover here
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap JS (needed for popover) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const pw = document.getElementById('pwField');
  const btn = document.getElementById('togglePw');
  const icon = document.getElementById('togglePwIcon');
  function toggle(){
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Hide' : 'Show';
    icon.textContent = show ? '🙈' : '👁️';
    pw.focus();
  }
  btn.addEventListener('click', toggle);
  icon.addEventListener('click', toggle);
})();
document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => new bootstrap.Popover(el));
</script>
