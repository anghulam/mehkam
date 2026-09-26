<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('case_closure_review','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'case_closure_review')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'تقييم القضية بعد إغلاقها';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('case_closure_review','add'); $_canEdit = can('case_closure_review','edit');

$conn->query("CREATE TABLE IF NOT EXISTS case_closure_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    case_id INT NOT NULL UNIQUE,
    outcome VARCHAR(50) DEFAULT NULL,
    reason TEXT,
    lessons TEXT,
    rating TINYINT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حفظ تقييم ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'review') {
    requirePerm('case_closure_review', 'add', 'case_closure_review.php?msg=denied');
    $case_id = (int)($_POST['case_id'] ?? 0);
    $ok = $conn->query("SELECT id, status FROM cases WHERE id=$case_id AND office_id=$oid" . caseScope('cases'))->fetch_assoc();
    if (!$ok) { header('Location: case_closure_review.php?msg=invalid'); exit; }
    $outcome = $conn->real_escape_string(trim($_POST['outcome'] ?? ''));
    $reason = $conn->real_escape_string(trim($_POST['reason'] ?? ''));
    $lessons = $conn->real_escape_string(trim($_POST['lessons'] ?? ''));
    $rating = max(1, min(5, (int)($_POST['rating'] ?? 3)));
    $conn->query("INSERT INTO case_closure_reviews (office_id,case_id,outcome,reason,lessons,rating,created_by) VALUES ($oid,$case_id,'$outcome','$reason','$lessons',$rating,".($uid ?: 'NULL').")
        ON DUPLICATE KEY UPDATE outcome='$outcome', reason='$reason', lessons='$lessons', rating=$rating");
    header('Location: case_closure_review.php?msg=saved'); exit;
}

/* ── قضايا مغلقة بلا تقييم ── */
$pending = [];
$pr = $conn->query("SELECT c.id, c.case_number, c.title, c.status, c.case_type FROM cases c
    WHERE c.office_id=$oid AND c.status IN ('won','lost','settled','closed')" . caseScope('c') . "
    AND c.id NOT IN (SELECT case_id FROM case_closure_reviews WHERE office_id=$oid)
    ORDER BY c.id DESC LIMIT 100");
if ($pr) while ($x = $pr->fetch_assoc()) $pending[] = $x;

/* ── أرشيف التقييمات (مرجع قابل للبحث) ── */
$searchQ = trim($_GET['q'] ?? '');
$where = "r.office_id=$oid";
if ($searchQ !== '') { $qe = $conn->real_escape_string($searchQ); $where .= " AND (c.title LIKE '%$qe%' OR r.lessons LIKE '%$qe%' OR c.case_type LIKE '%$qe%')"; }
$reviews = [];
$rr = $conn->query("SELECT r.*, c.case_number, c.title, c.case_type FROM case_closure_reviews r JOIN cases c ON c.id=r.case_id WHERE $where ORDER BY r.id DESC LIMIT 200");
if ($rr) while ($x = $rr->fetch_assoc()) $reviews[] = $x;

$_outMap = ['won'=>'كسب كامل','partial'=>'كسب جزئي','lost'=>'خسارة','settled'=>'تسوية/صلح','withdrawn'=>'سحب/إنهاء'];

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-clipboard-check"></i> تقييم القضية بعد إغلاقها</div>
<div class="mk-page-sub mb-3">وثّق نتيجة كل قضية والدروس المستفادة — يبني أرشيفاً مرجعياً يستفيد منه فريقك في القضايا المشابهة مستقبلاً</div>

<?php if (isset($_GET['msg'])):
  $mm = ['saved'=>['success','تم حفظ التقييم'],'invalid'=>['danger','قضية غير صحيحة'],'denied'=>['danger','ليست لديك صلاحية']];
  $m = $mm[$_GET['msg']] ?? ['success','تم']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($pending && $_canAdd): ?>
<div class="card mb-4 border-warning"><div class="card-header" style="background:#fffbeb"><i class="fas fa-hourglass-half me-1 text-warning"></i>قضايا مغلقة بانتظار التقييم (<?= count($pending) ?>)</div>
  <div class="list-group list-group-flush">
  <?php foreach ($pending as $p): ?>
  <div class="list-group-item d-flex justify-content-between align-items-center">
    <div><b><?= e($p['case_number']) ?></b> <?= e(mb_substr($p['title'],0,50)) ?> <?= statusBadge($p['status']) ?></div>
    <button class="btn btn-sm btn-primary" onclick='rvOpen(<?= json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-plus me-1"></i>تقييم</button>
  </div>
  <?php endforeach; ?>
  </div></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="fw-bold"><i class="fas fa-book-open me-1"></i>أرشيف التقييمات</div>
  <form method="GET" class="d-flex gap-2"><input name="q" class="form-control form-control-sm" placeholder="ابحث بنوع القضية أو الدرس المستفاد..." value="<?= e($searchQ) ?>"><button class="btn btn-sm btn-outline-primary">بحث</button></form>
</div>

<div class="row g-3">
<?php if (!$reviews): ?><div class="col-12"><div class="text-muted text-center py-5">لا توجد تقييمات بعد</div></div>
<?php else: foreach ($reviews as $r): ?>
<div class="col-lg-6"><div class="card h-100"><div class="card-body">
  <div class="d-flex justify-content-between align-items-start">
    <div><b><?= e($r['case_number']) ?></b> <span class="text-muted" style="font-size:12px"><?= e($r['case_type'] ?: '') ?></span></div>
    <div class="text-warning"><?php for($i=0;$i<5;$i++): ?><i class="fa<?= $i<$r['rating']?'s':'r' ?> fa-star"></i><?php endfor; ?></div>
  </div>
  <div class="text-muted" style="font-size:13px"><?= e(mb_substr($r['title'],0,60)) ?></div>
  <span class="badge bg-info bg-opacity-10 text-info mt-2"><?= e($_outMap[$r['outcome']] ?? $r['outcome'] ?: '—') ?></span>
  <?php if ($r['reason']): ?><div class="mt-2" style="font-size:13px"><b>السبب:</b> <?= e(mb_substr($r['reason'],0,150)) ?></div><?php endif; ?>
  <?php if ($r['lessons']): ?><div class="mt-1" style="font-size:13px"><b>الدروس المستفادة:</b> <?= e(mb_substr($r['lessons'],0,200)) ?></div><?php endif; ?>
  <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary mt-2" onclick='rvOpen(<?= json_encode(["id"=>$r["case_id"],"case_number"=>$r["case_number"],"title"=>$r["title"],"outcome"=>$r["outcome"],"reason"=>$r["reason"],"lessons"=>$r["lessons"],"rating"=>$r["rating"]], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit me-1"></i>تعديل</button><?php endif; ?>
</div></div></div>
<?php endforeach; endif; ?>
</div>

<div class="modal fade" id="rvModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="rvTitle">تقييم قضية</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="review"><input type="hidden" name="case_id" id="rv_case">
    <div class="modal-body">
      <div class="mb-2"><label class="form-label fw-semibold">النتيجة</label><select name="outcome" id="rv_outcome" class="form-select">
        <?php foreach ($_outMap as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
      <div class="mb-2"><label class="form-label fw-semibold">التقييم العام</label><select name="rating" id="rv_rating" class="form-select">
        <?php for ($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= str_repeat('★',$i) ?></option><?php endfor; ?></select></div>
      <div class="mb-2"><label class="form-label fw-semibold">سبب الكسب/الخسارة</label><textarea name="reason" id="rv_reason" class="form-control" rows="3"></textarea></div>
      <div class="mb-1"><label class="form-label fw-semibold">الدروس المستفادة</label><textarea name="lessons" id="rv_lessons" class="form-control" rows="3" placeholder="ما الذي تتعلمه لقضية مشابهة لاحقاً؟"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<script>
function rvOpen(p){
  document.getElementById('rvTitle').innerText='تقييم: '+p.case_number;
  document.getElementById('rv_case').value=p.id;
  document.getElementById('rv_outcome').value=p.outcome||(p.status==='won'?'won':(p.status==='lost'?'lost':(p.status==='settled'?'settled':'')));
  document.getElementById('rv_rating').value=p.rating||3;
  document.getElementById('rv_reason').value=p.reason||'';
  document.getElementById('rv_lessons').value=p.lessons||'';
  new bootstrap.Modal(document.getElementById('rvModal')).show();
}
</script>

<?php include '../includes/office_footer.php'; ?>
