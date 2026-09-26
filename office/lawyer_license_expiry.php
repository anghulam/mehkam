<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('lawyer_license_expiry','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'lawyer_license_expiry')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'تراخيص المحامين المهنية';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canApprove = can('lawyer_license_expiry','approve'); // مستوى المدير: يدير تراخيص الجميع
$_canAdd = can('lawyer_license_expiry','add'); $_canDel = can('lawyer_license_expiry','delete');

$conn->query("CREATE TABLE IF NOT EXISTS lawyer_licenses (
    id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, user_id INT NOT NULL,
    license_type VARCHAR(150) NOT NULL, license_number VARCHAR(100) DEFAULT NULL, expiry_date DATE NOT NULL,
    created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (isset($_GET['delete']) && $_canDel) {
    $own = $_canApprove ? '' : " AND user_id=$uid";
    $conn->query("DELETE FROM lawyer_licenses WHERE id=".(int)$_GET['delete']." AND office_id=$oid$own");
    header('Location: lawyer_license_expiry.php?msg=deleted'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'lic') {
    requirePerm('lawyer_license_expiry', 'add', 'lawyer_license_expiry.php?msg=denied');
    $target = (int)($_POST['user_id'] ?? 0);
    if (!$_canApprove) $target = $uid;
    $type = trim($_POST['license_type'] ?? '');
    $exp = $_POST['expiry_date'] ?? '';
    if (!$target || $type === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) { header('Location: lawyer_license_expiry.php?msg=invalid'); exit; }
    $te = $conn->real_escape_string($type);
    $num = $conn->real_escape_string(trim($_POST['license_number'] ?? ''));
    $conn->query("INSERT INTO lawyer_licenses (office_id,user_id,license_type,license_number,expiry_date,created_by) VALUES ($oid,$target,'$te','$num','$exp',".($uid ?: 'NULL').")");
    header('Location: lawyer_license_expiry.php?msg=saved'); exit;
}

$officeUsers = [];
$ur = $conn->query("SELECT id, full_name FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
if ($ur) while ($u = $ur->fetch_assoc()) $officeUsers[$u['id']] = $u['full_name'];
$_targetOptions = $_canApprove ? $officeUsers : array_intersect_key($officeUsers, [$uid => true]);

$scope = $_canApprove ? '' : " AND l.user_id=$uid";
$list = [];
$lr = $conn->query("SELECT l.*, u.full_name FROM lawyer_licenses l LEFT JOIN users u ON u.id=l.user_id WHERE l.office_id=$oid$scope ORDER BY l.expiry_date ASC LIMIT 200");
if ($lr) while ($x = $lr->fetch_assoc()) $list[] = $x;

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-id-card"></i> تراخيص المحامين المهنية</div>
<div class="mk-page-sub mb-3">تتبّع صلاحية عضوية الهيئة ورخصة المزاولة لمحامي المكتب — تنبيه قبل الانتهاء</div>

<?php if (isset($_GET['msg'])): ?><div class="alert alert-success alert-dismissible fade show">تم بنجاح<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<?php if ($_canAdd): ?>
<div class="card mb-4"><div class="card-header fw-bold"><i class="fas fa-plus me-1"></i>إضافة ترخيص</div><div class="card-body">
  <form method="POST" class="row g-2 align-items-end"><input type="hidden" name="form_type" value="lic">
    <?php if ($_canApprove): ?>
    <div class="col-md-3"><label class="form-label fw-semibold">المحامي</label><select name="user_id" class="form-select">
      <?php foreach ($_targetOptions as $uid2 => $un): ?><option value="<?= $uid2 ?>"><?= e($un) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="col-md-3"><label class="form-label fw-semibold">نوع الترخيص *</label><input name="license_type" class="form-control" placeholder="عضوية الهيئة، رخصة مزاولة..." required></div>
    <div class="col-md-3"><label class="form-label fw-semibold">رقم الترخيص</label><input name="license_number" class="form-control"></div>
    <div class="col-md-2"><label class="form-label fw-semibold">تاريخ الانتهاء *</label><input type="date" name="expiry_date" class="form-control" required></div>
    <div class="col-md-1"><button class="btn btn-primary w-100">حفظ</button></div>
  </form>
</div></div>
<?php endif; ?>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>المحامي</th><th>نوع الترخيص</th><th>الرقم</th><th>تاريخ الانتهاء</th><th>الحالة</th><th></th></tr></thead>
<tbody>
<?php if (!$list): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد تراخيص مسجّلة</td></tr>
<?php else: foreach ($list as $l):
  $days = (int)((strtotime($l['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
  if ($days < 0) { $lbl='منتهية منذ '.abs($days).' يوم'; $cls='danger'; } elseif ($days<=60) { $lbl='تنتهي خلال '.$days.' يوم'; $cls='warning'; } else { $lbl='سارية'; $cls='success'; } ?>
<tr>
  <td class="fw-semibold"><?= e($l['full_name'] ?: '—') ?></td>
  <td><?= e($l['license_type']) ?></td>
  <td style="font-size:12px"><?= e($l['license_number'] ?: '—') ?></td>
  <td><?= e($l['expiry_date']) ?></td>
  <td><span class="badge bg-<?= $cls ?> bg-opacity-10 text-<?= $cls ?>"><?= $lbl ?></span></td>
  <td><?php if ($_canDel): ?><a href="lawyer_license_expiry.php?delete=<?= (int)$l['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف؟')"><i class="fas fa-trash"></i></a><?php endif; ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div>

<?php include '../includes/office_footer.php'; ?>
