<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('conflict_check','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'conflict_check')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'فحص تعارض المصالح';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('conflict_check','add'); $_canDel = can('conflict_check','delete');

$conn->query("CREATE TABLE IF NOT EXISTS case_parties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    case_id INT DEFAULT NULL,
    party_name VARCHAR(200) NOT NULL,
    id_number VARCHAR(50) DEFAULT NULL,
    role ENUM('opponent','other') DEFAULT 'opponent',
    notes VARCHAR(255) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id), INDEX idx_name (party_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM case_parties WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header('Location: conflict_check.php?msg=deleted'); exit;
}

/* ── تسجيل طرف (خصم) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'party') {
    requirePerm('conflict_check', 'add', 'conflict_check.php?msg=denied');
    $name = trim($_POST['party_name'] ?? '');
    if ($name === '') { header('Location: conflict_check.php?msg=invalid'); exit; }
    $ne = $conn->real_escape_string($name);
    $idn = $conn->real_escape_string(trim($_POST['id_number'] ?? ''));
    $case_id = !empty($_POST['case_id']) ? (int)$_POST['case_id'] : 'NULL';
    if ($case_id !== 'NULL' && !$conn->query("SELECT id FROM cases WHERE id=$case_id AND office_id=$oid" . caseScope('cases'))->num_rows) $case_id = 'NULL';
    $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    $conn->query("INSERT INTO case_parties (office_id,case_id,party_name,id_number,role,notes,created_by) VALUES ($oid,$case_id,'$ne','$idn','opponent','$notes',".($uid ?: 'NULL').")");
    header('Location: conflict_check.php?msg=saved'); exit;
}

/* ── فحص التعارض ── */
$searchQ = trim($_GET['q'] ?? '');
$clientHits = []; $partyHits = [];
if ($searchQ !== '') {
    $qe = $conn->real_escape_string($searchQ);
    $cr = $conn->query("SELECT id, full_name, id_number, phone FROM clients WHERE office_id=$oid AND (full_name LIKE '%$qe%' OR id_number LIKE '%$qe%') LIMIT 30");
    if ($cr) while ($x = $cr->fetch_assoc()) $clientHits[] = $x;
    $pr = $conn->query("SELECT p.*, c.case_number, c.title case_title FROM case_parties p LEFT JOIN cases c ON c.id=p.case_id
        WHERE p.office_id=$oid AND (p.party_name LIKE '%$qe%' OR p.id_number LIKE '%$qe%') ORDER BY p.id DESC LIMIT 30");
    if ($pr) while ($x = $pr->fetch_assoc()) $partyHits[] = $x;
}
$hasConflict = $searchQ !== '' && (count($clientHits) > 0 || count($partyHits) > 0);

$cases_arr = [];
$ccr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid" . caseScope('cases') . " ORDER BY id DESC LIMIT 300");
if ($ccr) while ($x = $ccr->fetch_assoc()) $cases_arr[$x['id']] = $x['case_number'] . ' — ' . mb_substr($x['title'], 0, 40);

$recent = [];
$rr = $conn->query("SELECT p.*, c.case_number FROM case_parties p LEFT JOIN cases c ON c.id=p.case_id WHERE p.office_id=$oid ORDER BY p.id DESC LIMIT 20");
if ($rr) while ($x = $rr->fetch_assoc()) $recent[] = $x;

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-user-shield"></i> فحص تعارض المصالح</div>
<div class="mk-page-sub mb-3">قبل قبول قضية جديدة، تحقّق هل الطرف الآخر عميل حالي أو سابق للمكتب، أو خصم في قضية أخرى</div>

<?php if (isset($_GET['msg'])):
  $mm = ['saved'=>['success','تم تسجيل الطرف بنجاح'],'deleted'=>['success','تم الحذف'],'invalid'=>['danger','أدخل اسم الطرف'],'denied'=>['danger','ليست لديك صلاحية']];
  $m = $mm[$_GET['msg']] ?? ['success','تم']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body">
  <form method="GET" class="d-flex gap-2">
    <input type="text" name="q" class="form-control" placeholder="اكتب اسم الشخص أو الشركة أو رقم الهوية/السجل للفحص..." value="<?= e($searchQ) ?>" autofocus>
    <button class="btn btn-primary"><i class="fas fa-magnifying-glass me-1"></i>فحص</button>
    <?php if ($searchQ !== ''): ?><a href="conflict_check.php" class="btn btn-outline-secondary">مسح</a><?php endif; ?>
  </form>
</div></div>

<?php if ($searchQ !== ''): ?>
<div class="alert alert-<?= $hasConflict ? 'danger' : 'success' ?> d-flex align-items-center gap-2 mb-3">
  <i class="fas fa-<?= $hasConflict ? 'triangle-exclamation' : 'circle-check' ?> fa-lg"></i>
  <div><?= $hasConflict ? '<b>تحذير:</b> وُجدت نتائج مطابقة لـ «'.e($searchQ).'» — راجعها قبل قبول القضية' : 'لا توجد أي مطابقة لـ «'.e($searchQ).'» في عملاء المكتب أو سجل الأطراف' ?></div>
</div>

<?php if ($clientHits): ?>
<div class="card mb-3"><div class="card-header fw-bold"><i class="fas fa-address-book me-1 text-primary"></i>مطابقة في عملاء المكتب</div>
  <div class="list-group list-group-flush">
  <?php foreach ($clientHits as $c): ?>
  <div class="list-group-item d-flex justify-content-between align-items-center">
    <div><b><?= e($c['full_name']) ?></b> <span class="text-muted" style="font-size:12px"><?= e($c['id_number'] ?: '') ?></span></div>
    <a href="clients.php?open=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary">فتح ملف العميل</a>
  </div>
  <?php endforeach; ?>
  </div></div>
<?php endif; ?>

<?php if ($partyHits): ?>
<div class="card mb-3"><div class="card-header fw-bold"><i class="fas fa-user-group me-1 text-danger"></i>مطابقة كخصم في قضايا سابقة</div>
  <div class="list-group list-group-flush">
  <?php foreach ($partyHits as $p): ?>
  <div class="list-group-item">
    <b><?= e($p['party_name']) ?></b> <span class="text-muted" style="font-size:12px"><?= e($p['id_number'] ?: '') ?></span>
    <?php if ($p['case_number']): ?><div style="font-size:12px" class="text-muted">في القضية: <?= e($p['case_number']) ?> — <?= e(mb_substr($p['case_title'] ?? '', 0, 50)) ?></div><?php endif; ?>
    <?php if ($p['notes']): ?><div style="font-size:12px" class="text-muted"><?= e($p['notes']) ?></div><?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div></div>
<?php endif; ?>
<?php endif; ?>

<div class="row g-3">
  <?php if ($_canAdd): ?>
  <div class="col-lg-5">
    <div class="card"><div class="card-header fw-bold"><i class="fas fa-plus me-1"></i>تسجيل طرف/خصم جديد</div><div class="card-body">
      <form method="POST"><input type="hidden" name="form_type" value="party">
        <div class="mb-2"><label class="form-label fw-semibold">اسم الطرف *</label><input name="party_name" class="form-control" required></div>
        <div class="mb-2"><label class="form-label fw-semibold">رقم الهوية/السجل</label><input name="id_number" class="form-control"></div>
        <div class="mb-2"><label class="form-label fw-semibold">مرتبط بالقضية (اختياري)</label>
          <select name="case_id" class="form-select"><option value="">— بدون —</option>
            <?php foreach ($cases_arr as $cid => $cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?></select></div>
        <div class="mb-2"><label class="form-label fw-semibold">ملاحظات</label><input name="notes" class="form-control"></div>
        <button class="btn btn-primary w-100"><i class="fas fa-save me-1"></i>حفظ</button>
      </form>
    </div></div>
  </div>
  <?php endif; ?>
  <div class="col-lg-<?= $_canAdd ? 7 : 12 ?>">
    <div class="card"><div class="card-header fw-bold"><i class="fas fa-clock-rotate-left me-1"></i>آخر الأطراف المسجّلة</div>
      <div class="table-responsive"><table class="table table-sm mb-0" style="font-size:13px">
      <thead><tr><th>الاسم</th><th>الهوية/السجل</th><th>القضية</th><th></th></tr></thead><tbody>
      <?php if (!$recent): ?><tr><td colspan="4" class="text-center text-muted py-4">لا يوجد سجل بعد</td></tr>
      <?php else: foreach ($recent as $p): ?>
      <tr><td><?= e($p['party_name']) ?></td><td><?= e($p['id_number'] ?: '—') ?></td><td><?= e($p['case_number'] ?: '—') ?></td>
        <td><?php if ($_canDel): ?><a href="conflict_check.php?delete=<?= (int)$p['id'] ?>" class="text-danger" onclick="return confirm('حذف؟')"><i class="fas fa-trash"></i></a><?php endif; ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody></table></div>
    </div>
  </div>
</div>

<?php include '../includes/office_footer.php'; ?>
