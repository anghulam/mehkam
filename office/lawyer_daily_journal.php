<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('lawyer_daily_journal','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'lawyer_daily_journal')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'دفتر يوميات المحامي';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('lawyer_daily_journal','add');
$_canApprove = can('lawyer_daily_journal','approve'); // مستوى المدير: يرى تقرير الفريق الأسبوعي كاملاً

$conn->query("CREATE TABLE IF NOT EXISTS daily_journal (
    id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, user_id INT NOT NULL,
    entry_date DATE NOT NULL, notes VARCHAR(500) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_date (user_id, entry_date), INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'entry') {
    requirePerm('lawyer_daily_journal', 'add', 'lawyer_daily_journal.php?msg=denied');
    $notes = trim($_POST['notes'] ?? '');
    $date = date('Y-m-d');
    if ($notes !== '') {
        $ne = $conn->real_escape_string(mb_substr($notes, 0, 500));
        $conn->query("INSERT INTO daily_journal (office_id,user_id,entry_date,notes) VALUES ($oid,$uid,'$date','$ne') ON DUPLICATE KEY UPDATE notes='$ne'");
    }
    header('Location: lawyer_daily_journal.php?msg=saved'); exit;
}

$today = $conn->query("SELECT * FROM daily_journal WHERE office_id=$oid AND user_id=$uid AND entry_date=CURDATE() LIMIT 1")->fetch_assoc();

$scope = $_canApprove ? '' : " AND j.user_id=$uid";
$week = [];
$wr = $conn->query("SELECT j.*, u.full_name FROM daily_journal j LEFT JOIN users u ON u.id=j.user_id
    WHERE j.office_id=$oid AND j.entry_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)$scope ORDER BY j.entry_date DESC, j.user_id LIMIT 200");
if ($wr) while ($x = $wr->fetch_assoc()) $week[] = $x;

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-book-journal-whills"></i> دفتر يوميات المحامي</div>
<div class="mk-page-sub mb-3">سطران عن أهم ما أنجزته اليوم — يتجمّع بتقرير أسبوعي تلقائي</div>

<?php if (isset($_GET['msg'])): ?><div class="alert alert-success alert-dismissible fade show">تم بنجاح<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<?php if ($_canAdd): ?>
<div class="card mb-4"><div class="card-header fw-bold"><i class="fas fa-pen me-1"></i>يوميتك اليوم (<?= date('Y-m-d') ?>)</div><div class="card-body">
  <form method="POST"><input type="hidden" name="form_type" value="entry">
    <textarea name="notes" class="form-control" rows="2" placeholder="ماذا أنجزت اليوم؟" required><?= e($today['notes'] ?? '') ?></textarea>
    <button class="btn btn-primary mt-2"><i class="fas fa-save me-1"></i><?= $today ? 'تحديث' : 'حفظ' ?></button>
  </form>
</div></div>
<?php endif; ?>

<div class="fw-bold mb-2"><i class="fas fa-calendar-week me-1"></i>تقرير آخر 7 أيام</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><?php if ($_canApprove): ?><th>المحامي</th><?php endif; ?><th>التاريخ</th><th>ماذا أُنجز</th></tr></thead>
<tbody>
<?php if (!$week): ?><tr><td colspan="3" class="text-center text-muted py-4">لا توجد قيود بعد</td></tr>
<?php else: foreach ($week as $w): ?>
<tr><?php if ($_canApprove): ?><td class="fw-semibold"><?= e($w['full_name'] ?: '—') ?></td><?php endif; ?><td style="font-size:12px"><?= e($w['entry_date']) ?></td><td><?= e($w['notes']) ?></td></tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div>

<?php include '../includes/office_footer.php'; ?>
