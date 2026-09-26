<?php
$page_title = 'نظرة عامة';
require_once __DIR__ . '/../includes/client_portal_header.php';

$_canInvoices = hasFeature($conn, $_cp_oid, 'has_invoices');
$_canContracts = hasFeature($conn, $_cp_oid, 'has_contracts');

$cases_count = (int)$conn->query("SELECT COUNT(*) c FROM cases WHERE client_id=$_cp_id AND office_id=$_cp_oid")->fetch_assoc()['c'];
$active_count = (int)$conn->query("SELECT COUNT(*) c FROM cases WHERE client_id=$_cp_id AND office_id=$_cp_oid AND status='active'")->fetch_assoc()['c'];

$upcoming = $conn->query("SELECT s.*, c.case_number, c.title FROM sessions s JOIN cases c ON s.case_id=c.id
    WHERE c.client_id=$_cp_id AND s.office_id=$_cp_oid AND s.status='scheduled' AND s.session_date >= NOW()
    ORDER BY s.session_date ASC LIMIT 5");
$upcoming_arr = [];
if ($upcoming) while ($r = $upcoming->fetch_assoc()) $upcoming_arr[] = $r;

$unpaid_total = 0;
if ($_canInvoices) {
    $unpaid_total = (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE client_id=$_cp_id AND office_id=$_cp_oid AND status IN ('sent','overdue') AND direction!='expense'")->fetch_assoc()['s'];
}
?>
<style>
.cd-stat{background:#fff;border:1px solid #eef1f6;border-radius:14px;padding:18px;height:100%;display:flex;align-items:center;gap:14px}
.cd-stat-ico{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;flex-shrink:0}
</style>

<div class="mb-3">
  <div class="fw-bold" style="font-size:18px">مرحباً <?= e($_cp_client['full_name']) ?> 👋</div>
  <div class="text-muted" style="font-size:13px">إليك ملخّص حالتك مع <?= e($_cp_office['name'] ?? 'المكتب') ?></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-4">
    <div class="cd-stat"><div class="cd-stat-ico" style="background:#2563eb"><i class="fas fa-gavel"></i></div>
      <div><div class="fw-bold" style="font-size:18px"><?= $cases_count ?></div><div class="text-muted" style="font-size:11px">إجمالي القضايا</div></div></div>
  </div>
  <div class="col-6 col-lg-4">
    <div class="cd-stat"><div class="cd-stat-ico" style="background:#16a34a"><i class="fas fa-circle-check"></i></div>
      <div><div class="fw-bold" style="font-size:18px"><?= $active_count ?></div><div class="text-muted" style="font-size:11px">قضايا نشطة</div></div></div>
  </div>
  <?php if ($_canInvoices): ?>
  <div class="col-6 col-lg-4">
    <div class="cd-stat"><div class="cd-stat-ico" style="background:#d97706"><i class="fas fa-coins"></i></div>
      <div><div class="fw-bold" style="font-size:18px"><?= number_format($unpaid_total,0) ?> ر.س</div><div class="text-muted" style="font-size:11px">مستحقّات غير مدفوعة</div></div></div>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><i class="fas fa-calendar-days me-2 text-primary"></i>أقرب الجلسات</div>
  <div class="card-body p-0">
    <?php if (!$upcoming_arr): ?>
    <div class="text-muted text-center py-4">لا توجد جلسات قادمة</div>
    <?php else: ?>
    <table class="table table-hover mb-0">
      <?php foreach ($upcoming_arr as $u): ?>
      <tr>
        <td class="fw-semibold"><?= e($u['case_number']) ?> — <?= e(mb_substr($u['title'],0,40)) ?></td>
        <td style="font-size:12px"><?= dDate($u['session_date'], true) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/client_portal_footer.php'; ?>
