<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('session_clash','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'session_clash')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'كاشف تعارض المواعيد';
$uid = (int)($_SESSION['user_id'] ?? 0);
const SC_WINDOW_HOURS = 2; // نعتبر جلستين متعارضتين إن كانتا لنفس المحامي بفارق أقل من هذا العدد من الساعات

/* ── الجلسات القادمة مع المحامين المُسندين لكل قضية ── */
$rows = [];
$sr = $conn->query("SELECT s.id session_id, s.session_date, s.status, c.id case_id, c.case_number, c.title, c.court_name
    FROM sessions s JOIN cases c ON c.id=s.case_id
    WHERE s.office_id=$oid AND s.status='scheduled' AND s.session_date >= NOW()" . caseScope('c') . "
    ORDER BY s.session_date ASC LIMIT 500");
if ($sr) while ($x = $sr->fetch_assoc()) $rows[] = $x;

$lawyersBy = [];
if ($rows) {
    $ids = implode(',', array_unique(array_map(fn($r) => (int)$r['case_id'], $rows)));
    $ar = $conn->query("SELECT ca.case_id, u.id uid, u.full_name FROM case_assignments ca JOIN users u ON u.id=ca.user_id WHERE ca.case_id IN ($ids)");
    if ($ar) while ($x = $ar->fetch_assoc()) $lawyersBy[$x['case_id']][] = ['id' => (int)$x['uid'], 'name' => $x['full_name']];
}

/* ── كشف التعارض: جلستان لقضيتين مختلفتين، تشتركان بمحامٍ واحد على الأقل، بفارق أقل من النافذة الزمنية ── */
$clashes = [];
$n = count($rows);
for ($i = 0; $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
        if ($rows[$i]['case_id'] === $rows[$j]['case_id']) continue;
        $diffH = abs(strtotime($rows[$j]['session_date']) - strtotime($rows[$i]['session_date'])) / 3600;
        if ($diffH > SC_WINDOW_HOURS) break; // القائمة مرتبة زمنياً، لا داعي لفحص الأبعد
        $la = $lawyersBy[$rows[$i]['case_id']] ?? []; $lb = $lawyersBy[$rows[$j]['case_id']] ?? [];
        $common = array_uintersect($la, $lb, fn($x, $y) => $x['id'] <=> $y['id']);
        if ($common) $clashes[] = ['a' => $rows[$i], 'b' => $rows[$j], 'lawyers' => array_column($common, 'name')];
    }
}

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-calendar-xmark"></i> كاشف تعارض المواعيد</div>
<div class="mk-page-sub mb-3">يفحص الجلسات القادمة ويحذّرك إن كان نفس المحامي مُسنداً لجلستين متقاربتين في قضيتين مختلفتين (خلال <?= SC_WINDOW_HOURS ?> ساعة)</div>

<?php if (!$clashes): ?>
<div class="alert alert-success"><i class="fas fa-circle-check me-1"></i>لا يوجد أي تعارض مواعيد بين الجلسات القادمة حالياً.</div>
<?php else: ?>
<div class="alert alert-danger"><i class="fas fa-triangle-exclamation me-1"></i>وُجد <?= count($clashes) ?> تعارض محتمل — راجعها وأعد الجدولة إن لزم.</div>
<div class="row g-3">
<?php foreach ($clashes as $c): ?>
<div class="col-lg-6"><div class="card border-danger h-100"><div class="card-body">
  <div class="fw-bold text-danger mb-2"><i class="fas fa-user-clock me-1"></i><?= e(implode('، ', $c['lawyers'])) ?></div>
  <?php foreach (['a','b'] as $k): $s = $c[$k]; ?>
  <div class="border-bottom py-2" style="font-size:13px">
    <b><?= e(dDate($s['session_date'], true)) ?></b> — <?= e($s['case_number']) ?> <?= e(mb_substr($s['title'],0,35)) ?>
    <div class="text-muted"><?= e($s['court_name'] ?: '—') ?></div>
  </div>
  <?php endforeach; ?>
  <a href="cases.php?open_case=<?= (int)$c['a']['case_id'] ?>" class="btn btn-sm btn-outline-primary mt-2">فتح القضية الأولى</a>
  <a href="cases.php?open_case=<?= (int)$c['b']['case_id'] ?>" class="btn btn-sm btn-outline-primary mt-2">فتح القضية الثانية</a>
</div></div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
