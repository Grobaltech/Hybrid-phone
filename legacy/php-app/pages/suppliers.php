<?php
// pages/suppliers.php — CRUD for suppliers
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

require_login();
require_page_access('purchases'); // suppliers are part of purchasing flow

$errors = [];
$ok = null;

// Create / Update
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  if (function_exists('csrf_check')) csrf_check();

  // Delete
  if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    try {
      $pdo->prepare("DELETE FROM suppliers WHERE id=?")->execute([$id]);
      audit('supplier.delete', 'supplier', $id, null, 'suppliers');
      $ok = "Supplier deleted.";
    } catch (Throwable $e) {
      $errors[] = "Delete failed: ".$e->getMessage();
    }
  }

  if (isset($_POST['save_supplier'])) {
    $id    = (int)($_POST['id'] ?? 0);
    $name  = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($name === '') $errors[] = "Name is required.";

    if (!$errors) {
      try {
        if ($id > 0) {
          $pdo->prepare("UPDATE suppliers SET name=?, phone=?, email=? WHERE id=?")
              ->execute([$name, $phone, $email, $id]);
          audit('supplier.update','supplier',$id,'name='.$name,'suppliers');
          $ok = "Supplier updated.";
        } else {
          $pdo->prepare("INSERT INTO suppliers (name, phone, email) VALUES (?,?,?)")
              ->execute([$name, $phone, $email]);
          $id = (int)$pdo->lastInsertId();
          audit('supplier.create','supplier',$id,'name='.$name,'suppliers');
          $ok = "Supplier created.";
        }
      } catch (Throwable $e) {
        $errors[] = "Save failed: ".$e->getMessage();
      }
    }
  }
}

$suppliers = $pdo->query("SELECT id, name, phone, email, created_at FROM suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center">
      <h5 class="card-title mb-0">Suppliers</h5>
      <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#editModal" data-id="0">Add Supplier</button>
    </div>

    <?php foreach ($errors as $m): ?><div class="alert alert-danger mt-3"><?= e($m) ?></div><?php endforeach; ?>
    <?php if ($ok): ?><div class="alert alert-success mt-3"><?= e($ok) ?></div><?php endif; ?>

    <div class="table-responsive mt-3">
      <table class="table table-sm align-middle table-hover">
        <thead class="table-light">
          <tr>
            <th>#</th><th>Name</th><th>Phone</th><th>Email</th><th>Created</th><th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($suppliers as $s): ?>
          <tr>
            <td><?= (int)$s['id'] ?></td>
            <td><?= e($s['name']) ?></td>
            <td class="small"><?= e($s['phone'] ?: '—') ?></td>
            <td class="small"><?= e($s['email'] ?: '—') ?></td>
            <td class="small text-muted"><?= e($s['created_at']) ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-primary me-1" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?= (int)$s['id'] ?>">Edit</button>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this supplier?');">
                <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
                <input type="hidden" name="delete_id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-sm btn-danger">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; if (!$suppliers): ?>
          <tr><td colspan="6" class="text-center text-muted">No suppliers yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="id" id="s_id">
      <div class="modal-header">
        <h5 class="modal-title" id="editTitle">Add Supplier</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Name</label>
          <input class="form-control" name="name" id="s_name" required>
        </div>
        <div class="row">
          <div class="col-md-6 mb-2">
            <label class="form-label">Phone</label>
            <input class="form-control" name="phone" id="s_phone">
          </div>
          <div class="col-md-6 mb-2">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" id="s_email">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" name="save_supplier" value="1">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('editModal')?.addEventListener('show.bs.modal', (e)=>{
  const id = Number(e.relatedTarget?.getAttribute('data-id')||0);
  document.getElementById('s_id').value = id;
  document.getElementById('editTitle').textContent = id ? 'Edit Supplier' : 'Add Supplier';
  document.getElementById('s_name').value = '';
  document.getElementById('s_phone').value = '';
  document.getElementById('s_email').value = '';

  if (id) {
    // lightweight fetch by rendering it server-side would be fine too; to keep simple, we scan table row
    const tr = e.relatedTarget.closest('tr');
    if (tr) {
      document.getElementById('s_name').value  = tr.children[1].innerText.trim();
      document.getElementById('s_phone').value = tr.children[2].innerText.trim().replace('—','');
      document.getElementById('s_email').value = tr.children[3].innerText.trim().replace('—','');
    }
  }
});
</script>
