<?php
// pages/settings.php
require_once __DIR__ . '/../lib/auth.php';
require_login(); if (!is_admin()) { http_response_code(403); echo '<div class="alert alert-danger m-3">Admins only.</div>'; return; }
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/settings.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Save text settings
  setting_set('company_name', trim($_POST['company_name'] ?? ''));
  setting_set('company_address', trim($_POST['company_address'] ?? ''));
  setting_set('company_phone', trim($_POST['company_phone'] ?? ''));
  setting_set('company_email', trim($_POST['company_email'] ?? ''));

  // Handle logo upload (optional)
  if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['logo']['tmp_name']);
    finfo_close($finfo);

    $allowed = ['image/png','image/jpeg','image/webp'];
    if (!in_array($mime, $allowed)) {
      $msg = '<div class="alert alert-warning">Logo must be PNG, JPG/JPEG, or WEBP.</div>';
    } else {
      // Normalize to PNG path, but we’ll keep original mime; browsers don’t care about extension here
      $target = __DIR__ . '/../assets/logo.png';
      if (!move_uploaded_file($_FILES['logo']['tmp_name'], $target)) {
        $msg = '<div class="alert alert-danger">Failed to upload logo (write permission?).</div>';
      } else {
        $msg = '<div class="alert alert-success">Settings saved. Logo updated.</div>';
      }
    }
  } else {
    if ($msg === '') $msg = '<div class="alert alert-success">Settings saved.</div>';
  }
}

$company_name    = setting_get('company_name', APP_NAME);
$company_address = setting_get('company_address', '');
$company_phone   = setting_get('company_phone', '');
$company_email   = setting_get('company_email', '');

$logo_rel = file_exists(__DIR__ . '/../assets/logo.png') ? (BASE_URL . '/../assets/logo.png') : null;
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title">Business Settings</h5>
        <?= $msg ?>
        <form method="post" enctype="multipart/form-data" class="row g-3">
          <div class="col-12">
            <label class="form-label">Company / Shop Name</label>
            <input name="company_name" class="form-control" value="<?= e($company_name) ?>" required>
          </div>
          <div class="col-12">
            <label class="form-label">Address</label>
            <input name="company_address" class="form-control" value="<?= e($company_address) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input name="company_phone" class="form-control" value="<?= e($company_phone) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input name="company_email" type="email" class="form-control" value="<?= e($company_email) ?>">
          </div>

          <div class="col-12">
            <label class="form-label">Logo (PNG/JPG/WEBP)</label>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="form-control">
            <div class="form-text">Recommended height ~48px; transparent PNG looks best.</div>
          </div>

          <?php if ($logo_rel): ?>
          <div class="col-12">
            <label class="form-label d-block">Current Logo</label>
            <img src="<?= $logo_rel ?>" alt="Logo" class="brand-preview border p-2">
          </div>
          <?php endif; ?>

          <div class="col-12 d-grid">
            <button class="btn btn-primary">Save Settings</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title">Where the logo shows</h5>
        <ul class="mb-2">
          <li>Navbar (next to the app name)</li>
          <li>Receipt/Invoice (printable/PDF)</li>
        </ul>
        <div class="text-muted small">
          If your logo doesn’t show up after upload, clear your browser cache or hard refresh (Ctrl/Cmd + Shift + R).
        </div>
      </div>
    </div>
  </div>
</div>

<style>
.brand-preview{ max-height: 60px; border-radius: 8px; background:#fff; }
</style>
