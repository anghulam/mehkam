<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('global_search','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'global_search')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'البحث الشامل الموحّد';
$_hasFin = hasFeature($conn, $oid, 'has_finance');

$term = trim($_GET['q'] ?? '');
$results = ['clients' => [], 'cases' => [], 'contracts' => [], 'invoices' => []];

if (mb_strlen($term) >= 2) {
    $qe = $conn->real_escape_string($term);

    if (can('clients','view')) {
        $r = $conn->query("SELECT id, full_name, phone, id_number FROM clients WHERE office_id=$oid AND (full_name LIKE '%$qe%' OR phone LIKE '%$qe%' OR id_number LIKE '%$qe%') LIMIT 15");
        if ($r) while ($x = $r->fetch_assoc()) $results['clients'][] = $x;
    }
    if (can('cases','view')) {
        $r = $conn->query("SELECT id, case_number, title, status FROM cases WHERE office_id=$oid AND (case_number LIKE '%$qe%' OR title LIKE '%$qe%')" . caseScope('cases') . " LIMIT 15");
        if ($r) while ($x = $r->fetch_assoc()) $results['cases'][] = $x;
    }
    if (can('contracts','view')) {
        $r = $conn->query("SELECT id, contract_number, title, status FROM contracts WHERE office_id=$oid AND (contract_number LIKE '%$qe%' OR title LIKE '%$qe%') LIMIT 15");
        if ($r) while ($x = $r->fetch_assoc()) $results['contracts'][] = $x;
    }
    if ($_hasFin && can('invoices','view')) {
        $r = $conn->query("SELECT id, invoice_number, title, total FROM invoices WHERE office_id=$oid AND (invoice_number LIKE '%$qe%' OR title LIKE '%$qe%') LIMIT 15");
        if ($r) while ($x = $r->fetch_assoc()) $results['invoices'][] = $x;
    }
}
$total = array_sum(array_map('count', $results));

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-magnifying-glass"></i> البحث الشامل الموحّد</div>
<div class="mk-page-sub mb-3">دوّر في كل شيء بالمكتب من مكان واحد — عملاء، قضايا، عقود، وفواتير</div>

<div class="card mb-4"><div class="card-body">
  <form method="GET" class="d-flex gap-2">
    <input type="text" name="q" class="form-control form-control-lg" placeholder="اكتب اسماً، رقم قضية، رقم عقد، رقم فاتورة، جوال..." value="<?= e($term) ?>" autofocus>
    <button class="btn btn-primary btn-lg"><i class="fas fa-magnifying-glass"></i></button>
  </form>
</div></div>

<?php if (mb_strlen($term) >= 2 && $total === 0): ?>
<div class="alert alert-info">لا توجد نتائج مطابقة لـ «<?= e($term) ?>»</div>
<?php endif; ?>

<?php if ($results['clients']): ?>
<div class="card mb-3"><div class="card-header fw-bold"><i class="fas fa-address-book me-1 text-primary"></i>العملاء (<?= count($results['clients']) ?>)</div>
  <div class="list-group list-group-flush"><?php foreach ($results['clients'] as $c): ?>
  <a href="client_file.php?id=<?= (int)$c['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
    <span><?= e($c['full_name']) ?></span><span class="text-muted" style="font-size:12px"><?= e($c['phone'] ?: $c['id_number'] ?: '') ?></span></a>
  <?php endforeach; ?></div></div>
<?php endif; ?>

<?php if ($results['cases']): ?>
<div class="card mb-3"><div class="card-header fw-bold"><i class="fas fa-gavel me-1 text-primary"></i>القضايا (<?= count($results['cases']) ?>)</div>
  <div class="list-group list-group-flush"><?php foreach ($results['cases'] as $c): ?>
  <a href="cases.php?open_case=<?= (int)$c['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
    <span><?= e($c['case_number']) ?> — <?= e(mb_substr($c['title'],0,50)) ?></span><?= statusBadge($c['status']) ?></a>
  <?php endforeach; ?></div></div>
<?php endif; ?>

<?php if ($results['contracts']): ?>
<div class="card mb-3"><div class="card-header fw-bold"><i class="fas fa-file-signature me-1 text-primary"></i>العقود (<?= count($results['contracts']) ?>)</div>
  <div class="list-group list-group-flush"><?php foreach ($results['contracts'] as $c): ?>
  <a href="contracts.php?edit=<?= (int)$c['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
    <span><?= e($c['contract_number'] ?: '—') ?> — <?= e(mb_substr($c['title'],0,50)) ?></span><?= statusBadge($c['status']) ?></a>
  <?php endforeach; ?></div></div>
<?php endif; ?>

<?php if ($results['invoices']): ?>
<div class="card mb-3"><div class="card-header fw-bold"><i class="fas fa-file-invoice-dollar me-1 text-primary"></i>الفواتير (<?= count($results['invoices']) ?>)</div>
  <div class="list-group list-group-flush"><?php foreach ($results['invoices'] as $c): ?>
  <a href="invoices.php?edit=<?= (int)$c['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
    <span><?= e($c['invoice_number']) ?> — <?= e(mb_substr($c['title'],0,40)) ?></span><span class="fw-bold"><?= number_format((float)$c['total'],2) ?></span></a>
  <?php endforeach; ?></div></div>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
