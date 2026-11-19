<?php
// pages/account.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

require_login();
$u = current_user();
$msg = '';
$uploadDir = __DIR__ . '/../uploads/users';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $newUser = trim($_POST['username'] ?? $u['username']);
  $newPass = $_POST['password'] ?? '';
  $phone   = trim($_POST['phone'] ?? '');
  $email   = trim($_POST['email'] ?? '');
  $nid     = trim($_POST['national_id'] ?? '');
  $about   = trim($_POST['about'] ?? '');

  if ($newUser !== $u['username']) {
    $pdo->prepare('UPDATE users SET username=? WHERE id=?')->execute([$newUser, $u['id']]);
    $_SESSION['user']['username'] = $newUser;
    $msg .= '<div class="alert alert-success">Username updated.</div>';
  }
  $pdo->prepare('UPDATE users SET phone=?, email=?, national_id=?, about=? WHERE id=?')
      ->execute([$phone,$email,$nid,$about,$u['id']]);
  if ($newPass !== '') {
    $hash = password_hash($newPass, PASSWORD_BCRYPT);
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash,$u['id']]);
    $msg .= '<div class="alert alert-success">Password updated.</div>';
  }

  // Photo
  if (!empty($_FILES['photo']['name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
    $safe = $u['id'] . '.' . strtolower($ext ?: 'jpg');
    $target = $uploadDir . '/' . $safe;
    if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
      $pdo->prepare('UPDATE users SET photo_path=? WHERE id=?')->execute(['uploads/users/'.$safe, $u['id']]);
      $msg .= '<div class="alert alert-success">Photo updated.</div>';
    }
  }
}
$me = $pdo->prepare('SELECT * FROM users WHERE id=?'); $me->execute([$u['id']]); $me = $me->fetch();
?>
<div class="card shadow-sm">
  <div class="card-body">
    <h5 class="card-title">My Account</h5>
    <?= $msg ?>
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Username</label>
        <input class="form-control" name="username" value="<?= e($me['username']) ?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">New Password (optional)</label>
        <input type="password" class="form-control" name="password" placeholder="leave blank to keep current">
      </div>
      <div class="col-md-6">
        <label class="form-label">Phone</label>
        <input class="form-control" name="phone" value="<?= e($me['phone']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Email</label>
        <input type="email" class="form-control" name="email" value="<?= e($me['email']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">National ID</label>
        <input class="form-control" name="national_id" value="<?= e($me['national_id']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Photo</label>
        <input type="file" name="photo" accept="image/*" class="form-control">
      </div>
      <div class="col-12">
        <label class="form-label">About</label>
        <textarea class="form-control" name="about" rows="3"><?= e($me['about']) ?></textarea>
      </div>
      <div class="col-12 d-grid"><button class="btn btn-primary">Save</button></div>
    </form>
  </div>
</div>
