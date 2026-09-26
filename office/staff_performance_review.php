<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('staff_performance_review','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'staff_performance_review')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'تقييم أداء الموظفين الدوري';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canApprove = can('staff_performance_review','approve'); // مستوى المدير: يُنشئ التقييمات ويراها لكل الفريق

$conn->query("CREATE TABLE IF NOT EXISTS performance_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, user_id INT NOT NULL, period_label VARCHAR(50) NOT NULL,
    cases_completed INT DEFAULT 0, punctuality_score TINYINT DEFAULT 3, rating TINYINT DEFAULT 3, notes TEXT,
    reviewed_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (isset($_GET['delete']) && $_canApprove) {
    $conn->query("DELETE FROM performance_reviews WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header('Location: staff_performance_review.php?msg=deleted'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'review') {
    if (!$_canApprove) { header('Location: staff_performance_review.php?msg=denied'); exit; }
    $target = (int)($_POST['user_id'] ?? 0);
    $ok = $conn->query("SELECT id FROM users WHERE id=$target AND office_id=$oid")->fetch_assoc();
    $period = trim($_POST['period_label'] ?? '');
    if (!$ok || $period === '') { header('Location: staff_performance_review.php?msg=invalid'); exit; }
    $pe = $conn->real_escape_string($period);
    $cc = max(0, (int)($_POST['cases_completed'] ?? 0));
    $pu = max(1, min(5, (int)($_POST['punctuality_score'] ?? 3)));
    $rt = max(1, min(5, (int)($_POST['rating'] ?? 3)));
    $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    $conn->query("INSERT INTO performance_reviews (office_id,user_id,period_label,cases_completed,punctuality_score,rating,notes,reviewed_by) VALUES ($oid,$target,'$pe',$cc,$pu,$rt,'$notes',".($uid ?: 'NULL').")");
    header('Location: staff_performance_review.php?msg=saved'); exit;
}

$officeUsers = [];
$ur = $conn->query("SELECT id, full_name FROM users WHERE office_id=$oid AND is_active=1 AND role<>'office_owner' ORDER BY full_name");
if ($ur) while ($u = $ur->fetch_assoc()) $officeUsers[$u['id']] = $u['full_name'];

$scope = $_canApprove ? '' : " AND r.user_id=$uid";
$list = [];
$lr = $conn->query("SELECT r.*, u.full_name FROM performance_reviews r LEFT JOIN users u ON u.id=r.user_id WHERE r.office_id=$oid$scope ORDER BY r.id DESC LIMIT 100");
if ($lr) while ($x = $lr->fetch_assoc()) $list[] = $x;

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-chart-simple"></i> تقييم أداء الموظفين الدوري</div>
<div class="mk-page-sub mb-3">تقييم ربع سنوي لكل موظف بمعايير محددة — أرشيف يساعد في القرارات الإدارية</div>

<?php if (isset($_GET['msg'])): ?><div class="alert alert-success alert-dismissible fade show">تم بنجاح<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<?php if ($_canApprove): ?>
<div class="card mb-4"><div class="card-header fw-bold"><i class="fas fa-plus me-1"></i>تقييم جديد</div><div class="card-body">
  <form method="POST"><input type="hidden" name="form_type" value="review">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label fw-semibold">الموظف *</label><select name="user_id" class="form-select" required><option value="">— اختر —</option>
        <?php foreach ($officeUsers as $uid2 => $un): ?><option value="<?= $uid2 ?>"><?= e($un) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label fw-semibold">الفترة *</label><input name="period_label" class="form-control" placeholder="مثال: الربع الأول 2026" required></div>
      <div class="col-md-4"><label class="form-label fw-semibold">قضايا منجزة بالفترة</label><input type="number" name="cases_completed" class="form-control" min="0"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">الالتزام بالمواعيد</label><select name="punctuality_score" class="form-select"><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= str_repeat('★',$i) ?></option><?php endfor; ?></select></div>
      <div class="col-md-4"><label class="form-label fw-semibold">التقييم العام</label><select name="rating" class="form-select"><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= str_repeat('★',$i) ?></option><?php endfor; ?></select></div>
      <div class="col-12"><label class="form-label fw-semibold">ملاحظات</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
    </div>
    <button class="btn btn-primary mt-3"><i class="fas fa-save me-1"></i>حفظ التقييم</button>
  </form>
</div></div>
<?php endif; ?>

<div class="row g-3">
<?php if (!$list): ?><div class="col-12"><div class="text-muted text-center py-5">لا توجد تقييمات بعد</div></div>
<?php else: foreach ($list as $r): ?>
<div class="col-lg-6"><div class="card h-100"><div class="card-body">
  <div class="d-flex justify-content-between align-items-start">
    <div><b><?= e($r['full_name'] ?: '—') ?></b><div class="text-muted" style="font-size:12px"><?= e($r['period_label']) ?></div></div>
    <div class="text-warning"><?php for($i=0;$i<5;$i++): ?><i class="fa<?= $i<$r['rating']?'s':'r' ?> fa-star"></i><?php endfor; ?></div>
  </div>
  <div class="mt-2" style="font-size:12.5px">
    <span class="badge bg-light text-dark"><i class="fas fa-gavel me-1"></i><?= (int)$r['cases_completed'] ?> قضية منجزة</span>
    <span class="badge bg-light text-dark"><i class="fas fa-clock me-1"></i>التزام: <?= str_repeat('★',(int)$r['punctuality_score']) ?></span>
  </div>
  <?php if ($r['notes']): ?><div class="mt-2" style="font-size:13px"><?= e($r['notes']) ?></div><?php endif; ?>
  <?php if ($_canApprove): ?><a href="staff_performance_review.php?delete=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-danger mt-2" onclick="return confirm('حذف؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
</div></div></div>
<?php endforeach; endif; ?>
</div>

<?php include '../includes/office_footer.php'; ?>
