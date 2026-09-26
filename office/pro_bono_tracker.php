<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('pro_bono_tracker','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'pro_bono_tracker')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'سجل المحاماة المجانية';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('pro_bono_tracker','add'); $_canDel = can('pro_bono_tracker','delete');

$conn->query("CREATE TABLE IF NOT EXISTS pro_bono_cases (
    id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, case_id INT NOT NULL UNIQUE,
    reason VARCHAR(255) DEFAULT NULL, estimated_value DECIMAL(10,2) DEFAULT 0,
    created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM pro_bono_cases WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header('Location: pro_bono_tracker.php?msg=deleted'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'pb') {
    requirePerm('pro_bono_tracker', 'add', 'pro_bono_tracker.php?msg=denied');
    $case_id = (int)($_POST['case_id'] ?? 0);
    $ok = $conn->query("SELECT id FROM cases WHERE id=$case_id AND office_id=$oid" . caseScope('cases'))->fetch_assoc();
    if (!$ok) { header('Location: pro_bono_tracker.php?msg=invalid'); exit; }
    $reason = $conn->real_escape_string(trim($_POST['reason'] ?? ''));
    $val = max(0, (float)($_POST['estimated_value'] ?? 0));
    $conn->query("INSERT INTO pro_bono_cases (office_id,case_id,reason,estimated_value,created_by) VALUES ($oid,$case_id,'$reason',$val,".($uid ?: 'NULL').")
        ON DUPLICATE KEY UPDATE reason='$reason', estimated_value=$val");
    header('Location: pro_bono_tracker.php?msg=saved'); exit;
}

$cases_arr = [];
$cr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid" . caseScope('cases') . " AND id NOT IN (SELECT case_id FROM pro_bono_cases WHERE office_id=$oid) ORDER BY id DESC LIMIT 300");
if ($cr) while ($x = $cr->fetch_assoc()) $cases_arr[$x['id']] = $x['case_number'] . ' — ' . mb_substr($x['title'], 0, 40);

$list = [];
$lr = $conn->query("SELECT p.*, c.case_number, c.title, c.status FROM pro_bono_cases p JOIN cases c ON c.id=p.case_id WHERE p.office_id=$oid ORDER BY p.id DESC LIMIT 200");
if ($lr) while ($x = $lr->fetch_assoc()) $list[] = $x;
$total_value = 0; foreach ($list as $x) $total_value += (float)$x['estimated_value'];

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-hand-holding-heart"></i> سجل المحاماة المجانية</div>
<div class="mk-page-sub mb-3">وثّق القضايا المجانية أو المخفَّضة للمسؤولية الاجتماعية — منفصل عن التقارير المالية العادية</div>

<?php if (isset($_GET['msg'])): ?><div class="alert alert-success alert-dismissible fade show">تم بنجاح<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="fw-bold" style="font-size:19px"><?= count($list) ?></div><div class="text-muted" style="font-size:12px">قضايا مجانية/مخفَّضة</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="fw-bold text-success" style="font-size:19px"><?= number_format($total_value,0) ?></div><div class="text-muted" style="font-size:12px">القيمة التقديرية المتبرَّع بها (ر.س)</div></div></div></div>
</div>

<?php if ($_canAdd): ?>
<div class="card mb-4"><div class="card-header fw-bold"><i class="fas fa-plus me-1"></i>تسجيل قضية</div><div class="card-body">
  <form method="POST" class="row g-2 align-items-end"><input type="hidden" name="form_type" value="pb">
    <div class="col-md-4"><label class="form-label fw-semibold">القضية *</label><select name="case_id" class="form-select" required><option value="">— اختر —</option>
      <?php foreach ($cases_arr as $cid => $cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">السبب</label><input name="reason" class="form-control" placeholder="مسؤولية اجتماعية، حالة إنسانية..."></div>
    <div class="col-md-3"><label class="form-label fw-semibold">القيمة التقديرية (ر.س)</label><input type="number" step="0.01" name="estimated_value" class="form-control"></div>
    <div class="col-md-1"><button class="btn btn-primary w-100">حفظ</button></div>
  </form>
</div></div>
<?php endif; ?>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>القضية</th><th>السبب</th><th>القيمة التقديرية</th><th></th></tr></thead>
<tbody>
<?php if (!$list): ?><tr><td colspan="4" class="text-center text-muted py-4">لا توجد قضايا مسجّلة</td></tr>
<?php else: foreach ($list as $p): ?>
<tr>
  <td class="fw-semibold"><?= e($p['case_number']) ?><br><small class="text-muted"><?= e(mb_substr($p['title'],0,40)) ?></small></td>
  <td><?= e($p['reason'] ?: '—') ?></td>
  <td><?= number_format((float)$p['estimated_value'],2) ?></td>
  <td><?php if ($_canDel): ?><a href="pro_bono_tracker.php?delete=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف السجل؟')"><i class="fas fa-trash"></i></a><?php endif; ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div>

<?php include '../includes/office_footer.php'; ?>
