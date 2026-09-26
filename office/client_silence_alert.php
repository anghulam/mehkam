<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('client_silence_alert','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'client_silence_alert')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'تنبيه العميل الصامت';
const CSA_DAYS = 30; // عدد الأيام بلا تواصل قبل اعتبار العميل «صامتاً»

$_hasInteractions = (bool)$conn->query("SHOW TABLES LIKE 'client_interactions'")->num_rows;

$rows = [];
$where = caseScope('c');
$sql = "SELECT cl.id client_id, cl.full_name, c.id case_id, c.case_number, c.title,
    GREATEST(
        COALESCE((SELECT MAX(s.session_date) FROM sessions s WHERE s.case_id=c.id), '2000-01-01'),
        COALESCE((SELECT MAX(i.created_at) FROM invoices i WHERE i.client_id=cl.id), '2000-01-01'),
        c.created_at"
    . ($_hasInteractions ? ", COALESCE((SELECT MAX(ci.created_at) FROM client_interactions ci WHERE ci.client_id=cl.id), '2000-01-01')" : "") . "
    ) last_touch
    FROM cases c JOIN clients cl ON cl.id=c.client_id
    WHERE c.office_id=$oid AND c.status='active'$where";
$r = $conn->query($sql);
if ($r) while ($x = $r->fetch_assoc()) {
    $days = (int)((strtotime(date('Y-m-d')) - strtotime($x['last_touch'])) / 86400);
    if ($days >= CSA_DAYS) { $x['days'] = $days; $rows[] = $x; }
}
usort($rows, fn($a, $b) => $b['days'] <=> $a['days']);

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-comment-slash"></i> تنبيه العميل الصامت</div>
<div class="mk-page-sub mb-3">قضايا نشطة لم يحدث لها أي تواصل أو تحديث (جلسة، فاتورة، ملاحظة) منذ <?= CSA_DAYS ?> يوماً أو أكثر</div>
<?php if (!$_hasInteractions): ?><div class="alert alert-info" style="font-size:12.5px"><i class="fas fa-circle-info me-1"></i>لرصد أدق، فعّل موديول "ملف الأعمال" لتسجيل مكالماتك وملاحظاتك مع العميل.</div><?php endif; ?>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>العميل</th><th>القضية</th><th>آخر تواصل</th><th>عدد الأيام</th><th></th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">لا يوجد عملاء صامتون حالياً 🎉</td></tr>
<?php else: foreach ($rows as $r): ?>
<tr>
  <td class="fw-semibold"><?= e($r['full_name']) ?></td>
  <td><?= e($r['case_number']) ?> — <?= e(mb_substr($r['title'],0,40)) ?></td>
  <td style="font-size:12px"><?= e(date('Y-m-d', strtotime($r['last_touch']))) ?></td>
  <td><span class="badge bg-<?= $r['days']>60?'danger':'warning' ?> bg-opacity-10 text-<?= $r['days']>60?'danger':'warning' ?>"><?= $r['days'] ?> يوم</span></td>
  <td><a href="client_file.php?id=<?= (int)$r['client_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-left me-1"></i>فتح ملف العميل</a></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div>

<?php include '../includes/office_footer.php'; ?>
