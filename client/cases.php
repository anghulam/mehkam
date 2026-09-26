<?php
$page_title = 'قضاياي';
require_once __DIR__ . '/../includes/client_portal_header.php';

$cases = [];
$cr = $conn->query("SELECT * FROM cases WHERE client_id=$_cp_id AND office_id=$_cp_oid ORDER BY id DESC");
if ($cr) while ($r = $cr->fetch_assoc()) $cases[] = $r;

$open_case = null; $open_sessions = [];
if (isset($_GET['id'])) {
    $open_case = $conn->query("SELECT * FROM cases WHERE id=".(int)$_GET['id']." AND client_id=$_cp_id AND office_id=$_cp_oid LIMIT 1")->fetch_assoc();
    if ($open_case) {
        $sr = $conn->query("SELECT * FROM sessions WHERE case_id=".(int)$open_case['id']." ORDER BY session_date DESC");
        if ($sr) while ($r = $sr->fetch_assoc()) $open_sessions[] = $r;
    }
}
?>
<?php if ($open_case): ?>
<a href="cases.php" class="btn btn-sm btn-outline-secondary mb-3"><i class="fas fa-arrow-right me-1"></i>رجوع لقضاياي</a>
<div class="card mb-3">
  <div class="card-header"><i class="fas fa-gavel me-2 text-primary"></i><?= e($open_case['case_number']) ?> — <?= e($open_case['title']) ?></div>
  <div class="card-body">
    <div class="row g-2" style="font-size:13px">
      <div class="col-md-4"><span class="text-muted">النوع:</span> <?= e($open_case['case_type'] ?: '—') ?></div>
      <div class="col-md-4"><span class="text-muted">المحكمة:</span> <?= e($open_case['court_name'] ?: '—') ?></div>
      <div class="col-md-4"><span class="text-muted">الحالة:</span> <?= statusBadge($open_case['status']) ?></div>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header"><i class="fas fa-calendar-days me-2 text-info"></i>الجلسات</div>
  <div class="card-body p-0">
    <?php if (!$open_sessions): ?>
    <div class="text-muted text-center py-4">لا توجد جلسات مسجّلة</div>
    <?php else: ?>
    <table class="table table-hover mb-0">
      <thead><tr><th>التاريخ</th><th>الحالة</th><th>النتيجة</th></tr></thead>
      <tbody>
      <?php foreach ($open_sessions as $s): ?>
      <tr>
        <td style="font-size:12px"><?= dDate($s['session_date'], true) ?></td>
        <td><?= statusBadge($s['status']) ?></td>
        <td style="font-size:12px"><?= e(mb_substr($s['result'] ?? '',0,80)) ?: '—' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>رقم القضية</th><th>العنوان</th><th>النوع</th><th>الحالة</th><th></th></tr></thead>
<tbody>
<?php if (!$cases): ?>
<tr><td colspan="5" class="text-center text-muted py-4">لا توجد قضايا مسجّلة حالياً</td></tr>
<?php else: foreach ($cases as $c): ?>
<tr>
  <td class="font-monospace"><?= e($c['case_number']) ?></td>
  <td><?= e($c['title']) ?></td>
  <td><?= e($c['case_type'] ?: '—') ?></td>
  <td><?= statusBadge($c['status']) ?></td>
  <td><a href="cases.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary">عرض التفاصيل</a></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/client_portal_footer.php'; ?>
