<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('hr','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'hr')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'الموارد البشرية';
$uid = (int)($_SESSION['user_id'] ?? 0);
$uname = $_SESSION['full_name'] ?? '';
$_canManage = can('hr','approve'); // مدير الموارد البشرية: يشوف الكل ويوافق على الإجازات ويصحح الحضور
$_canEdit = can('hr','edit');

$conn->query("CREATE TABLE IF NOT EXISTS hr_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    user_id INT NOT NULL,
    attend_date DATE NOT NULL,
    check_in TIME DEFAULT NULL,
    check_out TIME DEFAULT NULL,
    status ENUM('present','late','absent','leave') DEFAULT 'present',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_date (user_id, attend_date),
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS hr_leave_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    user_id INT NOT NULL,
    leave_type ENUM('annual','sick','emergency','unpaid','other') DEFAULT 'annual',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── تسجيل حضور/انصراف ذاتي ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'checkin') {
    $today = date('Y-m-d'); $now = date('H:i:s');
    $conn->query("INSERT INTO hr_attendance (office_id,user_id,attend_date,check_in,status) VALUES ($oid,$uid,'$today','$now','present')
        ON DUPLICATE KEY UPDATE check_in=IF(check_in IS NULL,'$now',check_in)");
    header("Location: hr.php?tab=attendance&msg=saved"); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'checkout') {
    $today = date('Y-m-d'); $now = date('H:i:s');
    $conn->query("UPDATE hr_attendance SET check_out='$now' WHERE office_id=$oid AND user_id=$uid AND attend_date='$today'");
    header("Location: hr.php?tab=attendance&msg=saved"); exit;
}
/* ── تصحيح حضور يدوي (مدير) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'attendance_fix' && $_canEdit) {
    $au = (int)($_POST['user_id'] ?? 0);
    $ad = $conn->real_escape_string($_POST['attend_date'] ?? date('Y-m-d'));
    $ci = trim($_POST['check_in'] ?? '') !== '' ? "'".$conn->real_escape_string($_POST['check_in'])."'" : 'NULL';
    $co = trim($_POST['check_out'] ?? '') !== '' ? "'".$conn->real_escape_string($_POST['check_out'])."'" : 'NULL';
    $st = in_array($_POST['status'] ?? '', ['present','late','absent','leave'], true) ? $_POST['status'] : 'present';
    $nt = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    if ($au) {
        $conn->query("INSERT INTO hr_attendance (office_id,user_id,attend_date,check_in,check_out,status,notes) VALUES ($oid,$au,'$ad',$ci,$co,'$st','$nt')
            ON DUPLICATE KEY UPDATE check_in=$ci, check_out=$co, status='$st', notes='$nt'");
    }
    header("Location: hr.php?tab=attendance&msg=saved"); exit;
}

/* ── طلب إجازة ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'leave_request') {
    $ltype = in_array($_POST['leave_type'] ?? '', ['annual','sick','emergency','unpaid','other'], true) ? $_POST['leave_type'] : 'annual';
    $sd = $conn->real_escape_string($_POST['start_date'] ?? date('Y-m-d'));
    $ed = $conn->real_escape_string($_POST['end_date'] ?? $sd);
    $reason = $conn->real_escape_string(trim($_POST['reason'] ?? ''));
    $conn->query("INSERT INTO hr_leave_requests (office_id,user_id,leave_type,start_date,end_date,reason) VALUES ($oid,$uid,'$ltype','$sd','$ed','$reason')");
    header("Location: hr.php?tab=leaves&msg=saved"); exit;
}
/* ── اعتماد/رفض إجازة ── */
if (isset($_GET['approve_leave']) && $_canManage) {
    $conn->query("UPDATE hr_leave_requests SET status='approved', reviewed_by=$uid, reviewed_at=NOW() WHERE id=".(int)$_GET['approve_leave']." AND office_id=$oid AND status='pending'");
    header("Location: hr.php?tab=leaves&msg=saved"); exit;
}
if (isset($_GET['reject_leave']) && $_canManage) {
    $conn->query("UPDATE hr_leave_requests SET status='rejected', reviewed_by=$uid, reviewed_at=NOW() WHERE id=".(int)$_GET['reject_leave']." AND office_id=$oid AND status='pending'");
    header("Location: hr.php?tab=leaves&msg=saved"); exit;
}

/* ── بيانات ── */
$my_today = $conn->query("SELECT * FROM hr_attendance WHERE office_id=$oid AND user_id=$uid AND attend_date='".date('Y-m-d')."' LIMIT 1")->fetch_assoc();

$att_where = "a.office_id=$oid" . (!$_canManage ? " AND a.user_id=$uid" : "");
$attendance = [];
$ar = $conn->query("SELECT a.*, u.full_name FROM hr_attendance a JOIN users u ON a.user_id=u.id
    WHERE $att_where ORDER BY a.attend_date DESC LIMIT 200");
if ($ar) while ($r = $ar->fetch_assoc()) $attendance[] = $r;

$leave_where = "l.office_id=$oid" . (!$_canManage ? " AND l.user_id=$uid" : "");
$leaves = [];
$lr = $conn->query("SELECT l.*, u.full_name FROM hr_leave_requests l JOIN users u ON l.user_id=u.id
    WHERE $leave_where ORDER BY l.created_at DESC LIMIT 200");
if ($lr) while ($r = $lr->fetch_assoc()) $leaves[] = $r;

$officeUsers = [];
if ($_canEdit) {
    $our = $conn->query("SELECT id, full_name FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
    if ($our) while ($r = $our->fetch_assoc()) $officeUsers[$r['id']] = $r['full_name'];
}

$_leaveTypeMap = ['annual'=>'سنوية','sick'=>'مرضية','emergency'=>'طارئة','unpaid'=>'بدون راتب','other'=>'أخرى'];
$_attStatusMap = ['present'=>['حاضر','success'],'late'=>['متأخر','warning'],'absent'=>['غائب','danger'],'leave'=>['إجازة','secondary']];
$_leaveStatusMap = ['pending'=>['قيد المراجعة','warning'],'approved'=>['معتمدة','success'],'rejected'=>['مرفوضة','danger']];

$tab = in_array($_GET['tab'] ?? '', ['leaves']) ? 'leaves' : 'attendance';

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-user-tie"></i> الموارد البشرية</div>
<div class="mk-page-sub mb-3">الحضور والانصراف وطلبات الإجازة</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم الحفظ بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card mb-3" style="background:linear-gradient(135deg,#0c1b36,#1a3a6e)">
  <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
    <div style="color:#fff">
      <div class="fw-bold" style="font-size:15px"><i class="fas fa-clock me-2" style="color:#e8c040"></i>حضورك اليوم</div>
      <div style="font-size:12px;opacity:.8">
        <?= $my_today && $my_today['check_in'] ? 'دخول: '.substr($my_today['check_in'],0,5) : 'لم تسجّل دخولاً بعد' ?>
        <?= $my_today && $my_today['check_out'] ? ' — خروج: '.substr($my_today['check_out'],0,5) : '' ?>
      </div>
    </div>
    <div class="d-flex gap-2">
      <form method="POST"><input type="hidden" name="form_type" value="checkin">
        <button class="btn btn-sm fw-bold" style="background:#e8c040;color:#17233d" <?= ($my_today && $my_today['check_in']) ? 'disabled' : '' ?>><i class="fas fa-sign-in-alt me-1"></i>تسجيل حضور</button>
      </form>
      <form method="POST"><input type="hidden" name="form_type" value="checkout">
        <button class="btn btn-outline-light btn-sm" <?= (!$my_today || !$my_today['check_in'] || $my_today['check_out']) ? 'disabled' : '' ?>><i class="fas fa-sign-out-alt me-1"></i>تسجيل انصراف</button>
      </form>
    </div>
  </div>
</div>

<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='attendance'?'active':'' ?>" href="hr.php?tab=attendance">الحضور والانصراف</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='leaves'?'active':'' ?>" href="hr.php?tab=leaves">طلبات الإجازة</a></li>
</ul>

<?php if ($tab === 'attendance'): ?>
<?php if ($_canEdit): ?>
<div class="d-flex justify-content-end mb-3">
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#attFixModal"><i class="fas fa-pen me-1"></i>تصحيح حضور</button>
</div>
<?php endif; ?>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><?php if ($_canManage): ?><th>الموظف</th><?php endif; ?><th>التاريخ</th><th>الدخول</th><th>الخروج</th><th>الحالة</th></tr></thead>
<tbody>
<?php if (!$attendance): ?><tr><td colspan="5" class="text-center text-muted py-4">لا توجد سجلات حضور بعد</td></tr>
<?php else: foreach ($attendance as $a): $sm = $_attStatusMap[$a['status']] ?? ['—','secondary']; ?>
<tr>
  <?php if ($_canManage): ?><td class="fw-semibold"><?= e($a['full_name']) ?></td><?php endif; ?>
  <td style="font-size:12px"><?= dDate($a['attend_date'], false) ?></td>
  <td><?= $a['check_in'] ? substr($a['check_in'],0,5) : '—' ?></td>
  <td><?= $a['check_out'] ? substr($a['check_out'],0,5) : '—' ?></td>
  <td><span class="badge bg-<?= $sm[1] ?> bg-opacity-10 text-<?= $sm[1] ?>"><?= $sm[0] ?></span></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php if ($tab === 'leaves'): ?>
<div class="d-flex justify-content-end mb-3">
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#leaveModal"><i class="fas fa-plus me-1"></i>طلب إجازة</button>
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><?php if ($_canManage): ?><th>الموظف</th><?php endif; ?><th>النوع</th><th>من</th><th>إلى</th><th>السبب</th><th>الحالة</th><?php if ($_canManage): ?><th>إجراءات</th><?php endif; ?></tr></thead>
<tbody>
<?php if (!$leaves): ?><tr><td colspan="7" class="text-center text-muted py-4">لا توجد طلبات إجازة بعد</td></tr>
<?php else: foreach ($leaves as $l): $sm = $_leaveStatusMap[$l['status']] ?? ['—','secondary']; ?>
<tr>
  <?php if ($_canManage): ?><td class="fw-semibold"><?= e($l['full_name']) ?></td><?php endif; ?>
  <td><?= $_leaveTypeMap[$l['leave_type']] ?? $l['leave_type'] ?></td>
  <td style="font-size:12px"><?= dDate($l['start_date'], false) ?></td>
  <td style="font-size:12px"><?= dDate($l['end_date'], false) ?></td>
  <td style="font-size:12px;max-width:200px"><?= e(mb_substr($l['reason'] ?? '',0,60)) ?></td>
  <td><span class="badge bg-<?= $sm[1] ?> bg-opacity-10 text-<?= $sm[1] ?>"><?= $sm[0] ?></span></td>
  <?php if ($_canManage): ?>
  <td class="d-flex gap-1">
    <?php if ($l['status']==='pending'): ?>
    <a href="hr.php?tab=leaves&approve_leave=<?= $l['id'] ?>" class="btn btn-sm btn-outline-success" onclick="return confirm('اعتماد الإجازة؟')"><i class="fas fa-check"></i></a>
    <a href="hr.php?tab=leaves&reject_leave=<?= $l['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('رفض الإجازة؟')"><i class="fas fa-xmark"></i></a>
    <?php endif; ?>
  </td>
  <?php endif; ?>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<!-- Modal: طلب إجازة -->
<div class="modal fade" id="leaveModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-umbrella-beach me-2"></i>طلب إجازة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="leave_request">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">نوع الإجازة</label>
        <select name="leave_type" class="form-select">
          <?php foreach ($_leaveTypeMap as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">من تاريخ</label><input type="date" name="start_date" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">إلى تاريخ</label><input type="date" name="end_date" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="mb-1"><label class="form-label fw-semibold">السبب</label><textarea name="reason" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>إرسال الطلب</button></div>
  </form>
</div></div></div>

<?php if ($_canEdit): ?>
<!-- Modal: تصحيح حضور -->
<div class="modal fade" id="attFixModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-pen me-2"></i>تصحيح حضور</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="attendance_fix">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">الموظف</label>
        <select name="user_id" class="form-select" required>
          <?php foreach ($officeUsers as $uid3=>$un): ?><option value="<?= $uid3 ?>"><?= e($un) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">التاريخ</label><input type="date" name="attend_date" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">دخول</label><input type="time" name="check_in" class="form-control"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">خروج</label><input type="time" name="check_out" class="form-control"></div>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">الحالة</label>
        <select name="status" class="form-select">
          <?php foreach ($_attStatusMap as $k=>$v): ?><option value="<?= $k ?>"><?= $v[0] ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-1"><label class="form-label fw-semibold">ملاحظات</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
