<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('case_enforcement','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'case_enforcement')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'متابعة تنفيذ الأحكام';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('case_enforcement','add'); $_canEdit = can('case_enforcement','edit'); $_canDel = can('case_enforcement','delete');
$_canApprove = can('case_enforcement','approve'); // مستوى المدير: يرى كل ملفات التنفيذ ويسندها لأي موظف

$conn->query("CREATE TABLE IF NOT EXISTS case_enforcement (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    case_id INT NOT NULL,
    enforcement_number VARCHAR(100) DEFAULT NULL,
    court_name VARCHAR(200) DEFAULT NULL,
    amount DECIMAL(12,2) DEFAULT 0,
    collected_amount DECIMAL(12,2) DEFAULT 0,
    status ENUM('pending','in_progress','completed','suspended') DEFAULT 'pending',
    filed_date DATE DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id), INDEX idx_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("CREATE TABLE IF NOT EXISTS enforcement_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enforcement_id INT NOT NULL,
    note VARCHAR(500) NOT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_enf (enforcement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$officeUsers = [];
$ur = $conn->query("SELECT id, full_name FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
if ($ur) while ($u = $ur->fetch_assoc()) $officeUsers[$u['id']] = $u['full_name'];
$_assignable = $_canApprove ? $officeUsers : array_intersect_key($officeUsers, [$uid => true]);
$_ownScope = !$_canApprove ? " AND (e.created_by=$uid OR e.assigned_to=$uid)" : "";

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $eid = (int)$_GET['delete'];
    $own = $_canApprove ? '' : " AND (created_by=$uid OR assigned_to=$uid)";
    $ok = $conn->query("SELECT id FROM case_enforcement WHERE id=$eid AND office_id=$oid$own")->fetch_assoc();
    if ($ok) { $conn->query("DELETE FROM enforcement_updates WHERE enforcement_id=$eid"); $conn->query("DELETE FROM case_enforcement WHERE id=$eid"); }
    header('Location: case_enforcement.php?msg=deleted'); exit;
}

/* ── حفظ/تعديل ملف تنفيذ ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'enf') {
    $eid = (int)($_POST['id'] ?? 0);
    requirePerm('case_enforcement', $eid ? 'edit' : 'add', 'case_enforcement.php?msg=denied');
    $case_id = (int)($_POST['case_id'] ?? 0);
    $okCase = $conn->query("SELECT id FROM cases WHERE id=$case_id AND office_id=$oid" . caseScope('cases'))->fetch_assoc();
    if (!$okCase) { header('Location: case_enforcement.php?msg=invalid'); exit; }
    $num = $conn->real_escape_string(trim($_POST['enforcement_number'] ?? ''));
    $court = $conn->real_escape_string(trim($_POST['court_name'] ?? ''));
    $amount = max(0, (float)($_POST['amount'] ?? 0));
    $collected = max(0, (float)($_POST['collected_amount'] ?? 0));
    $status = in_array($_POST['status'] ?? '', ['pending','in_progress','completed','suspended'], true) ? $_POST['status'] : 'pending';
    $filed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['filed_date'] ?? '') ? "'".$_POST['filed_date']."'" : 'NULL';
    $asg = (int)($_POST['assigned_to'] ?? 0);
    if (!isset($_assignable[$asg])) $asg = $_canApprove ? 'NULL' : $uid;

    if ($eid) {
        $own = $_canApprove ? '' : " AND (created_by=$uid OR assigned_to=$uid)";
        $conn->query("UPDATE case_enforcement SET case_id=$case_id,enforcement_number='$num',court_name='$court',amount=$amount,collected_amount=$collected,status='$status',filed_date=$filed,assigned_to=".($asg==='NULL'?'NULL':(int)$asg)." WHERE id=$eid AND office_id=$oid$own");
    } else {
        $conn->query("INSERT INTO case_enforcement (office_id,case_id,enforcement_number,court_name,amount,collected_amount,status,filed_date,assigned_to,created_by)
            VALUES ($oid,$case_id,'$num','$court',$amount,$collected,'$status',$filed,".($asg==='NULL'?'NULL':(int)$asg).",".($uid ?: 'NULL').")");
    }
    header('Location: case_enforcement.php?msg=saved'); exit;
}

/* ── إضافة تحديث/ملاحظة متابعة ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'update') {
    $eid = (int)($_POST['enforcement_id'] ?? 0);
    $own = $_canApprove ? '' : " AND (created_by=$uid OR assigned_to=$uid)";
    $ok = $conn->query("SELECT id FROM case_enforcement WHERE id=$eid AND office_id=$oid$own")->fetch_assoc();
    $note = trim($_POST['note'] ?? '');
    if ($ok && $note !== '') {
        $ne = $conn->real_escape_string(mb_substr($note, 0, 500));
        $conn->query("INSERT INTO enforcement_updates (enforcement_id,note,created_by) VALUES ($eid,'$ne',".($uid ?: 'NULL').")");
    }
    header('Location: case_enforcement.php?msg=saved'); exit;
}

/* ── بيانات ── */
$cases_arr = [];
$cr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid" . caseScope('cases') . " ORDER BY id DESC LIMIT 300");
if ($cr) while ($x = $cr->fetch_assoc()) $cases_arr[$x['id']] = $x['case_number'] . ' — ' . mb_substr($x['title'], 0, 40);

$files = [];
$fr = $conn->query("SELECT e.*, c.case_number, c.title case_title, u.full_name asg_name FROM case_enforcement e
    LEFT JOIN cases c ON c.id=e.case_id LEFT JOIN users u ON u.id=e.assigned_to
    WHERE e.office_id=$oid$_ownScope ORDER BY FIELD(e.status,'in_progress','pending','suspended','completed'), e.id DESC LIMIT 300");
if ($fr) while ($x = $fr->fetch_assoc()) $files[] = $x;

$total_pending = 0; $total_collected = 0; $open_count = 0;
foreach ($files as $x) { $total_pending += (float)$x['amount'] - (float)$x['collected_amount']; $total_collected += (float)$x['collected_amount']; if ($x['status'] !== 'completed') $open_count++; }

$_stMap = ['pending'=>['بانتظار القيد','warning'],'in_progress'=>['قيد التنفيذ','primary'],'completed'=>['منتهي','success'],'suspended'=>['موقوف','secondary']];

$updatesBy = [];
if ($files) {
    $ids = implode(',', array_map(fn($f) => (int)$f['id'], $files));
    $ur2 = $conn->query("SELECT eu.*, u.full_name uname FROM enforcement_updates eu LEFT JOIN users u ON u.id=eu.created_by WHERE eu.enforcement_id IN ($ids) ORDER BY eu.id DESC");
    if ($ur2) while ($x = $ur2->fetch_assoc()) $updatesBy[$x['enforcement_id']][] = $x;
}

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-gavel"></i> متابعة تنفيذ الأحكام</div>
<div class="mk-page-sub mb-3">تتبّع مراحل تنفيذ الحكم بعد صدوره: الحجز، السداد، والتحصيل — بمعزل عن مراحل التقاضي نفسها</div>

<?php if (isset($_GET['msg'])):
  $mm = ['saved'=>['success','تم الحفظ بنجاح'],'deleted'=>['success','تم الحذف'],'invalid'=>['danger','اختر قضية صحيحة'],'denied'=>['danger','ليست لديك صلاحية']];
  $m = $mm[$_GET['msg']] ?? ['success','تم']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="fw-bold" style="font-size:19px"><?= $open_count ?></div><div class="text-muted" style="font-size:12px">ملفات تنفيذ مفتوحة</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="fw-bold text-success" style="font-size:19px"><?= number_format($total_collected,0) ?></div><div class="text-muted" style="font-size:12px">محصَّل (ر.س)</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="fw-bold text-warning" style="font-size:19px"><?= number_format($total_pending,0) ?></div><div class="text-muted" style="font-size:12px">متبقٍّ للتحصيل (ر.س)</div></div></div></div>
</div>

<div class="d-flex justify-content-end mb-3"><?php if ($_canAdd): ?><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#enfModal" onclick="enfNew()"><i class="fas fa-plus me-1"></i>ملف تنفيذ جديد</button><?php endif; ?></div>

<div class="row g-3">
<?php if (!$files): ?><div class="col-12"><div class="text-muted text-center py-5">لا توجد ملفات تنفيذ بعد</div></div>
<?php else: foreach ($files as $f): $st = $_stMap[$f['status']]; $remaining = (float)$f['amount'] - (float)$f['collected_amount']; ?>
<div class="col-lg-6"><div class="card h-100"><div class="card-body">
  <div class="d-flex justify-content-between align-items-start">
    <div><div class="fw-bold"><?= e($f['case_number'] ?: '—') ?></div><div class="text-muted" style="font-size:12px"><?= e(mb_substr($f['case_title'] ?? '', 0, 50)) ?></div></div>
    <span class="badge bg-<?= $st[1] ?> bg-opacity-10 text-<?= $st[1] ?>"><?= $st[0] ?></span>
  </div>
  <div class="row g-2 mt-1" style="font-size:12.5px">
    <div class="col-6"><i class="fas fa-hashtag text-muted me-1"></i><?= e($f['enforcement_number'] ?: '—') ?></div>
    <div class="col-6"><i class="fas fa-landmark text-muted me-1"></i><?= e($f['court_name'] ?: '—') ?></div>
    <div class="col-6"><i class="fas fa-coins text-muted me-1"></i>المطالبة: <b><?= number_format((float)$f['amount'],0) ?></b></div>
    <div class="col-6"><i class="fas fa-check text-success me-1"></i>محصَّل: <b><?= number_format((float)$f['collected_amount'],0) ?></b><?= $remaining > 0 ? ' <span class="text-warning">(متبقٍّ '.number_format($remaining,0).')</span>' : '' ?></div>
    <?php if ($f['asg_name']): ?><div class="col-12"><i class="fas fa-user text-muted me-1"></i><?= e($f['asg_name']) ?></div><?php endif; ?>
  </div>
  <?php if (!empty($updatesBy[$f['id']])): ?>
  <div class="mt-2 border-top pt-2" style="font-size:12px;max-height:90px;overflow:auto">
    <?php foreach (array_slice($updatesBy[$f['id']], 0, 4) as $u): ?><div class="mb-1"><b><?= e(date('Y-m-d', strtotime($u['created_at']))) ?>:</b> <?= e($u['note']) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="d-flex gap-1 mt-2">
    <button class="btn btn-sm btn-outline-success" onclick="upOpen(<?= (int)$f['id'] ?>)"><i class="fas fa-comment-dots me-1"></i>إضافة تحديث</button>
    <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='enfEdit(<?= json_encode($f, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
    <?php if ($_canDel): ?><a href="case_enforcement.php?delete=<?= (int)$f['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف ملف التنفيذ؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </div>
</div></div></div>
<?php endforeach; endif; ?>
</div>

<div class="modal fade" id="enfModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="enfTitle">ملف تنفيذ جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="enf"><input type="hidden" name="id" id="e_id">
    <div class="modal-body">
      <div class="mb-2"><label class="form-label fw-semibold">القضية *</label><select name="case_id" id="e_case" class="form-select" required><option value="">— اختر —</option>
        <?php foreach ($cases_arr as $cid => $cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?></select></div>
      <div class="row g-2">
        <div class="col-6"><label class="form-label fw-semibold">رقم الطلب التنفيذي</label><input name="enforcement_number" id="e_num" class="form-control"></div>
        <div class="col-6"><label class="form-label fw-semibold">محكمة التنفيذ</label><input name="court_name" id="e_court" class="form-control"></div>
        <div class="col-6"><label class="form-label fw-semibold">مبلغ المطالبة</label><input type="number" step="0.01" name="amount" id="e_amount" class="form-control" min="0"></div>
        <div class="col-6"><label class="form-label fw-semibold">المبلغ المحصَّل</label><input type="number" step="0.01" name="collected_amount" id="e_collected" class="form-control" min="0"></div>
        <div class="col-6"><label class="form-label fw-semibold">تاريخ القيد</label><input type="date" name="filed_date" id="e_filed" class="form-control"></div>
        <div class="col-6"><label class="form-label fw-semibold">الحالة</label><select name="status" id="e_status" class="form-select">
          <?php foreach ($_stMap as $k => $v): ?><option value="<?= $k ?>"><?= $v[0] ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label fw-semibold">إسناد إلى</label><select name="assigned_to" id="e_assigned" class="form-select">
          <option value="">— بدون —</option><?php foreach ($_assignable as $auid => $aun): ?><option value="<?= $auid ?>"><?= e($aun) ?></option><?php endforeach; ?></select></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<div class="modal fade" id="upModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">إضافة تحديث</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="update"><input type="hidden" name="enforcement_id" id="u_eid">
    <div class="modal-body"><textarea name="note" class="form-control" rows="3" placeholder="مثال: تم الحجز على الحساب البنكي" required></textarea></div>
    <div class="modal-footer"><button class="btn btn-success w-100">إضافة</button></div>
  </form>
</div></div></div>

<script>
function enfNew(){document.getElementById('enfTitle').innerText='ملف تنفيذ جديد';document.getElementById('e_id').value='';document.getElementById('e_case').value='';document.getElementById('e_num').value='';document.getElementById('e_court').value='';document.getElementById('e_amount').value='';document.getElementById('e_collected').value='';document.getElementById('e_filed').value='';document.getElementById('e_status').value='pending';document.getElementById('e_assigned').value='';}
function enfEdit(f){document.getElementById('enfTitle').innerText='تعديل ملف تنفيذ';document.getElementById('e_id').value=f.id;document.getElementById('e_case').value=f.case_id;document.getElementById('e_num').value=f.enforcement_number||'';document.getElementById('e_court').value=f.court_name||'';document.getElementById('e_amount').value=f.amount;document.getElementById('e_collected').value=f.collected_amount;document.getElementById('e_filed').value=f.filed_date||'';document.getElementById('e_status').value=f.status;document.getElementById('e_assigned').value=f.assigned_to||'';new bootstrap.Modal(document.getElementById('enfModal')).show();}
function upOpen(id){document.getElementById('u_eid').value=id;new bootstrap.Modal(document.getElementById('upModal')).show();}
</script>

<?php include '../includes/office_footer.php'; ?>
