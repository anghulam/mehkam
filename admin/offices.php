<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireAdmin();
$page_title = 'إدارة المكاتب';

// حذف
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM offices WHERE id=$id");
    header("Location: offices.php?msg=deleted"); exit;
}

// تغيير الحالة
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $s  = $_GET['status'] ?? 'active';
    if (!in_array($s, ['active','trial','expired','suspended'])) $s = 'active';
    $conn->query("UPDATE offices SET status='$s' WHERE id=$id");
    header("Location: offices.php?msg=updated"); exit;
}

// إنشاء حساب مستخدم لمكتب موجود
if (isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $oid   = (int)$_POST['office_id'];
    $uname = $conn->real_escape_string(trim($_POST['username']));
    $upass = trim($_POST['password']);

    // تحقق
    if (!$uname || strlen($upass) < 6) {
        header("Location: offices.php?err=" . urlencode('اسم المستخدم وكلمة المرور (6+ أحرف) مطلوبان')); exit;
    }
    if ($conn->query("SELECT id FROM users WHERE username='$uname' LIMIT 1")->num_rows > 0) {
        header("Location: offices.php?err=" . urlencode('اسم المستخدم مستخدم بالفعل')); exit;
    }
    $office = $conn->query("SELECT * FROM offices WHERE id=$oid LIMIT 1")->fetch_assoc();
    if (!$office) { header("Location: offices.php?err=" . urlencode('المكتب غير موجود')); exit; }

    $hash = $conn->real_escape_string(password_hash($upass, PASSWORD_BCRYPT));
    $fn   = $conn->real_escape_string($office['owner_name']);
    $em   = $conn->real_escape_string($office['email']);
    $ph   = $conn->real_escape_string($office['phone']);
    $conn->query("INSERT INTO users (username,password,role,office_id,full_name,email,phone,is_active)
        VALUES ('$uname','$hash','office_owner',$oid,'$fn','$em','$ph',1)");
    header("Location: offices.php?msg=saved"); exit;
}

// إضافة / تعديل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = $conn->real_escape_string($_POST['name']);
    $license  = $conn->real_escape_string($_POST['license_number']);
    $owner    = $conn->real_escape_string($_POST['owner_name']);
    $pkg      = !empty($_POST['package_id']) ? (int)$_POST['package_id'] : 'NULL';
    $city     = $conn->real_escape_string($_POST['city']);
    $phone    = $conn->real_escape_string($_POST['phone']);
    $email    = $conn->real_escape_string($_POST['email']);
    $sub_end_raw = trim($_POST['subscription_end'] ?? '');
    $sub_end_sql = $sub_end_raw !== '' ? "'".$conn->real_escape_string($sub_end_raw)."'" : "NULL";
    $status_raw = $_POST['status'] ?? 'trial';
    $status     = in_array($status_raw, ['active','trial','expired','suspended']) ? $status_raw : 'trial';

    if (!empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $conn->query("UPDATE offices SET name='$name',license_number='$license',owner_name='$owner',package_id=$pkg,city='$city',phone='$phone',email='$email',subscription_end=$sub_end_sql,status='$status' WHERE id=$id");
        // تحديث كلمة المرور إن أُدخلت
        if (!empty($_POST['new_password'])) {
            $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
            $hash_e = $conn->real_escape_string($hash);
            $conn->query("UPDATE users SET password='$hash_e' WHERE office_id=$id AND role='office_owner' LIMIT 1");
        }
    } else {
        // التحقق من اسم المستخدم
        $uname = $conn->real_escape_string(trim($_POST['username'] ?? ''));
        $upass = trim($_POST['password'] ?? '');
        $err   = '';
        if (!$uname) $err = 'اسم المستخدم مطلوب';
        elseif (strlen($upass) < 6) $err = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
        elseif ($conn->query("SELECT id FROM users WHERE username='$uname' LIMIT 1")->num_rows > 0)
            $err = 'اسم المستخدم مستخدم بالفعل';

        if ($err) {
            header("Location: offices.php?err=" . urlencode($err)); exit;
        }

        $conn->query("INSERT INTO offices (name,license_number,owner_name,package_id,city,phone,email,subscription_end,status)
            VALUES ('$name','$license','$owner',$pkg,'$city','$phone','$email',$sub_end_sql,'$status')");
        $office_id = $conn->insert_id;

        $hash = $conn->real_escape_string(password_hash($upass, PASSWORD_BCRYPT));
        $fn   = $conn->real_escape_string($owner);
        $em   = $conn->real_escape_string($_POST['email']);
        $ph   = $conn->real_escape_string($_POST['phone']);
        $conn->query("INSERT INTO users (username,password,role,office_id,full_name,email,phone,is_active)
            VALUES ('$uname','$hash','office_owner',$office_id,'$fn','$em','$ph',1)");
    }
    header("Location: offices.php?msg=saved"); exit;
}

// بحث وفلتر
$where = "1=1";
if (!empty($_GET['q'])) {
    $q = $conn->real_escape_string($_GET['q']);
    $where .= " AND (o.name LIKE '%$q%' OR o.city LIKE '%$q%' OR o.owner_name LIKE '%$q%')";
}
if (!empty($_GET['status_filter'])) {
    $sf = $conn->real_escape_string($_GET['status_filter']);
    $where .= " AND o.status='$sf'";
}

$offices  = $conn->query("SELECT o.*, p.name pkg_name,
    u.username AS office_username, u.id AS user_id
    FROM offices o
    LEFT JOIN packages p ON o.package_id=p.id
    LEFT JOIN users u ON u.office_id=o.id AND u.role='office_owner'
    WHERE $where ORDER BY o.id DESC");
$packages = $conn->query("SELECT * FROM packages WHERE is_active=1");
$pkgs_arr = [];
while ($pk = $packages->fetch_assoc()) $pkgs_arr[$pk['id']] = $pk['name'];

// تعديل
$edit = null;
if (isset($_GET['edit'])) {
    $edit = $conn->query("SELECT * FROM offices WHERE id=".(int)$_GET['edit'])->fetch_assoc();
}

include '../includes/admin_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
  <div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle me-2"></i>
    <?= $_GET['msg']==='deleted'?'تم الحذف':'تم الحفظ' ?> بنجاح
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>
<?php if (isset($_GET['err'])): ?>
  <div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-circle me-2"></i><?= e($_GET['err']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- فلتر وبحث -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="GET">
      <div class="col-auto flex-grow-1">
        <input type="text" name="q" class="form-control" placeholder="بحث بالاسم أو المدينة..." value="<?= e($_GET['q'] ?? '') ?>">
      </div>
      <div class="col-auto">
        <select name="status_filter" class="form-select">
          <option value="">جميع الحالات</option>
          <option value="active" <?= ($_GET['status_filter']??'')==='active'?'selected':'' ?>>نشط</option>
          <option value="trial" <?= ($_GET['status_filter']??'')==='trial'?'selected':'' ?>>تجريبي</option>
          <option value="expired" <?= ($_GET['status_filter']??'')==='expired'?'selected':'' ?>>منتهي</option>
          <option value="suspended" <?= ($_GET['status_filter']??'')==='suspended'?'selected':'' ?>>موقوف</option>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary"><i class="fas fa-search me-1"></i>بحث</button>
        <a href="offices.php" class="btn btn-outline-secondary ms-1">إعادة</a>
      </div>
      <div class="col-auto ms-auto">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#officeModal">
          <i class="fas fa-plus me-1"></i>إضافة مكتب
        </button>
      </div>
    </form>
  </div>
</div>

<!-- الجدول -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>#</th><th>اسم المكتب</th><th>المالك</th><th>المدينة</th>
          <th>الباقة</th><th>اسم المستخدم</th><th>انتهاء الاشتراك</th><th>الحالة</th><th>إجراءات</th>
        </tr></thead>
        <tbody>
        <?php while($o = $offices->fetch_assoc()): ?>
        <tr>
          <td><?= $o['id'] ?></td>
          <td>
            <div class="fw-semibold"><?= e($o['name']) ?></div>
            <small class="text-muted"><?= e($o['license_number']) ?></small>
          </td>
          <td><?= e($o['owner_name']) ?></td>
          <td><?= e($o['city']) ?></td>
          <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= e($o['pkg_name'] ?? '—') ?></span></td>
          <td>
            <?php if ($o['office_username']): ?>
              <span class="badge bg-success bg-opacity-10 text-success fw-semibold" style="font-size:12px">
                <i class="fas fa-user me-1"></i><?= e($o['office_username']) ?>
              </span>
            <?php else: ?>
              <button type="button" class="btn btn-sm btn-outline-warning"
                      onclick="openCreateUser(<?= $o['id'] ?>, '<?= e(addslashes($o['name'])) ?>')"
                      title="إنشاء حساب دخول">
                <i class="fas fa-user-plus me-1"></i>إنشاء حساب
              </button>
            <?php endif; ?>
          </td>
          <td><?= $o['subscription_end'] ? date('Y/m/d', strtotime($o['subscription_end'])) : '—' ?></td>
          <td><?= statusBadge($o['status']) ?></td>
          <td>
            <a href="offices.php?edit=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary" title="تعديل">
              <i class="fas fa-edit"></i>
            </a>
            <?php if ($o['status']==='active'): ?>
            <a href="offices.php?toggle=<?= $o['id'] ?>&status=suspended" class="btn btn-sm btn-outline-warning" title="إيقاف" onclick="return confirm('إيقاف؟')">
              <i class="fas fa-pause"></i>
            </a>
            <?php else: ?>
            <a href="offices.php?toggle=<?= $o['id'] ?>&status=active" class="btn btn-sm btn-outline-success" title="تفعيل">
              <i class="fas fa-play"></i>
            </a>
            <?php endif; ?>
            <a href="offices.php?delete=<?= $o['id'] ?>" class="btn btn-sm btn-outline-danger" title="حذف" onclick="return confirm('حذف هذا المكتب؟')">
              <i class="fas fa-trash"></i>
            </a>
          </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal إضافة/تعديل -->
<div class="modal fade" id="officeModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-building me-2"></i><?= $edit ? 'تعديل مكتب' : 'إضافة مكتب جديد' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">اسم المكتب *</label>
              <input type="text" name="name" class="form-control" required value="<?= e($edit['name'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">رقم الترخيص</label>
              <input type="text" name="license_number" class="form-control" value="<?= e($edit['license_number'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">اسم المالك</label>
              <input type="text" name="owner_name" class="form-control" value="<?= e($edit['owner_name'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">الباقة</label>
              <select name="package_id" class="form-select">
                <?php foreach ($pkgs_arr as $pid => $pname): ?>
                  <option value="<?= $pid ?>" <?= ($edit['package_id'] ?? '')==$pid?'selected':'' ?>><?= e($pname) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">المدينة</label>
              <input type="text" name="city" class="form-control" value="<?= e($edit['city'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">رقم الجوال</label>
              <input type="text" name="phone" class="form-control" value="<?= e($edit['phone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">البريد الإلكتروني</label>
              <input type="email" name="email" class="form-control" value="<?= e($edit['email'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">تاريخ انتهاء الاشتراك</label>
              <input type="date" name="subscription_end" class="form-control" value="<?= e($edit['subscription_end'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">الحالة</label>
              <select name="status" class="form-select">
                <option value="active"    <?= ($edit['status']??'')==='active'?'selected':'' ?>>نشط</option>
                <option value="trial"     <?= ($edit['status']??'')==='trial'?'selected':'' ?>>تجريبي</option>
                <option value="suspended" <?= ($edit['status']??'')==='suspended'?'selected':'' ?>>موقوف</option>
                <option value="expired"   <?= ($edit['status']??'')==='expired'?'selected':'' ?>>منتهي</option>
              </select>
            </div>

            <!-- حساب المستخدم -->
            <?php if (!$edit): ?>
            <div class="col-12">
              <hr class="my-1">
              <div class="fw-bold mb-2" style="font-size:13px;color:#374151">
                <i class="fas fa-user-circle me-1 text-primary"></i>بيانات حساب الدخول (لمالك المكتب)
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">اسم المستخدم <span class="text-danger">*</span></label>
              <input type="text" name="username" class="form-control" required
                     autocomplete="off" placeholder="مثال: office_name">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">كلمة المرور <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" name="password" id="offPassInput" class="form-control" required
                       autocomplete="new-password" placeholder="6 أحرف على الأقل">
                <button type="button" class="btn btn-outline-secondary"
                        onclick="var i=document.getElementById('offPassInput');i.type=i.type==='password'?'text':'password'">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
            <?php else: ?>
            <!-- تعديل كلمة المرور فقط -->
            <div class="col-12">
              <hr class="my-1">
              <div class="fw-bold mb-2" style="font-size:13px;color:#374151">
                <i class="fas fa-key me-1 text-warning"></i>تغيير كلمة المرور (اختياري)
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">كلمة المرور الجديدة</label>
              <div class="input-group">
                <input type="password" name="new_password" id="offPassEdit" class="form-control"
                       autocomplete="new-password" placeholder="اتركه فارغاً للإبقاء على الحالية">
                <button type="button" class="btn btn-outline-secondary"
                        onclick="var i=document.getElementById('offPassEdit');i.type=i.type==='password'?'text':'password'">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
              <div class="form-text">
                <?php
                $eu = $conn->query("SELECT username FROM users WHERE office_id=".(int)($edit['id'])." AND role='office_owner' LIMIT 1")->fetch_assoc();
                if ($eu) echo 'اسم المستخدم الحالي: <strong>'.e($eu['username']).'</strong>';
                ?>
              </div>
            </div>
            <?php endif; ?>

          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($edit): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('officeModal')).show();
});
</script>
<?php endif; ?>

<!-- Modal إنشاء حساب مستخدم لمكتب موجود -->
<div class="modal fade" id="createUserModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#fff;border:none">
        <h6 class="modal-title mb-0"><i class="fas fa-user-plus me-2"></i>إنشاء حساب دخول</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="create_user">
        <input type="hidden" name="office_id" id="cuOfficeId">
        <div class="modal-body">
          <p class="text-muted mb-3" style="font-size:13px">
            إنشاء حساب لـ: <strong id="cuOfficeName"></strong>
          </p>
          <div class="mb-3">
            <label class="form-label fw-semibold">اسم المستخدم <span class="text-danger">*</span></label>
            <input type="text" name="username" class="form-control" required
                   autocomplete="off" placeholder="بدون مسافات">
          </div>
          <div class="mb-1">
            <label class="form-label fw-semibold">كلمة المرور <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" name="password" id="cuPassInput" class="form-control" required
                     autocomplete="new-password" placeholder="6 أحرف على الأقل">
              <button type="button" class="btn btn-outline-secondary"
                      onclick="var i=document.getElementById('cuPassInput');i.type=i.type==='password'?'text':'password'">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-success btn-sm">
            <i class="fas fa-user-plus me-1"></i>إنشاء الحساب
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openCreateUser(id, name) {
  document.getElementById('cuOfficeId').value  = id;
  document.getElementById('cuOfficeName').textContent = name;
  new bootstrap.Modal(document.getElementById('createUserModal')).show();
}
</script>

<?php include '../includes/admin_footer.php'; ?>
