<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('absence_delegation','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'absence_delegation')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'تفويض غياب مؤقت';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('absence_delegation','add'); $_canDel = can('absence_delegation','delete');
$_canApprove = can('absence_delegation','approve'); // مستوى المدير: يفوّض نيابة عن أي موظف ويرى الكل

$conn->query("CREATE TABLE IF NOT EXISTS absence_delegations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    from_user_id INT NOT NULL,
    to_user_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    status ENUM('active','ended') DEFAULT 'active',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
// إنهاء تلقائي لأي تفويض انتهت مدته
$conn->query("UPDATE absence_delegations SET status='ended' WHERE office_id=$oid AND status='active' AND end_date < CURDATE()");

$officeUsers = [];
$ur = $conn->query("SELECT id, full_name FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
if ($ur) while ($u = $ur->fetch_assoc()) $officeUsers[$u['id']] = $u['full_name'];
$_fromOptions = $_canApprove ? $officeUsers : array_intersect_key($officeUsers, [$uid => true]);

/* ── إنهاء مبكر ── */
if (isset($_GET['end']) && $_canDel) {
    $did = (int)$_GET['end'];
    $own = $_canApprove ? '' : " AND (from_user_id=$uid OR created_by=$uid)";
    $conn->query("UPDATE absence_delegations SET status='ended' WHERE id=$did AND office_id=$oid$own");
    header('Location: absence_delegation.php?msg=saved'); exit;
}

/* ── إنشاء تفويض ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'deleg') {
    requirePerm('absence_delegation', 'add', 'absence_delegation.php?msg=denied');
    $from = (int)($_POST['from_user_id'] ?? 0);
    if (!isset($_fromOptions[$from])) $from = $uid;
    $to = (int)($_POST['to_user_id'] ?? 0);
    $start = $_POST['start_date'] ?? ''; $end = $_POST['end_date'] ?? '';
    if (!isset($officeUsers[$to]) || $to === $from || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
        header('Location: absence_delegation.php?msg=invalid'); exit;
    }
    $reason = $conn->real_escape_string(trim($_POST['reason'] ?? ''));
    $conn->query("INSERT INTO absence_delegations (office_id,from_user_id,to_user_id,start_date,end_date,reason,created_by) VALUES ($oid,$from,$to,'$start','$end','$reason',".($uid ?: 'NULL').")");
    header('Location: absence_delegation.php?msg=saved'); exit;
}

$scope = $_canApprove ? '' : " AND (from_user_id=$uid OR to_user_id=$uid)";
$list = [];
$lr = $conn->query("SELECT d.*, uf.full_name from_name, ut.full_name to_name FROM absence_delegations d
    LEFT JOIN users uf ON uf.id=d.from_user_id LEFT JOIN users ut ON ut.id=d.to_user_id
    WHERE d.office_id=$oid$scope ORDER BY d.status='active' DESC, d.id DESC LIMIT 100");
if ($lr) while ($x = $lr->fetch_assoc()) $list[] = $x;

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-right-left"></i> تفويض غياب مؤقت</div>
<div class="mk-page-sub mb-3">عند الإجازة، انقل ظهور مهامك المُسندة إليك لزميل خلال فترة محددة — ترجع لك تلقائياً بعد انتهاء المدة</div>

<?php if (isset($_GET['msg'])):
  $mm = ['saved'=>['success','تم الحفظ بنجاح'],'invalid'=>['danger','تحقّق من التواريخ واختيار الزميل'],'denied'=>['danger','ليست لديك صلاحية']];
  $m = $mm[$_GET['msg']] ?? ['success','تم']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($_canAdd): ?>
<div class="card mb-4"><div class="card-header fw-bold"><i class="fas fa-plus me-1"></i>تفويض جديد</div><div class="card-body">
  <form method="POST" class="row g-2 align-items-end"><input type="hidden" name="form_type" value="deleg">
    <?php if ($_canApprove): ?>
    <div class="col-md-3"><label class="form-label fw-semibold">الموظف الغائب</label><select name="from_user_id" class="form-select">
      <?php foreach ($_fromOptions as $fid => $fn): ?><option value="<?= $fid ?>" <?= $fid==$uid?'selected':'' ?>><?= e($fn) ?></option><?php endforeach; ?></select></div>
    <?php else: ?><input type="hidden" name="from_user_id" value="<?= $uid ?>"><?php endif; ?>
    <div class="col-md-3"><label class="form-label fw-semibold">تُنقل المهام إلى *</label><select name="to_user_id" class="form-select" required>
      <option value="">— اختر زميلاً —</option><?php foreach ($officeUsers as $oid2 => $on): if ($oid2==$uid) continue; ?><option value="<?= $oid2 ?>"><?= e($on) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label fw-semibold">من</label><input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-2"><label class="form-label fw-semibold">إلى</label><input type="date" name="end_date" class="form-control" required></div>
    <div class="col-md-2"><button class="btn btn-primary w-100"><i class="fas fa-right-left me-1"></i>تفويض</button></div>
    <div class="col-12"><input type="text" name="reason" class="form-control form-control-sm" placeholder="السبب (اختياري) — إجازة سنوية، مأمورية..."></div>
  </form>
</div></div>
<?php endif; ?>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>من</th><th>إلى</th><th>الفترة</th><th>السبب</th><th>الحالة</th><th></th></tr></thead>
<tbody>
<?php if (!$list): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد تفويضات بعد</td></tr>
<?php else: foreach ($list as $d): $active = $d['status']==='active'; ?>
<tr>
  <td class="fw-semibold"><?= e($d['from_name'] ?: '—') ?></td>
  <td><?= e($d['to_name'] ?: '—') ?></td>
  <td style="font-size:12px"><?= e($d['start_date']) ?> → <?= e($d['end_date']) ?></td>
  <td style="font-size:12px"><?= e($d['reason'] ?: '—') ?></td>
  <td><span class="badge bg-<?= $active?'success':'secondary' ?> bg-opacity-10 text-<?= $active?'success':'secondary' ?>"><?= $active?'فعّال':'منتهٍ' ?></span></td>
  <td><?php if ($active && $_canDel): ?><a href="absence_delegation.php?end=<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('إنهاء هذا التفويض الآن؟')">إنهاء</a><?php endif; ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div>

<?php include '../includes/office_footer.php'; ?>
