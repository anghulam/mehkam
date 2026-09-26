<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('time_tracking','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'time_tracking')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'تتبّع الوقت والساعات';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('time_tracking','add'); $_canEdit = can('time_tracking','edit'); $_canDel = can('time_tracking','delete');
$_restricted = isRestricted(); // موظف مقيَّد يرى ويضيف فقط ساعاته الخاصة

$conn->query("CREATE TABLE IF NOT EXISTS time_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    case_id INT DEFAULT NULL,
    user_id INT NOT NULL,
    entry_date DATE NOT NULL,
    hours DECIMAL(5,2) NOT NULL DEFAULT 0,
    hourly_rate DECIMAL(10,2) DEFAULT 0,
    billable TINYINT DEFAULT 1,
    invoiced TINYINT DEFAULT 0,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id), INDEX idx_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حذف ── */
if (isset($_GET['delete'])) {
    $scope = $_restricted ? " AND user_id=$uid" : "";
    if ($_canDel) $conn->query("DELETE FROM time_entries WHERE id=".(int)$_GET['delete']." AND office_id=$oid$scope");
    header("Location: time_tracking.php?tab=log&msg=deleted"); exit;
}

/* ── تعليم كمفوترة ── */
if (isset($_GET['mark_invoiced']) && $_canEdit) {
    $conn->query("UPDATE time_entries SET invoiced=1 WHERE case_id=".(int)$_GET['mark_invoiced']." AND office_id=$oid AND invoiced=0 AND billable=1");
    header("Location: time_tracking.php?tab=report&msg=saved"); exit;
}

/* ── حفظ / تعديل ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'entry') {
    $eid = (int)($_POST['id'] ?? 0);
    requirePerm('time_tracking', $eid ? 'edit' : 'add', 'time_tracking.php?msg=denied');
    $case_id = !empty($_POST['case_id']) ? (int)$_POST['case_id'] : 'NULL';
    $entry_user = $_restricted ? $uid : (!empty($_POST['user_id']) ? (int)$_POST['user_id'] : $uid);
    $date = trim($_POST['entry_date'] ?? '') !== '' ? $conn->real_escape_string($_POST['entry_date']) : date('Y-m-d');
    $hours = max(0, (float)($_POST['hours'] ?? 0));
    $rate = max(0, (float)($_POST['hourly_rate'] ?? 0));
    $billable = isset($_POST['billable']) ? 1 : 0;
    $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));

    if ($eid) {
        $scope = $_restricted ? " AND user_id=$uid" : "";
        $conn->query("UPDATE time_entries SET case_id=$case_id, entry_date='$date', hours=$hours, hourly_rate=$rate, billable=$billable, description='$desc'
            WHERE id=$eid AND office_id=$oid$scope");
    } else {
        $conn->query("INSERT INTO time_entries (office_id,case_id,user_id,entry_date,hours,hourly_rate,billable,description)
            VALUES ($oid,$case_id,$entry_user,'$date',$hours,$rate,$billable,'$desc')");
    }
    header("Location: time_tracking.php?tab=log&msg=saved"); exit;
}

/* ── بيانات مساعدة ── */
$cases_arr = [];
$ccr = $conn->query("SELECT c.id, c.case_number, c.title FROM cases c WHERE c.office_id=$oid" . caseScope('c') . " ORDER BY c.id DESC");
if ($ccr) while ($r = $ccr->fetch_assoc()) $cases_arr[$r['id']] = $r['case_number'].' — '.mb_substr($r['title'],0,40);

$users_arr = [];
if (!$_restricted) {
    $uur = $conn->query("SELECT id, full_name FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
    if ($uur) while ($r = $uur->fetch_assoc()) $users_arr[$r['id']] = $r['full_name'];
}

/* ── قائمة السجلات ── */
$where = "t.office_id=$oid";
if ($_restricted) $where .= " AND t.user_id=$uid";
$entries = $conn->query("SELECT t.*, c.case_number, c.title case_title, u.full_name user_name
    FROM time_entries t LEFT JOIN cases c ON t.case_id=c.id LEFT JOIN users u ON t.user_id=u.id
    WHERE $where ORDER BY t.entry_date DESC, t.id DESC LIMIT 300");
$entries_arr = [];
if ($entries) while ($r = $entries->fetch_assoc()) $entries_arr[] = $r;

$edit = null;
if (isset($_GET['edit'])) {
    $scope = $_restricted ? " AND user_id=$uid" : "";
    $edit = $conn->query("SELECT * FROM time_entries WHERE id=".(int)$_GET['edit']." AND office_id=$oid$scope")->fetch_assoc();
}

/* ── تقرير حسب القضية ── */
$report_by_case = [];
$rbc = $conn->query("SELECT t.case_id, c.case_number, c.title,
        SUM(t.hours) total_hours, SUM(CASE WHEN t.billable=1 THEN t.hours*t.hourly_rate ELSE 0 END) billable_value,
        SUM(CASE WHEN t.billable=1 AND t.invoiced=0 THEN t.hours*t.hourly_rate ELSE 0 END) unbilled_value,
        SUM(CASE WHEN t.billable=1 AND t.invoiced=0 THEN 1 ELSE 0 END) unbilled_count
    FROM time_entries t LEFT JOIN cases c ON t.case_id=c.id
    WHERE $where AND t.case_id IS NOT NULL GROUP BY t.case_id ORDER BY unbilled_value DESC");
if ($rbc) while ($r = $rbc->fetch_assoc()) $report_by_case[] = $r;

$total_hours_month = (float)$conn->query("SELECT IFNULL(SUM(hours),0) s FROM time_entries t WHERE $where AND MONTH(entry_date)=MONTH(CURDATE()) AND YEAR(entry_date)=YEAR(CURDATE())")->fetch_assoc()['s'];
$total_unbilled = array_sum(array_column($report_by_case, 'unbilled_value'));

$tab = in_array($_GET['tab'] ?? '', ['report']) ? 'report' : 'log';

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-stopwatch"></i> تتبّع الوقت والساعات</div>
<div class="mk-page-sub mb-3">سجّل ساعات العمل على كل قضية وتابع القيمة القابلة للفوترة</div>

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
      <div><div class="fw-bold"><?= number_format($total_hours_month,1) ?></div><div class="text-muted" style="font-size:11px">ساعات هذا الشهر</div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#fef3c7;color:#d97706"><i class="fas fa-coins"></i></div>
      <div><div class="fw-bold"><?= number_format($total_unbilled,0) ?> ر.س</div><div class="text-muted" style="font-size:11px">قيمة غير مفوترة</div></div>
    </div></div>
  </div>
</div>

<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='log'?'active':'' ?>" href="time_tracking.php?tab=log">سجل الساعات</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='report'?'active':'' ?>" href="time_tracking.php?tab=report">التقرير حسب القضية</a></li>
</ul>

<?php if ($tab === 'log'): ?>
<div class="d-flex justify-content-end mb-3">
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#entryModal" onclick="teNew()"><i class="fas fa-plus me-1"></i>تسجيل ساعات</button>
  <?php endif; ?>
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>التاريخ</th><th>القضية</th><?php if (!$_restricted): ?><th>الموظف</th><?php endif; ?><th>الساعات</th><th>السعر/ساعة</th><th>القيمة</th><th>قابلة للفوترة</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$entries_arr): ?>
<tr><td colspan="8" class="text-center text-muted py-4">لا توجد ساعات مسجّلة بعد</td></tr>
<?php else: foreach ($entries_arr as $r): ?>
<tr>
  <td style="font-size:12px"><?= dDate($r['entry_date'], false) ?></td>
  <td><?= $r['case_number'] ? e($r['case_number']).' — '.e(mb_substr($r['case_title'],0,30)) : '<span class="text-muted">بدون قضية</span>' ?></td>
  <?php if (!$_restricted): ?><td><?= e($r['user_name'] ?: '—') ?></td><?php endif; ?>
  <td><?= number_format((float)$r['hours'],2) ?></td>
  <td><?= number_format((float)$r['hourly_rate'],2) ?></td>
  <td class="fw-bold"><?= number_format((float)$r['hours']*(float)$r['hourly_rate'],2) ?> ر.س</td>
  <td><?= $r['billable'] ? '<span class="badge bg-success bg-opacity-10 text-success">نعم</span>' : '<span class="badge bg-secondary bg-opacity-10 text-secondary">لا</span>' ?>
      <?php if ($r['invoiced']): ?><br><small class="text-muted">مُفوترة</small><?php endif; ?></td>
  <td class="d-flex gap-1">
    <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='teEdit(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
    <?php if ($_canDel): ?><a href="time_tracking.php?delete=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا السجل؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php if ($tab === 'report'): ?>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>القضية</th><th>إجمالي الساعات</th><th>القيمة القابلة للفوترة</th><th>غير المفوترة</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$report_by_case): ?>
<tr><td colspan="5" class="text-center text-muted py-4">لا توجد بيانات كافية</td></tr>
<?php else: foreach ($report_by_case as $r): ?>
<tr>
  <td class="fw-semibold"><?= e($r['case_number']) ?> — <?= e(mb_substr($r['title'],0,40)) ?></td>
  <td><?= number_format((float)$r['total_hours'],2) ?></td>
  <td><?= number_format((float)$r['billable_value'],2) ?> ر.س</td>
  <td class="fw-bold text-warning"><?= number_format((float)$r['unbilled_value'],2) ?> ر.س <small class="text-muted">(<?= (int)$r['unbilled_count'] ?> سجل)</small></td>
  <td>
    <?php if ($_canEdit && (float)$r['unbilled_value'] > 0): ?>
    <a href="time_tracking.php?mark_invoiced=<?= $r['case_id'] ?>" class="btn btn-sm btn-outline-success" onclick="return confirm('تعليم كل الساعات غير المفوترة لهذه القضية كمفوترة؟ استخدمها بعد إنشاء الفاتورة يدوياً من صفحة الفواتير.')">
      <i class="fas fa-check me-1"></i>تعليم كمفوترة
    </a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<div class="alert alert-info mt-3 mb-0" style="font-size:12px"><i class="fas fa-circle-info me-1"></i>لإصدار فاتورة بقيمة الساعات، أنشئها من صفحة الفواتير بنفس القضية والمبلغ، ثم علّم الساعات هنا كمفوترة.</div>
<?php endif; ?>

<!-- Modal -->
<div class="modal fade" id="entryModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="teTitle"><i class="fas fa-stopwatch me-2"></i>تسجيل ساعات</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="entry"><input type="hidden" name="id" id="te_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">القضية</label>
        <select name="case_id" id="te_case" class="form-select"><option value="">— بدون —</option>
          <?php foreach ($cases_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if (!$_restricted): ?>
      <div class="mb-3"><label class="form-label fw-semibold">الموظف</label>
        <select name="user_id" id="te_user" class="form-select">
          <?php foreach ($users_arr as $uid2=>$un): ?><option value="<?= $uid2 ?>" <?= $uid2==$uid?'selected':'' ?>><?= e($un) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="row g-3 mb-3">
        <div class="col-md-4"><label class="form-label fw-semibold">التاريخ</label><input type="date" name="entry_date" id="te_date" class="form-control"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">عدد الساعات</label><input type="number" name="hours" id="te_hours" class="form-control" min="0" step="0.25" required></div>
        <div class="col-md-4"><label class="form-label fw-semibold">السعر/ساعة (ر.س)</label><input type="number" name="hourly_rate" id="te_rate" class="form-control" min="0" step="0.01" value="0"></div>
      </div>
      <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="billable" id="te_billable" checked><label class="form-check-label" for="te_billable">قابلة للفوترة</label></div>
      <div class="mb-1"><label class="form-label fw-semibold">وصف العمل</label><textarea name="description" id="te_desc" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<script>
function teNew(){
  document.getElementById('teTitle').innerHTML='<i class="fas fa-stopwatch me-2"></i>تسجيل ساعات';
  document.getElementById('te_id').value='';document.getElementById('te_case').value='';
  document.getElementById('te_date').value=new Date().toISOString().slice(0,10);
  document.getElementById('te_hours').value='';document.getElementById('te_rate').value='0';
  document.getElementById('te_billable').checked=true;document.getElementById('te_desc').value='';
}
function teEdit(r){
  document.getElementById('teTitle').innerHTML='<i class="fas fa-edit me-2"></i>تعديل سجل';
  document.getElementById('te_id').value=r.id;document.getElementById('te_case').value=r.case_id||'';
  document.getElementById('te_date').value=r.entry_date;document.getElementById('te_hours').value=r.hours;
  document.getElementById('te_rate').value=r.hourly_rate;document.getElementById('te_billable').checked=r.billable=='1';
  document.getElementById('te_desc').value=r.description||'';
  var us = document.getElementById('te_user'); if (us) us.value = r.user_id;
  new bootstrap.Modal(document.getElementById('entryModal')).show();
}
<?php if ($edit): ?>
document.addEventListener('DOMContentLoaded', function(){ teEdit(<?= json_encode($edit, JSON_HEX_APOS|JSON_HEX_QUOT) ?>); });
<?php endif; ?>
</script>

<?php include '../includes/office_footer.php'; ?>
