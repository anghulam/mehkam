<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('multi_branch','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'multi_branch')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'إدارة الفروع المتعددة';
$_canAdd = can('multi_branch','add'); $_canEdit = can('multi_branch','edit'); $_canDel = can('multi_branch','delete');

$conn->query("CREATE TABLE IF NOT EXISTS office_branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    city VARCHAR(100) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    manager_user_id INT DEFAULT NULL,
    is_main TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

try { $conn->query("ALTER TABLE users ADD COLUMN branch_id INT DEFAULT NULL"); } catch (\Throwable $e) {}

/* ── حذف فرع ── */
if (isset($_GET['delete']) && $_canDel) {
    $bid = (int)$_GET['delete'];
    $conn->query("UPDATE users SET branch_id=NULL WHERE branch_id=$bid AND office_id=$oid");
    $conn->query("DELETE FROM office_branches WHERE id=$bid AND office_id=$oid");
    header("Location: multi_branch.php?msg=deleted"); exit;
}

/* ── حفظ/تعديل فرع ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'branch') {
    $bid = (int)($_POST['id'] ?? 0);
    requirePerm('multi_branch', $bid ? 'edit' : 'add', 'multi_branch.php?msg=denied');
    $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
    $city = $conn->real_escape_string(trim($_POST['city'] ?? ''));
    $addr = $conn->real_escape_string(trim($_POST['address'] ?? ''));
    $phone = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
    $mgr = !empty($_POST['manager_user_id']) ? (int)$_POST['manager_user_id'] : 'NULL';
    $isMain = isset($_POST['is_main']) ? 1 : 0;
    if ($isMain) $conn->query("UPDATE office_branches SET is_main=0 WHERE office_id=$oid");
    if ($bid) {
        $conn->query("UPDATE office_branches SET name='$name',city='$city',address='$addr',phone='$phone',manager_user_id=$mgr,is_main=$isMain WHERE id=$bid AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO office_branches (office_id,name,city,address,phone,manager_user_id,is_main) VALUES ($oid,'$name','$city','$addr','$phone',$mgr,$isMain)");
    }
    header("Location: multi_branch.php?msg=saved"); exit;
}

/* ── إسناد موظف لفرع ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'assign' && $_canEdit) {
    $target_uid = (int)($_POST['user_id'] ?? 0);
    $bid = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : 'NULL';
    $conn->query("UPDATE users SET branch_id=$bid WHERE id=$target_uid AND office_id=$oid");
    header("Location: multi_branch.php?tab=staff&msg=saved"); exit;
}

/* ── بيانات ── */
$branches = [];
$br = $conn->query("SELECT b.*, u.full_name manager_name, (SELECT COUNT(*) FROM users WHERE branch_id=b.id) staff_count
    FROM office_branches b LEFT JOIN users u ON b.manager_user_id=u.id WHERE b.office_id=$oid ORDER BY b.is_main DESC, b.id");
if ($br) while ($r = $br->fetch_assoc()) $branches[] = $r;

$officeUsers = [];
$our = $conn->query("SELECT id, full_name, branch_id, role FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
$staff = [];
if ($our) while ($r = $our->fetch_assoc()) { $officeUsers[$r['id']] = $r['full_name']; $staff[] = $r; }

$edit = null;
if (isset($_GET['edit'])) $edit = $conn->query("SELECT * FROM office_branches WHERE id=".(int)$_GET['edit']." AND office_id=$oid")->fetch_assoc();

$_roleLbl = ['office_owner'=>'مالك المكتب','lawyer'=>'محامي','secretary'=>'سكرتير','trainee'=>'متدرّب'];
$tab = in_array($_GET['tab'] ?? '', ['staff']) ? 'staff' : 'branches';

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-code-branch"></i> إدارة الفروع المتعددة</div>
<div class="mk-page-sub mb-3">نظّم فروع مكتبك وأسند كل موظف لفرعه</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='branches'?'active':'' ?>" href="multi_branch.php?tab=branches">الفروع (<?= count($branches) ?>)</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='staff'?'active':'' ?>" href="multi_branch.php?tab=staff">إسناد الموظفين</a></li>
</ul>

<?php if ($tab === 'branches'): ?>
<div class="d-flex justify-content-end mb-3">
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#branchModal" onclick="brNew()"><i class="fas fa-plus me-1"></i>فرع جديد</button>
  <?php endif; ?>
</div>
<div class="row g-3">
<?php if (!$branches): ?>
<div class="col-12"><div class="text-muted text-center py-5">لا توجد فروع مسجّلة بعد — المكتب كله يُعامَل كفرع واحد افتراضياً</div></div>
<?php else: foreach ($branches as $b): ?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div class="fw-bold"><?= e($b['name']) ?> <?php if ($b['is_main']): ?><span class="badge bg-primary" style="font-size:10px">الرئيسي</span><?php endif; ?></div>
        <div class="d-flex gap-1">
          <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='brEdit(<?= json_encode($b, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
          <?php if ($_canDel): ?><a href="multi_branch.php?delete=<?= $b['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا الفرع؟ الموظفون المُسندون له يصبحون بدون فرع.')"><i class="fas fa-trash"></i></a><?php endif; ?>
        </div>
      </div>
      <div class="text-muted" style="font-size:12px">
        <?php if ($b['city']): ?><div><i class="fas fa-location-dot me-1"></i><?= e($b['city']) ?></div><?php endif; ?>
        <?php if ($b['phone']): ?><div><i class="fas fa-phone me-1"></i><?= e($b['phone']) ?></div><?php endif; ?>
        <?php if ($b['manager_name']): ?><div><i class="fas fa-user-tie me-1"></i>مدير الفرع: <?= e($b['manager_name']) ?></div><?php endif; ?>
      </div>
      <div class="mt-2"><span class="badge bg-info bg-opacity-10 text-info"><i class="fas fa-users me-1"></i><?= (int)$b['staff_count'] ?> موظف</span></div>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'staff'): ?>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>الموظف</th><th>الدور</th><th>الفرع</th><?php if ($_canEdit): ?><th>تغيير</th><?php endif; ?></tr></thead>
<tbody>
<?php foreach ($staff as $s): ?>
<tr>
  <td class="fw-semibold"><?= e($s['full_name']) ?></td>
  <td><?= $_roleLbl[$s['role']] ?? $s['role'] ?></td>
  <td><?php
    $bname = '—';
    foreach ($branches as $b) if ($b['id'] == $s['branch_id']) { $bname = $b['name']; break; }
    echo e($bname);
  ?></td>
  <?php if ($_canEdit): ?>
  <td>
    <form method="POST" class="d-flex gap-1">
      <input type="hidden" name="form_type" value="assign">
      <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
      <select name="branch_id" class="form-select form-select-sm" style="max-width:180px" onchange="this.form.submit()">
        <option value="">— بدون فرع —</option>
        <?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>" <?= $s['branch_id']==$b['id']?'selected':'' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
      </select>
    </form>
  </td>
  <?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<!-- Modal: فرع -->
<div class="modal fade" id="branchModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="brTitle"><i class="fas fa-code-branch me-2"></i>فرع جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="branch"><input type="hidden" name="id" id="br_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">اسم الفرع *</label><input type="text" name="name" id="br_name" class="form-control" required></div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">المدينة</label><input type="text" name="city" id="br_city" class="form-control"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">الجوال</label><input type="text" name="phone" id="br_phone" class="form-control"></div>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">العنوان</label><input type="text" name="address" id="br_address" class="form-control"></div>
      <div class="mb-3"><label class="form-label fw-semibold">مدير الفرع</label>
        <select name="manager_user_id" id="br_manager" class="form-select"><option value="">— بدون —</option>
          <?php foreach ($officeUsers as $uid5=>$un): ?><option value="<?= $uid5 ?>"><?= e($un) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="is_main" id="br_main"><label class="form-check-label" for="br_main">هذا هو الفرع الرئيسي</label></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<script>
function brNew(){document.getElementById('brTitle').innerHTML='<i class="fas fa-code-branch me-2"></i>فرع جديد';document.getElementById('br_id').value='';document.getElementById('br_name').value='';document.getElementById('br_city').value='';document.getElementById('br_phone').value='';document.getElementById('br_address').value='';document.getElementById('br_manager').value='';document.getElementById('br_main').checked=false;}
function brEdit(b){document.getElementById('brTitle').innerHTML='<i class="fas fa-edit me-2"></i>تعديل فرع';document.getElementById('br_id').value=b.id;document.getElementById('br_name').value=b.name;document.getElementById('br_city').value=b.city||'';document.getElementById('br_phone').value=b.phone||'';document.getElementById('br_address').value=b.address||'';document.getElementById('br_manager').value=b.manager_user_id||'';document.getElementById('br_main').checked=b.is_main=='1';new bootstrap.Modal(document.getElementById('branchModal')).show();}
</script>

<?php include '../includes/office_footer.php'; ?>
