<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('legal_deadlines','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'legal_deadlines')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'المواعيد النظامية والتذكيرات';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('legal_deadlines','add'); $_canEdit = can('legal_deadlines','edit'); $_canDel = can('legal_deadlines','delete');
$_canApprove = can('legal_deadlines','approve');

$conn->query("CREATE TABLE IF NOT EXISTS legal_deadlines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    case_id INT DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    deadline_date DATE NOT NULL,
    assigned_to INT DEFAULT NULL,
    status ENUM('upcoming','done','missed') DEFAULT 'upcoming',
    notes TEXT,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM legal_deadlines WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header("Location: legal_deadlines.php?msg=deleted"); exit;
}
/* ── تعليم كمنجز ── */
if (isset($_GET['mark_done'])) {
    $did = (int)$_GET['mark_done'];
    $own = $_canApprove ? "" : " AND (assigned_to=$uid OR created_by=$uid)";
    $conn->query("UPDATE legal_deadlines SET status='done' WHERE id=$did AND office_id=$oid$own");
    header("Location: legal_deadlines.php?msg=saved"); exit;
}

/* ── حفظ ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'deadline') {
    $did = (int)($_POST['id'] ?? 0);
    requirePerm('legal_deadlines', $did ? 'edit' : 'add', 'legal_deadlines.php?msg=denied');
    if ($did && !$_canApprove) {
        $own = $conn->query("SELECT id FROM legal_deadlines WHERE id=$did AND office_id=$oid AND (assigned_to=$uid OR created_by=$uid)")->num_rows;
        if (!$own) { header("Location: legal_deadlines.php?msg=denied"); exit; }
    }
    $case_id = !empty($_POST['case_id']) ? (int)$_POST['case_id'] : 'NULL';
    $title = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $date = $conn->real_escape_string(trim($_POST['deadline_date'] ?? '') !== '' ? $_POST['deadline_date'] : date('Y-m-d'));
    $assigned = $_canApprove ? (!empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : 'NULL') : $uid;
    $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    if ($did) {
        $conn->query("UPDATE legal_deadlines SET case_id=$case_id,title='$title',deadline_date='$date',assigned_to=$assigned,notes='$notes' WHERE id=$did AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO legal_deadlines (office_id,case_id,title,deadline_date,assigned_to,notes,created_by) VALUES ($oid,$case_id,'$title','$date',$assigned,'$notes',".($uid ?: 'NULL').")");
    }
    header("Location: legal_deadlines.php?msg=saved"); exit;
}

/* ── تحديث المواعيد الفائتة تلقائياً ── */
$conn->query("UPDATE legal_deadlines SET status='missed' WHERE office_id=$oid AND status='upcoming' AND deadline_date < CURDATE()");

/* ── بيانات — موظف عادي يشوف فقط ما يخصّه ── */
$_ownScope = !$_canApprove ? " AND (d.assigned_to=$uid OR d.created_by=$uid)" : "";
$deadlines = [];
$dr = $conn->query("SELECT d.*, c.case_number, c.title case_title, u.full_name assigned_name FROM legal_deadlines d
    LEFT JOIN cases c ON d.case_id=c.id LEFT JOIN users u ON d.assigned_to=u.id
    WHERE d.office_id=$oid$_ownScope ORDER BY d.deadline_date ASC");
if ($dr) while ($r = $dr->fetch_assoc()) $deadlines[] = $r;

/* ── مواعيد مشتقّة من انتهاء العقود/الوكالات (للقراءة فقط) ── */
$derived = [];
if (hasFeature($conn, $oid, 'has_contracts') && can('contracts','view')) {
    $cr = $conn->query("SELECT id, contract_number title_num, title, end_date FROM contracts WHERE office_id=$oid AND end_date IS NOT NULL AND end_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    if ($cr) while ($r = $cr->fetch_assoc()) $derived[] = ['type'=>'contract','id'=>$r['id'],'title'=>'انتهاء عقد: '.$r['title_num'].' — '.$r['title'],'date'=>$r['end_date']];
    $pr = $conn->query("SELECT id, poa_number, title, expiry_date FROM poa WHERE office_id=$oid AND expiry_date IS NOT NULL AND expiry_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    if ($pr) while ($r = $pr->fetch_assoc()) $derived[] = ['type'=>'poa','id'=>$r['id'],'title'=>'انتهاء وكالة: '.$r['poa_number'].' — '.$r['title'],'date'=>$r['expiry_date']];
}

$cases_arr = [];
$ccr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid" . caseScope('cases') . " ORDER BY id DESC LIMIT 300");
if ($ccr) while ($r = $ccr->fetch_assoc()) $cases_arr[$r['id']] = $r['case_number'].' — '.mb_substr($r['title'],0,40);

$officeUsers = [];
if ($_canApprove) {
    $our = $conn->query("SELECT id, full_name FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
    if ($our) while ($r = $our->fetch_assoc()) $officeUsers[$r['id']] = $r['full_name'];
}

$upcoming_count = count(array_filter($deadlines, fn($d) => $d['status']==='upcoming'));
$missed_count = count(array_filter($deadlines, fn($d) => $d['status']==='missed'));

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-hourglass-half"></i> المواعيد النظامية والتذكيرات</div>
<div class="mk-page-sub mb-3">تابع المواعيد الحرجة وتذكيرات انتهاء العقود والوكالات</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#eff6ff;color:#2563eb"><i class="fas fa-clock"></i></div>
      <div><div class="fw-bold"><?= $upcoming_count ?></div><div class="text-muted" style="font-size:11px">مواعيد قادمة</div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#fef2f2;color:#dc2626"><i class="fas fa-triangle-exclamation"></i></div>
      <div><div class="fw-bold"><?= $missed_count ?></div><div class="text-muted" style="font-size:11px">مواعيد فائتة</div></div>
    </div></div>
  </div>
</div>

<div class="d-flex justify-content-end mb-3">
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#deadlineModal" onclick="dlNew()"><i class="fas fa-plus me-1"></i>موعد جديد</button>
  <?php endif; ?>
</div>

<div class="card mb-3"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>الموعد</th><th>القضية</th><th>التاريخ</th><th>المسؤول</th><th>الحالة</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$deadlines): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد مواعيد مسجّلة بعد</td></tr>
<?php else: foreach ($deadlines as $d):
  $daysLeft = (int)((strtotime($d['deadline_date']) - strtotime(date('Y-m-d'))) / 86400);
  $rowColor = $d['status']==='missed' ? '#fef2f2' : ($d['status']==='done' ? '' : ($daysLeft<=3 ? '#fffbeb' : ''));
?>
<tr style="<?= $rowColor ? 'background:'.$rowColor : '' ?>">
  <td class="fw-semibold"><?= e($d['title']) ?></td>
  <td style="font-size:12px"><?= $d['case_number'] ? e($d['case_number']) : '—' ?></td>
  <td style="font-size:12px;white-space:nowrap"><?= dDate($d['deadline_date'], false) ?>
    <?php if ($d['status']==='upcoming'): ?><br><small class="text-muted"><?= $daysLeft>=0 ? "باقي $daysLeft يوم" : '' ?></small><?php endif; ?>
  </td>
  <td style="font-size:12px"><?= e($d['assigned_name'] ?: '—') ?></td>
  <td>
    <?php if ($d['status']==='done'): ?><span class="badge bg-success bg-opacity-10 text-success">منجز</span>
    <?php elseif ($d['status']==='missed'): ?><span class="badge bg-danger bg-opacity-10 text-danger">فائت</span>
    <?php else: ?><span class="badge bg-warning bg-opacity-10 text-warning">قادم</span><?php endif; ?>
  </td>
  <td class="d-flex gap-1">
    <?php if ($d['status']!=='done'): ?><a href="legal_deadlines.php?mark_done=<?= $d['id'] ?>" class="btn btn-sm btn-outline-success" title="تعليم كمنجز"><i class="fas fa-check"></i></a><?php endif; ?>
    <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='dlEdit(<?= json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
    <?php if ($_canDel): ?><a href="legal_deadlines.php?delete=<?= $d['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا الموعد؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>

<?php if ($derived): ?>
<div class="card">
  <div class="card-header"><i class="fas fa-file-signature me-2 text-muted"></i>تذكيرات انتهاء العقود والوكالات (تلقائية)</div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
    <?php foreach ($derived as $dv): $dleft = (int)((strtotime($dv['date']) - strtotime(date('Y-m-d'))) / 86400); ?>
    <tr>
      <td><?= e($dv['title']) ?></td>
      <td style="font-size:12px;white-space:nowrap"><?= dDate($dv['date'], false) ?></td>
      <td style="font-size:12px"><?= $dleft >= 0 ? "باقي $dleft يوم" : "منتهي منذ ".abs($dleft)." يوم" ?></td>
      <td><a href="contracts.php?tab=<?= $dv['type']==='contract'?'contracts':'poa' ?>" class="btn btn-sm btn-outline-secondary py-0 px-2">فتح</a></td>
    </tr>
    <?php endforeach; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Modal -->
<div class="modal fade" id="deadlineModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="dlTitle"><i class="fas fa-hourglass-half me-2"></i>موعد جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="deadline"><input type="hidden" name="id" id="dl_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">وصف الموعد *</label><input type="text" name="title" id="dl_title" class="form-control" required placeholder="مثال: آخر يوم للطعن على الحكم"></div>
      <div class="mb-3"><label class="form-label fw-semibold">القضية المرتبطة (اختياري)</label>
        <select name="case_id" id="dl_case" class="form-select"><option value="">— بدون —</option>
          <?php foreach ($cases_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">التاريخ *</label><input type="date" name="deadline_date" id="dl_date" class="form-control" required></div>
        <?php if ($_canApprove): ?>
        <div class="col-md-6"><label class="form-label fw-semibold">المسؤول</label>
          <select name="assigned_to" id="dl_assigned" class="form-select"><option value="">— بدون —</option>
            <?php foreach ($officeUsers as $uid2=>$un): ?><option value="<?= $uid2 ?>"><?= e($un) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
      </div>
      <div class="mb-1"><label class="form-label fw-semibold">ملاحظات</label><textarea name="notes" id="dl_notes" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<script>
function dlNew(){document.getElementById('dlTitle').innerHTML='<i class="fas fa-hourglass-half me-2"></i>موعد جديد';document.getElementById('dl_id').value='';document.getElementById('dl_title').value='';document.getElementById('dl_case').value='';document.getElementById('dl_date').value=new Date().toISOString().slice(0,10);document.getElementById('dl_notes').value='';var a=document.getElementById('dl_assigned');if(a)a.value='';}
function dlEdit(d){document.getElementById('dlTitle').innerHTML='<i class="fas fa-edit me-2"></i>تعديل موعد';document.getElementById('dl_id').value=d.id;document.getElementById('dl_title').value=d.title;document.getElementById('dl_case').value=d.case_id||'';document.getElementById('dl_date').value=d.deadline_date;document.getElementById('dl_notes').value=d.notes||'';var a=document.getElementById('dl_assigned');if(a)a.value=d.assigned_to||'';new bootstrap.Modal(document.getElementById('deadlineModal')).show();}
</script>

<?php include '../includes/office_footer.php'; ?>
