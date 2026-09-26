<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('client_source_attribution','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'client_source_attribution')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'تتبّع مصدر العميل';
$_canEdit = can('client_source_attribution','edit');
$_SOURCES = ['إحالة من عميل','إحالة من محامٍ آخر','إعلان مدفوع','مواقع التواصل','بحث Google','معرفة شخصية','أخرى'];

$conn->query("CREATE TABLE IF NOT EXISTS client_sources (
    client_id INT PRIMARY KEY, office_id INT NOT NULL, source VARCHAR(100) NOT NULL,
    referred_by VARCHAR(150) DEFAULT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'src') {
    requirePerm('client_source_attribution', 'edit', 'client_source_attribution.php?msg=denied');
    $cid = (int)($_POST['client_id'] ?? 0);
    $ok = $conn->query("SELECT id FROM clients WHERE id=$cid AND office_id=$oid")->fetch_assoc();
    $src = trim($_POST['source'] ?? '');
    if ($ok && $src !== '') {
        $se = $conn->real_escape_string($src);
        $ref = $conn->real_escape_string(trim($_POST['referred_by'] ?? ''));
        $conn->query("INSERT INTO client_sources (client_id,office_id,source,referred_by) VALUES ($cid,$oid,'$se','$ref') ON DUPLICATE KEY UPDATE source='$se', referred_by='$ref'");
    }
    header('Location: client_source_attribution.php?msg=saved'); exit;
}

$stats = [];
$sr = $conn->query("SELECT source, COUNT(*) c FROM client_sources WHERE office_id=$oid GROUP BY source ORDER BY c DESC");
if ($sr) while ($x = $sr->fetch_assoc()) $stats[] = $x;
$total = array_sum(array_column($stats, 'c'));

$clients = [];
$cr = $conn->query("SELECT c.id, c.full_name, s.source, s.referred_by FROM clients c LEFT JOIN client_sources s ON s.client_id=c.id WHERE c.office_id=$oid ORDER BY c.id DESC LIMIT 300");
if ($cr) while ($x = $cr->fetch_assoc()) $clients[] = $x;

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-route"></i> تتبّع مصدر العميل</div>
<div class="mk-page-sub mb-3">سجّل من أين جاء كل عميل — يوضح أي قناة تسويقية فعلاً تجيب عملاء</div>

<?php if (isset($_GET['msg'])): ?><div class="alert alert-success alert-dismissible fade show">تم بنجاح<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<?php if ($stats): ?>
<div class="card mb-4"><div class="card-header fw-bold"><i class="fas fa-chart-pie me-1"></i>توزيع المصادر</div>
  <div class="card-body">
  <?php foreach ($stats as $s): $pct = $total ? round($s['c']/$total*100) : 0; ?>
  <div class="d-flex align-items-center gap-2 mb-2" style="font-size:13px">
    <div style="width:140px"><?= e($s['source']) ?></div>
    <div class="progress flex-grow-1" style="height:10px"><div class="progress-bar bg-primary" style="width:<?= max(3,$pct) ?>%"></div></div>
    <div style="width:50px" class="text-end fw-bold"><?= $s['c'] ?></div>
  </div>
  <?php endforeach; ?>
  </div></div>
<?php endif; ?>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>العميل</th><th>المصدر</th><th>تفاصيل</th><th></th></tr></thead>
<tbody>
<?php if (!$clients): ?><tr><td colspan="4" class="text-center text-muted py-4">لا يوجد عملاء بعد</td></tr>
<?php else: foreach ($clients as $c): ?>
<tr>
  <td class="fw-semibold"><?= e($c['full_name']) ?></td>
  <td><?= $c['source'] ? '<span class="badge bg-info bg-opacity-10 text-info">'.e($c['source']).'</span>' : '<span class="text-muted">غير محدد</span>' ?></td>
  <td style="font-size:12px"><?= e($c['referred_by'] ?: '—') ?></td>
  <td><?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='srcOpen(<?= json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div>

<div class="modal fade" id="srcModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="srcTitle">مصدر العميل</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="src"><input type="hidden" name="client_id" id="s_cid">
    <div class="modal-body">
      <label class="form-label fw-semibold">المصدر</label>
      <select name="source" id="s_source" class="form-select"><?php foreach ($_SOURCES as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?></select>
      <label class="form-label fw-semibold mt-2">تفاصيل (اسم المُحيل مثلاً)</label>
      <input name="referred_by" id="s_ref" class="form-control">
    </div>
    <div class="modal-footer"><button class="btn btn-primary w-100">حفظ</button></div>
  </form>
</div></div></div>
<script>
function srcOpen(c){document.getElementById('srcTitle').innerText='مصدر: '+c.full_name;document.getElementById('s_cid').value=c.id;document.getElementById('s_source').value=c.source||'<?= e($_SOURCES[0]) ?>';document.getElementById('s_ref').value=c.referred_by||'';new bootstrap.Modal(document.getElementById('srcModal')).show();}
</script>

<?php include '../includes/office_footer.php'; ?>
