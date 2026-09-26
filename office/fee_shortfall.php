<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('fee_shortfall','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'fee_shortfall')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'متابعة الأتعاب الناقصة';

$rows = [];
$cr = $conn->query("SELECT c.id, c.case_number, c.title, c.fees, c.paid_amount, cl.full_name client_name
    FROM cases c LEFT JOIN clients cl ON cl.id=c.client_id
    WHERE c.office_id=$oid AND c.fees > c.paid_amount AND c.status='active'" . caseScope('c') . "
    ORDER BY (c.fees - c.paid_amount) DESC LIMIT 300");
if ($cr) while ($x = $cr->fetch_assoc()) $rows[] = $x;

$total_shortfall = 0; foreach ($rows as $r) $total_shortfall += (float)$r['fees'] - (float)$r['paid_amount'];

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-money-bill-trend-up"></i> متابعة الأتعاب الناقصة</div>
<div class="mk-page-sub mb-3">القضايا النشطة التي لم تُحصَّل أتعابها كاملة بعد، مرتبة بالأكبر عجزاً</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="fw-bold" style="font-size:19px"><?= count($rows) ?></div><div class="text-muted" style="font-size:12px">قضايا فيها عجز تحصيل</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="fw-bold text-danger" style="font-size:19px"><?= number_format($total_shortfall,0) ?></div><div class="text-muted" style="font-size:12px">إجمالي العجز (ر.س)</div></div></div></div>
</div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>القضية</th><th>العميل</th><th>الأتعاب المتفق عليها</th><th>المحصَّل</th><th>العجز</th><th></th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">لا يوجد عجز تحصيل حالياً 🎉</td></tr>
<?php else: foreach ($rows as $r): $gap = (float)$r['fees'] - (float)$r['paid_amount']; ?>
<tr>
  <td class="fw-semibold"><?= e($r['case_number']) ?><br><small class="text-muted"><?= e(mb_substr($r['title'],0,40)) ?></small></td>
  <td><?= e($r['client_name'] ?: '—') ?></td>
  <td><?= number_format((float)$r['fees'],2) ?></td>
  <td><?= number_format((float)$r['paid_amount'],2) ?></td>
  <td class="fw-bold text-danger"><?= number_format($gap,2) ?></td>
  <td><a href="invoices.php?case_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-invoice-dollar me-1"></i>الفواتير</a></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div>

<?php include '../includes/office_footer.php'; ?>
