<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('client_periodic_report','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'client_periodic_report')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'تقرير دوري للعميل';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('client_periodic_report','add'); $_canDel = can('client_periodic_report','delete');
$_hasFin = hasFeature($conn, $oid, 'has_finance');

$conn->query("CREATE TABLE IF NOT EXISTS client_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    client_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    period_from DATE NOT NULL,
    period_to DATE NOT NULL,
    generated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM client_reports WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header('Location: client_periodic_report.php?msg=deleted'); exit;
}

/* ── توليد تقرير ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'gen') {
    requirePerm('client_periodic_report', 'add', 'client_periodic_report.php?msg=denied');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $from = $_POST['period_from'] ?? ''; $to = $_POST['period_to'] ?? '';
    $ok = $conn->query("SELECT id FROM clients WHERE id=$client_id AND office_id=$oid")->fetch_assoc();
    if (!$ok || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { header('Location: client_periodic_report.php?msg=invalid'); exit; }
    $token = bin2hex(random_bytes(24));
    $conn->query("INSERT INTO client_reports (office_id,client_id,token,period_from,period_to,generated_by) VALUES ($oid,$client_id,'$token','$from','$to',".($uid ?: 'NULL').")");
    header('Location: client_periodic_report.php?msg=saved'); exit;
}

$clients_arr = [];
$cr = $conn->query("SELECT id, full_name FROM clients WHERE office_id=$oid ORDER BY full_name LIMIT 500");
if ($cr) while ($x = $cr->fetch_assoc()) $clients_arr[$x['id']] = $x['full_name'];

$reports = [];
$rr = $conn->query("SELECT r.*, c.full_name client_name FROM client_reports r LEFT JOIN clients c ON c.id=r.client_id WHERE r.office_id=$oid ORDER BY r.id DESC LIMIT 100");
if ($rr) while ($x = $rr->fetch_assoc()) $reports[] = $x;

$_base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/') . '/public/client_report.php?t=';

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-file-contract"></i> تقرير دوري للعميل</div>
<div class="mk-page-sub mb-3">ولّد تقريراً يلخّص كل قضايا العميل وحالتها خلال فترة محددة، وشاركه معه برابط مباشر</div>

<?php if (isset($_GET['msg'])):
  $mm = ['saved'=>['success','تم توليد التقرير'],'deleted'=>['success','تم الحذف'],'invalid'=>['danger','أكمل العميل والفترة بشكل صحيح'],'denied'=>['danger','ليست لديك صلاحية']];
  $m = $mm[$_GET['msg']] ?? ['success','تم']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($_canAdd): ?>
<div class="card mb-4"><div class="card-body">
  <form method="POST" class="row g-2 align-items-end"><input type="hidden" name="form_type" value="gen">
    <div class="col-md-4"><label class="form-label fw-semibold">العميل *</label><select name="client_id" class="form-select" required><option value="">— اختر —</option>
      <?php foreach ($clients_arr as $cid => $cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label fw-semibold">من تاريخ</label><input type="date" name="period_from" class="form-control" value="<?= date('Y-m-01') ?>" required></div>
    <div class="col-md-3"><label class="form-label fw-semibold">إلى تاريخ</label><input type="date" name="period_to" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-2"><button class="btn btn-primary w-100"><i class="fas fa-file-circle-plus me-1"></i>توليد</button></div>
  </form>
</div></div>
<?php endif; ?>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>العميل</th><th>الفترة</th><th>تاريخ التوليد</th><th>الرابط</th><th></th></tr></thead>
<tbody>
<?php if (!$reports): ?><tr><td colspan="5" class="text-center text-muted py-4">لا توجد تقارير بعد</td></tr>
<?php else: foreach ($reports as $r): $url = $_base . $r['token']; ?>
<tr>
  <td class="fw-semibold"><?= e($r['client_name'] ?: '—') ?></td>
  <td style="font-size:12px"><?= e($r['period_from']) ?> → <?= e($r['period_to']) ?></td>
  <td style="font-size:12px"><?= e(date('Y-m-d', strtotime($r['created_at']))) ?></td>
  <td>
    <div class="input-group input-group-sm" style="max-width:260px">
      <input type="text" class="form-control" readonly value="<?= e($url) ?>" onclick="this.select()">
      <button class="btn btn-outline-primary" onclick="navigator.clipboard.writeText('<?= e($url) ?>')"><i class="fas fa-copy"></i></button>
      <a class="btn btn-outline-success" target="_blank" href="https://wa.me/?text=<?= urlencode($url) ?>"><i class="fab fa-whatsapp"></i></a>
    </div>
  </td>
  <td><?php if ($_canDel): ?><a href="client_periodic_report.php?delete=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا التقرير؟')"><i class="fas fa-trash"></i></a><?php endif; ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div>
<div class="alert alert-info mt-3" style="font-size:12px"><i class="fas fa-circle-info me-1"></i>التقرير يُولَّد ويُشارَك يدوياً برابط — لا يُرسَل بريد تلقائي حالياً.</div>

<?php include '../includes/office_footer.php'; ?>
