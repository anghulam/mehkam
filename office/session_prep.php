<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('session_prep','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'session_prep')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'مذكرة تحضير الجلسة';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('session_prep','add');

$conn->query("CREATE TABLE IF NOT EXISTS session_prep_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    case_id INT NOT NULL,
    session_id INT DEFAULT NULL,
    points_to_raise TEXT,
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_case_session (case_id, session_id),
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حفظ نقاط الإثارة ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'points') {
    requirePerm('session_prep', 'add', 'session_prep.php?msg=denied');
    $case_id = (int)($_POST['case_id'] ?? 0);
    $session_id = !empty($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    $ok = $conn->query("SELECT id FROM cases WHERE id=$case_id AND office_id=$oid" . caseScope('cases'))->fetch_assoc();
    if (!$ok) { header('Location: session_prep.php?msg=denied'); exit; }
    $pts = $conn->real_escape_string(trim($_POST['points_to_raise'] ?? ''));
    $sidSql = $session_id ?: 0;
    $conn->query("INSERT INTO session_prep_notes (office_id,case_id,session_id,points_to_raise,updated_by) VALUES ($oid,$case_id,$sidSql,'$pts',".($uid ?: 'NULL').")
        ON DUPLICATE KEY UPDATE points_to_raise='$pts', updated_by=".($uid ?: 'NULL'));
    $redir = "session_prep.php?open=$case_id" . ($session_id ? "&session=$session_id" : '');
    header("Location: $redir&msg=saved"); exit;
}

/* ── قائمة الجلسات القادمة ── */
$upcoming = [];
$sr = $conn->query("SELECT s.id session_id, s.session_date, c.id case_id, c.case_number, c.title, c.court_name, cl.full_name client_name
    FROM sessions s JOIN cases c ON c.id=s.case_id LEFT JOIN clients cl ON cl.id=c.client_id
    WHERE s.office_id=$oid AND s.status='scheduled' AND s.session_date >= NOW()" . caseScope('c') . "
    ORDER BY s.session_date ASC LIMIT 40");
if ($sr) while ($x = $sr->fetch_assoc()) $upcoming[] = $x;

/* ── عرض مذكرة تحضير قضية محددة ── */
$open = null; $sessions = []; $prep = null;
if (isset($_GET['open'])) {
    $cid = (int)$_GET['open'];
    $open = $conn->query("SELECT c.*, cl.full_name client_name FROM cases c LEFT JOIN clients cl ON cl.id=c.client_id
        WHERE c.id=$cid AND c.office_id=$oid" . caseScope('c'))->fetch_assoc();
    if ($open) {
        $sr2 = $conn->query("SELECT * FROM sessions WHERE case_id=$cid AND office_id=$oid ORDER BY session_date DESC LIMIT 8");
        if ($sr2) while ($x = $sr2->fetch_assoc()) $sessions[] = $x;
        $sid = (int)($_GET['session'] ?? 0);
        $prep = $conn->query("SELECT * FROM session_prep_notes WHERE case_id=$cid AND session_id=" . ($sid ?: 0) . " LIMIT 1")->fetch_assoc();
    }
}

include '../includes/office_header.php';
?>
<style>@media print { .no-print{display:none!important} .mk-sidebar,.mk-header{display:none!important} .mk-main{margin:0!important} body{background:#fff!important} }</style>

<div class="mk-page-title mb-1 no-print"><i class="fas fa-file-lines"></i> مذكرة تحضير الجلسة</div>
<div class="mk-page-sub mb-3 no-print">اختر جلسة قادمة لتجهيز ملخص سريع عنها ونقاط الإثارة قبل الحضور</div>

<?php if (isset($_GET['msg']) && $_GET['msg']==='saved'): ?>
<div class="alert alert-success no-print">تم حفظ نقاط الإثارة</div>
<?php endif; ?>

<?php if (!$open): ?>
<div class="card no-print"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>التاريخ</th><th>القضية</th><th>العميل</th><th>المحكمة</th><th></th></tr></thead><tbody>
<?php if (!$upcoming): ?><tr><td colspan="5" class="text-center text-muted py-4">لا توجد جلسات قادمة</td></tr>
<?php else: foreach ($upcoming as $s): ?>
<tr><td><?= e(dDate($s['session_date'], true)) ?></td><td><?= e($s['case_number']) ?> — <?= e(mb_substr($s['title'],0,40)) ?></td>
<td><?= e($s['client_name'] ?: '—') ?></td><td><?= e($s['court_name'] ?: '—') ?></td>
<td><a href="session_prep.php?open=<?= (int)$s['case_id'] ?>&session=<?= (int)$s['session_id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-file-lines me-1"></i>تحضير</a></td></tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div>

<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <a href="session_prep.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-right me-1"></i>رجوع</a>
  <button onclick="window.print()" class="btn btn-outline-primary btn-sm"><i class="fas fa-print me-1"></i>طباعة</button>
</div>

<div class="card mb-3"><div class="card-body">
  <h4 class="mb-1"><?= e($open['case_number']) ?> — <?= e($open['title']) ?></h4>
  <div class="text-muted mb-3">العميل: <?= e($open['client_name'] ?: '—') ?> · المحكمة: <?= e($open['court_name'] ?: '—') ?> · نوع القضية: <?= e($open['case_type'] ?: '—') ?></div>
  <?php if ($open['description']): ?><div class="mb-2"><b>وصف القضية:</b> <?= nl2br(e($open['description'])) ?></div><?php endif; ?>

  <div class="fw-bold mt-3 mb-2"><i class="fas fa-clock-rotate-left me-1"></i>آخر المستجدات</div>
  <?php if (!$sessions): ?><div class="text-muted">لا توجد جلسات سابقة مسجّلة</div>
  <?php else: foreach ($sessions as $s): ?>
  <div class="border-bottom py-2" style="font-size:13.5px">
    <b><?= e(dDate($s['session_date'], true)) ?></b> — <?= statusBadge($s['status']) ?>
    <?php if ($s['result']): ?><div class="mt-1"><b>النتيجة:</b> <?= nl2br(e($s['result'])) ?></div><?php endif; ?>
    <?php if ($s['notes']): ?><div class="mt-1 text-muted"><?= nl2br(e($s['notes'])) ?></div><?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
</div></div>

<div class="card no-print">
  <div class="card-header fw-bold"><i class="fas fa-list-check me-1"></i>نقاط للإثارة في هذه الجلسة</div>
  <div class="card-body">
    <?php if ($_canAdd): ?>
    <form method="POST"><input type="hidden" name="form_type" value="points">
      <input type="hidden" name="case_id" value="<?= (int)$open['id'] ?>">
      <input type="hidden" name="session_id" value="<?= (int)($_GET['session'] ?? 0) ?>">
      <textarea name="points_to_raise" class="form-control" rows="6" placeholder="اكتب النقاط التي تريد إثارتها أو التذكير بها في الجلسة..."><?= e($prep['points_to_raise'] ?? '') ?></textarea>
      <button class="btn btn-primary mt-2"><i class="fas fa-save me-1"></i>حفظ</button>
    </form>
    <?php else: ?>
    <div style="white-space:pre-line"><?= e($prep['points_to_raise'] ?? 'لا توجد نقاط مسجّلة') ?></div>
    <?php endif; ?>
  </div>
</div>
<div class="d-none d-print-block mt-3" style="white-space:pre-line"><b>نقاط للإثارة:</b><br><?= e($prep['points_to_raise'] ?? '—') ?></div>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
