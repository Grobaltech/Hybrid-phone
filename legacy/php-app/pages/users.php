<?php
// pages/users.php — CRUD + Caps + Pages + Activity + Photo cropping, ID (upload/capture), CV upload, NIN
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

require_login();
require_page_access('users');

$errors = [];
$ok = null;

/* -------------------- Utilities -------------------- */
function fetch_user(int $id): ?array { global $pdo; $s=$pdo->prepare("SELECT * FROM users WHERE id=?"); $s->execute([$id]); $r=$s->fetch(PDO::FETCH_ASSOC); return $r?:null; }
function count_admins(): int { global $pdo; return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn(); }
function ensure_dir(string $path){ if(!is_dir($path)) @mkdir($path,0775,true); }
function col_exists(string $table, string $col): bool {
  global $pdo; try { $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch(); } catch(Throwable $e){ return false; }
}
function save_data_url(string $dataUrl, string $destAbs): bool {
  if (!preg_match('#^data:image/(png|jpe?g);base64,#i',$dataUrl,$m)) return false;
  $data = base64_decode(substr($dataUrl, strpos($dataUrl, ',')+1));
  if ($data===false) return false;
  return (bool)file_put_contents($destAbs,$data);
}
function sanitize_filename(string $name): string {
  $name = preg_replace('/[^A-Za-z0-9._-]+/','_',$name);
  return trim($name,'_');
}

/* -------------------- Auto-migrate optional columns -------------------- */
try {
  if (!col_exists('users','id_photo_path')) { $pdo->exec("ALTER TABLE users ADD COLUMN id_photo_path VARCHAR(255) NULL AFTER photo_path"); }
} catch (Throwable $e) { /* ignore */ }
try {
  if (!col_exists('users','cv_path')) { $pdo->exec("ALTER TABLE users ADD COLUMN cv_path VARCHAR(255) NULL AFTER id_photo_path"); }
} catch (Throwable $e) { /* ignore */ }
try {
  if (!col_exists('users','national_id')) { $pdo->exec("ALTER TABLE users ADD COLUMN national_id VARCHAR(128) NULL"); }
} catch (Throwable $e) { /* ignore */ }

/* -------------------- AJAX (get/caps/pages/activity) -------------------- */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json');
  $id = (int)($_GET['id'] ?? 0);

  if ($_GET['ajax'] === 'get') {
    echo json_encode(['user'=>fetch_user($id)]); exit;
  }
  if ($_GET['ajax'] === 'caps') {
    $st = $pdo->prepare("SELECT cap FROM user_caps WHERE user_id=? AND allowed=1"); $st->execute([$id]);
    echo json_encode(['caps'=>array_map(fn($r)=>$r['cap'],$st->fetchAll(PDO::FETCH_ASSOC))]); exit;
  }
  if ($_GET['ajax'] === 'pages') {
    // access_map() from auth.php
    $st = $pdo->prepare("SELECT page, allowed FROM user_page_access WHERE user_id=?"); $st->execute([$id]);
    $out=[]; foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $out[$r['page']] = (bool)$r['allowed']; }
    echo json_encode(['pages'=>$out]); exit;
  }
  if ($_GET['ajax'] === 'activity') {
    $st = $pdo->prepare("SELECT action, entity_type, entity_id, page, ip, user_agent, created_at FROM audit_logs WHERE user_id=? ORDER BY id DESC LIMIT 50");
    $st->execute([$id]);
    $rows = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $r['device'] = ua_device_label($r['user_agent'] ?? '');
      $rows[] = $r;
    }
    echo json_encode(['logs'=>$rows]); exit;
  }
}

/* -------------------- POST handlers -------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  if (function_exists('csrf_check')) csrf_check();

  /* Delete */
  if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    if ($id === (int)current_user_id()) { $errors[]="You cannot delete your own account."; }
    else {
      $u = fetch_user($id);
      if (!$u) $errors[]="User not found.";
      else if ($u['role']==='admin' && count_admins()<=1) $errors[]="You cannot delete the last admin.";
      else {
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM user_caps WHERE user_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM user_page_access WHERE user_id=?")->execute([$id]);
        audit('user.delete','user',$id,'username='.$u['username'],'users');
        $ok="User deleted.";
      }
    }
  }

  /* Save user (create/update) with files */
  if (isset($_POST['save_user'])) {
    $id=(int)($_POST['id']??0);
    $username=trim($_POST['username']??'');
    $role=$_POST['role']??'cashier';
    $phone=trim($_POST['phone']??'');
    $email=trim($_POST['email']??'');
    $nid=trim($_POST['national_id']??'');
    $about=trim($_POST['about']??'');
    $pass=(string)($_POST['password']??'');

    if ($username==='') $errors[]="Username is required.";
    if (!in_array($role,['admin','manager','cashier'],true)) $errors[]="Invalid role.";

    // Ensure upload dirs
    $root = realpath(__DIR__ . '/..'); // pages/.. -> project root
    $uDir = $root . '/uploads/users';
    $idDir= $root . '/uploads/users/id';
    $cvDir= $root . '/uploads/users/cv';
    ensure_dir($uDir); ensure_dir($idDir); ensure_dir($cvDir);

    // Prepare resulting paths (relative, stored in DB)
    $photo_path = null; $id_photo_path = null; $cv_path = null;

    // Photo: prefer cropped data URL
    if (!empty($_POST['photo_cropped'])) {
      $fname = sanitize_filename(($username?:'user').'_photo_'.date('Ymd_His')).'.jpg';
      $dest  = $uDir . '/' . $fname;
      if (save_data_url($_POST['photo_cropped'], $dest)) {
        $photo_path = 'uploads/users/'.$fname;
      } else { $errors[] = "Failed to save cropped photo."; }
    } elseif (!empty($_FILES['photo_file']['name'])) {
      if ($_FILES['photo_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['photo_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext,['jpg','jpeg','png'])) $errors[]="Photo must be JPG/PNG.";
        else {
          $fname = sanitize_filename(($username?:'user').'_photo_'.date('Ymd_His')).'.'.$ext;
          $dest  = $uDir . '/' . $fname;
          if (move_uploaded_file($_FILES['photo_file']['tmp_name'], $dest)) {
            $photo_path = 'uploads/users/'.$fname;
          } else { $errors[]="Photo upload failed."; }
        }
      } else { $errors[]="Photo upload error."; }
    }

    // ID photo: from capture data or file
    if (!empty($_POST['id_photo_data'])) {
      $fname = sanitize_filename(($username?:'user').'_id_'.date('Ymd_His')).'.png';
      $dest  = $idDir . '/' . $fname;
      if (save_data_url($_POST['id_photo_data'], $dest)) {
        $id_photo_path = 'uploads/users/id/'.$fname;
      } else { $errors[] = "Failed to save captured ID photo."; }
    } elseif (!empty($_FILES['id_photo_file']['name'])) {
      if ($_FILES['id_photo_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['id_photo_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext,['jpg','jpeg','png'])) $errors[]="ID photo must be JPG/PNG.";
        else {
          $fname = sanitize_filename(($username?:'user').'_id_'.date('Ymd_His')).'.'.$ext;
          $dest  = $idDir . '/' . $fname;
          if (move_uploaded_file($_FILES['id_photo_file']['tmp_name'], $dest)) {
            $id_photo_path = 'uploads/users/id/'.$fname;
          } else { $errors[]="ID upload failed."; }
        }
      } else if ($_FILES['id_photo_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors[]="ID upload error.";
      }
    }

    // CV upload
    if (!empty($_FILES['cv_file']['name'])) {
      if ($_FILES['cv_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['cv_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext,['pdf','doc','docx'])) $errors[]="CV must be PDF/DOC/DOCX.";
        else {
          $fname = sanitize_filename(($username?:'user').'_cv_'.date('Ymd_His')).'.'.$ext;
          $dest  = $cvDir . '/' . $fname;
          if (move_uploaded_file($_FILES['cv_file']['tmp_name'], $dest)) {
            $cv_path = 'uploads/users/cv/'.$fname;
          } else { $errors[]="CV upload failed."; }
        }
      } else if ($_FILES['cv_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors[]="CV upload error.";
      }
    }

    if (!$errors) {
      if ($id>0) {
        $u=fetch_user($id); if(!$u){$errors[]="User not found.";}
        else if ($u['role']==='admin' && $role!=='admin' && count_admins()<=1){$errors[]="You cannot remove the last admin role.";}
        else {
          // Build dynamic update
          $fields = "username=?, role=?, phone=?, email=?, national_id=?, about=?";
          $args   = [$username,$role,$phone,$email,$nid,$about];

          if ($photo_path)   { $fields .= ", photo_path=?";   $args[]=$photo_path; }
          if (col_exists('users','id_photo_path') && $id_photo_path) { $fields .= ", id_photo_path=?"; $args[]=$id_photo_path; }
          if (col_exists('users','cv_path') && $cv_path) { $fields .= ", cv_path=?"; $args[]=$cv_path; }
          if ($pass!=='') { $fields .= ", password_hash=?"; $args[] = password_hash($pass,PASSWORD_DEFAULT); }

          $args[]=$id;
          $pdo->prepare("UPDATE users SET $fields WHERE id=?")->execute($args);
          audit('user.update','user',$id,'username='.$username,'users'); $ok="User updated.";
        }
      } else {
        if ($pass==='') $errors[]="Password is required for a new user.";
        else {
          // Insert base
          $pdo->prepare("INSERT INTO users (username,password_hash,role,phone,email,national_id,about,photo_path,id_photo_path,cv_path) 
            VALUES (?,?,?,?,?,?,?,?,?,?)")
              ->execute([
                $username, password_hash($pass,PASSWORD_DEFAULT),
                $role, $phone, $email, $nid, $about,
                $photo_path, (col_exists('users','id_photo_path')?$id_photo_path:null),
                (col_exists('users','cv_path')?$cv_path:null)
              ]);
          $newId=(int)$pdo->lastInsertId();
          audit('user.create','user',$newId,'username='.$username,'users'); $ok="User created.";
        }
      }
    }
  }

  /* Capabilities */
  if (isset($_POST['save_caps'])) {
    $id=(int)$_POST['uid']; $caps=$_POST['caps']??[];
    $pdo->prepare("DELETE FROM user_caps WHERE user_id=?")->execute([$id]);
    foreach($caps as $cap=>$v){ if($v){ $pdo->prepare("INSERT INTO user_caps (user_id,cap,allowed) VALUES (?,?,1)")->execute([$id,$cap]); } }
    audit('user.caps','user',$id,json_encode(array_keys($caps)),'users'); $ok="Capabilities saved.";
  }

  /* Pages access */
  if (isset($_POST['save_pages'])) {
    $id=(int)$_POST['uid']; $pages=$_POST['pages']??[];
    $pdo->prepare("DELETE FROM user_page_access WHERE user_id=?")->execute([$id]);
    foreach($pages as $pg=>$v){ $pdo->prepare("INSERT INTO user_page_access (user_id,page,allowed) VALUES (?,?,?)")->execute([$id,$pg,$v?1:0]); }
    audit('user.pages','user',$id,json_encode(array_keys($pages)),'users'); $ok="Page access saved.";
  }
}

/* -------------------- Data for view -------------------- */
$users = $pdo->query("SELECT id, username, role, phone, email, national_id, photo_path, id_photo_path, cv_path, created_at FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$allPages = array_keys(access_map()); unset($allPages[array_search('login',$allPages)], $allPages[array_search('logout',$allPages)]); $allPages=array_values($allPages);
$allCaps = ['view_costs'];
?>
<div class="row">
  <div class="col-lg-7">
    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <h5 class="card-title">Users</h5>
        <?php foreach ($errors as $m): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endforeach; ?>
        <?php if ($ok): ?><div class="alert alert-success"><?= e($ok) ?></div><?php endif; ?>

        <div class="table-responsive">
          <table class="table table-sm align-middle table-hover">
            <thead class="table-light">
              <tr>
                <th>#</th><th>Username</th><th>Role</th><th>Phone</th><th>Email</th><th>NIN</th><th>Photo</th><th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
              <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= e($u['username']) ?></td>
                <td><span class="badge text-bg-secondary"><?= e($u['role']) ?></span></td>
                <td class="small"><?= e($u['phone'] ?? '—') ?></td>
                <td class="small"><?= e($u['email'] ?? '—') ?></td>
                <td class="small"><?= e($u['national_id'] ?? '—') ?></td>
                <td><?php if (!empty($u['photo_path'])): ?><img src="<?= BASE_URL.'/../'.e($u['photo_path']) ?>" style="height:28px;width:28px;object-fit:cover;border-radius:50%"><?php else: ?>—<?php endif; ?></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-primary me-1" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?= (int)$u['id'] ?>">Edit</button>
                  <button class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="modal" data-bs-target="#capsModal" data-id="<?= (int)$u['id'] ?>">Caps</button>
                  <button class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="modal" data-bs-target="#pagesModal" data-id="<?= (int)$u['id'] ?>">Pages</button>
                  <button class="btn btn-sm btn-info me-1" data-bs-toggle="modal" data-bs-target="#activityModal" data-id="<?= (int)$u['id'] ?>">Activity</button>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this user?');">
                    <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
                    <input type="hidden" name="delete_id" value="<?= (int)$u['id'] ?>">
                    <button class="btn btn-sm btn-danger">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; if (!$users): ?>
                <tr><td colspan="8" class="text-center text-muted">No users yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#editModal" data-id="0">Add User</button>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-2">Tips</h6>
        <ul class="small mb-0">
          <li>Crop profile photos before saving.</li>
          <li>Capture ID from webcam or upload a file.</li>
          <li>Accepted CV: PDF/DOC/DOCX.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- Edit/Add Modal with Photo Crop, ID (upload/capture), NIN, CV -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-lg">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="id" id="u_id">
      <input type="hidden" name="photo_cropped" id="photo_cropped">
      <input type="hidden" name="id_photo_data" id="id_photo_data">
      <div class="modal-header">
        <h5 class="modal-title" id="editTitle">Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Basic details -->
        <div class="row">
          <div class="col-md-6">
            <div class="mb-2"><label class="form-label">Username</label><input class="form-control" name="username" id="u_username" required></div>
          </div>
          <div class="col-md-3">
            <div class="mb-2">
              <label class="form-label">Role</label>
              <select class="form-select" name="role" id="u_role" required>
                <option value="admin">Admin</option><option value="manager">Manager</option><option value="cashier">Cashier</option>
              </select>
            </div>
          </div>
          <div class="col-md-3">
            <div class="mb-2"><label class="form-label">Password <small class="text-muted">(blank = keep)</small></label><input type="password" class="form-control" name="password" id="u_password"></div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-4 mb-2"><label class="form-label">Phone</label><input class="form-control" name="phone" id="u_phone"></div>
          <div class="col-md-4 mb-2"><label class="form-label">Email</label><input type="email" class="form-control" name="email" id="u_email"></div>
          <div class="col-md-4 mb-2"><label class="form-label">NIN</label><input class="form-control" name="national_id" id="u_nid" placeholder="National ID number"></div>
        </div>

        <div class="mb-2"><label class="form-label">About</label><textarea class="form-control" rows="2" name="about" id="u_about"></textarea></div>

        <hr>

        <!-- Profile Photo: upload + crop -->
        <div class="row g-3">
          <div class="col-lg-6">
            <label class="form-label">Profile Photo (JPG/PNG)</label>
            <input type="file" class="form-control" name="photo_file" id="photo_file" accept="image/*">
            <div class="mt-2">
              <img id="photo_preview" style="max-width:100%; display:none; border:1px solid #ddd; border-radius:6px">
            </div>
            <div class="form-text">Use the cropper to adjust; click <em>Save</em> to keep the cropped image.</div>
          </div>

          <!-- ID Upload / Capture -->
          <div class="col-lg-6">
            <label class="form-label">ID Photo (upload or capture)</label>
            <input type="file" class="form-control mb-2" name="id_photo_file" id="id_photo_file" accept="image/*">
            <div class="border rounded p-2">
              <div class="mb-1">Capture from camera</div>
              <video id="cam" autoplay playsinline style="width:100%; max-height:200px; background:#000;"></video>
              <div class="d-flex justify-content-end mt-2">
                <button class="btn btn-sm btn-outline-secondary me-2" type="button" id="camStart">Start</button>
                <button class="btn btn-sm btn-primary" type="button" id="camSnap">Capture</button>
              </div>
              <canvas id="snap" style="display:none;"></canvas>
              <div id="snapPreview" class="mt-2"></div>
            </div>
          </div>
        </div>

        <hr>

        <!-- CV upload -->
        <div class="row g-3">
          <div class="col-lg-6">
            <label class="form-label">CV (PDF/DOC/DOCX)</label>
            <input type="file" class="form-control" name="cv_file" id="cv_file" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" name="save_user" value="1" onclick="return preparePhotoCrop()">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Caps Modal -->
<div class="modal fade" id="capsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="uid" id="c_uid">
      <div class="modal-header"><h5 class="modal-title">Capabilities</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <?php foreach ($allCaps as $cap): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="caps[<?= e($cap) ?>]" id="cap_<?= e($cap) ?>" value="1">
            <label class="form-check-label" for="cap_<?= e($cap) ?>"><?= e($cap) ?></label>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer"><button class="btn btn-primary" name="save_caps" value="1">Save</button></div>
    </form>
  </div>
</div>

<!-- Pages Modal -->
<div class="modal fade" id="pagesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="uid" id="p_uid">
      <div class="modal-header"><h5 class="modal-title">Page Access</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row">
          <?php foreach ($allPages as $pg): ?>
          <div class="col-md-4"><div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="pages[<?= e($pg) ?>]" id="page_<?= e($pg) ?>" value="1">
            <label class="form-check-label" for="page_<?= e($pg) ?>"><?= e($pg) ?></label>
          </div></div>
          <?php endforeach; ?>
        </div>
        <div class="small text-muted">Checked = allow. Unchecked = no override.</div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary" name="save_pages" value="1">Save</button></div>
    </form>
  </div>
</div>

<!-- Activity Modal -->
<div class="modal fade" id="activityModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Recent Activity</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light"><tr><th>When</th><th>Action</th><th>Entity</th><th>Page</th><th>IP</th><th>Device</th></tr></thead>
            <tbody id="actBody"><tr><td colspan="6" class="text-center text-muted">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- Cropper.js (CDN) and page scripts -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
<script>
(function(){
  // Populate edit form
  document.getElementById('editModal')?.addEventListener('show.bs.modal', async (e)=>{
    const id=Number(e.relatedTarget?.getAttribute('data-id')||0);
    document.getElementById('u_id').value=id;
    document.getElementById('editTitle').textContent=id?'Edit User':'Add User';
    ['u_username','u_role','u_password','u_phone','u_email','u_nid','u_about'].forEach(i=>{const el=document.getElementById(i); if(el){ el.value=''; }});
    // reset files, previews, hidden fields
    document.getElementById('photo_file').value='';
    document.getElementById('photo_preview').style.display='none';
    document.getElementById('photo_cropped').value='';
    document.getElementById('id_photo_file').value='';
    document.getElementById('id_photo_data').value='';
    document.getElementById('snapPreview').innerHTML='';

    if(id){
      const res=await fetch('<?= BASE_URL ?>/?page=users&ajax=get&id='+id); const d=await res.json(); const u=d.user||{};
      document.getElementById('u_username').value=u.username||'';
      document.getElementById('u_role').value=u.role||'cashier';
      document.getElementById('u_phone').value=u.phone||'';
      document.getElementById('u_email').value=u.email||'';
      document.getElementById('u_nid').value=u.national_id||'';
      document.getElementById('u_about').value=u.about||'';
    }
  });

  // Caps
  document.getElementById('capsModal')?.addEventListener('show.bs.modal', async (e)=>{
    const id=Number(e.relatedTarget?.getAttribute('data-id')||0); document.getElementById('c_uid').value=id;
    document.querySelectorAll('#capsModal input[type=checkbox]').forEach(c=>c.checked=false);
    const d=await (await fetch('<?= BASE_URL ?>/?page=users&ajax=caps&id='+id)).json();
    (d.caps||[]).forEach(cap=>{const el=document.getElementById('cap_'+cap); if(el) el.checked=true;});
  });

  // Pages
  document.getElementById('pagesModal')?.addEventListener('show.bs.modal', async (e)=>{
    const id=Number(e.relatedTarget?.getAttribute('data-id')||0); document.getElementById('p_uid').value=id;
    document.querySelectorAll('#pagesModal input[type=checkbox]').forEach(c=>c.checked=false);
    const d=await (await fetch('<?= BASE_URL ?>/?page=users&ajax=pages&id='+id)).json(); const map=d.pages||{};
    Object.keys(map).forEach(pg=>{ if(map[pg]){ const el=document.getElementById('page_'+pg); if(el) el.checked=true; } });
  });

  // Activity
  document.getElementById('activityModal')?.addEventListener('show.bs.modal', async (e)=>{
    const id=Number(e.relatedTarget?.getAttribute('data-id')||0);
    const tbody=document.getElementById('actBody'); tbody.innerHTML='<tr><td colspan="6" class="text-center text-muted">Loading…</td></tr>';
    const d=await (await fetch('<?= BASE_URL ?>/?page=users&ajax=activity&id='+id)).json();
    const logs=d.logs||[];
    if(!logs.length){ tbody.innerHTML='<tr><td colspan="6" class="text-center text-muted">No activity.</td></tr>'; return; }
    tbody.innerHTML='';
    logs.forEach(l=>{
      const tr=document.createElement('tr');
      tr.innerHTML=`<td class="small text-muted">${l.created_at||''}</td>
        <td>${l.action||''}</td>
        <td>${(l.entity_type||'')}${l.entity_id?('#'+l.entity_id):''}</td>
        <td class="small">${l.page||''}</td>
        <td class="small">${l.ip||''}</td>
        <td class="small">${l.device||'Unknown'}</td>`;
      tbody.appendChild(tr);
    });
  });

  // Photo cropper
  let cropper=null;
  const photoFile=document.getElementById('photo_file');
  const photoPrev=document.getElementById('photo_preview');
  photoFile.addEventListener('change', (e)=>{
    const f=e.target.files?.[0]; if(!f) return;
    const url=URL.createObjectURL(f);
    photoPrev.src=url; photoPrev.style.display='block';
    if (cropper) cropper.destroy();
    cropper=new Cropper(photoPrev, {
      viewMode:1, aspectRatio:1, autoCropArea:1, movable:false, zoomable:true, rotatable:false, scalable:false
    });
  });
  window.preparePhotoCrop = function(){
    if (cropper){
      const canvas=cropper.getCroppedCanvas({width:512,height:512});
      document.getElementById('photo_cropped').value = canvas.toDataURL('image/jpeg',0.9);
    }
    return true; // allow submit
  };

  // Camera capture for ID
  const cam=document.getElementById('cam');
  const snap=document.getElementById('snap');
  document.getElementById('camStart').addEventListener('click', async ()=>{
    try {
      const stream = await navigator.mediaDevices.getUserMedia({video:true,audio:false});
      cam.srcObject=stream;
    } catch(e){ alert('Camera not available: '+e.message); }
  });
  document.getElementById('camSnap').addEventListener('click', ()=>{
    if (!cam.srcObject){ alert('Start the camera first.'); return; }
    const r=cam.getBoundingClientRect();
    snap.width = Math.round(r.width);
    snap.height= Math.round(r.height);
    const ctx=snap.getContext('2d'); ctx.drawImage(cam,0,0,snap.width,snap.height);
    const data = snap.toDataURL('image/png');
    document.getElementById('id_photo_data').value=data;
    document.getElementById('snapPreview').innerHTML = '<img src="'+data+'" style="max-width:100%; border:1px solid #ddd; border-radius:6px">';
  });
})();
</script>
