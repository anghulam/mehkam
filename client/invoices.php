<?php
$page_title = 'فواتيري';
require_once __DIR__ . '/../includes/client_portal_header.php';

if (!hasFeature($conn, $_cp_oid, 'has_invoices')) {
    echo '<div class="alert alert-info">هذه الميزة غير متاحة حالياً من مكتبك.</div>';
    require_once __DIR__ . '/../includes/client_portal_footer.php';
    exit;
}

$invoices = [];
$ir = $conn->query("SELECT * FROM invoices WHERE client_id=$_cp_id AND office_id=$_cp_oid ORDER BY created_at DESC");
if ($ir) while ($r = $ir->fetch_assoc()) $invoices[] = $r;
?>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>رقم الفاتورة</th><th>العنوان</th><th>الإجمالي</th><th>الحالة</th><th>التاريخ</th><th></th></tr></thead>
<tbody>
<?php if (!$invoices): ?>
<tr><td colspan="6" class="text-center text-muted py-4">لا توجد فواتير حالياً</td></tr>
<?php else: foreach ($invoices as $iv): ?>
<tr>
  <td class="font-monospace"><?= e($iv['invoice_number']) ?></td>
  <td><?= e($iv['title']) ?></td>
  <td class="fw-bold"><?= number_format((float)$iv['total'],2) ?> ر.س</td>
  <td><?= statusBadge($iv['status']) ?></td>
  <td style="font-size:12px"><?= $iv['issue_date'] ? dDate($iv['issue_date']) : '—' ?></td>
  <td>
    <?php if ($iv['public_token']): ?>
    <a href="../public/invoice_view.php?t=<?= e($iv['public_token']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-pdf me-1"></i>عرض</a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>

<?php require_once __DIR__ . '/../includes/client_portal_footer.php'; ?>
