<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('esignature','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'esignature')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'التوقيع الإلكتروني';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('esignature','add'); $_canDel = can('esignature','delete');
$_canApprove = can('esignature','approve'); // مستوى المدير: يشوف كل طلبات التوقيع
$_canContracts = hasFeature($conn, $oid, 'has_contracts') && can('contracts','view');

$conn->query("CREATE TABLE IF NOT EXISTS esign_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    doc_type ENUM('contract','poa','other') DEFAULT 'other',
    doc_id INT DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    signer_name VARCHAR(150) NOT NULL,
    signer_phone VARCHAR(30) DEFAULT NULL,
    signer_email VARCHAR(150) DEFAULT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('pending','signed','declined') DEFAULT 'pending',
    signature_data LONGTEXT DEFAULT NULL,
    signed_at TIMESTAMP NULL DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $delId = (int)$_GET['delete'];
    $ownScope = !$_canApprove ? " AND created_by=$uid" : "";
    $conn->query("DELETE FROM esign_requests WHERE id=$delId AND office_id=$oid$ownScope");
    header("Location: esignature.php?msg=deleted"); exit;
}

/* ── إنشاء طلب توقيع ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'esign') {
    requirePerm('esignature', 'add', 'esignature.php?msg=denied');
    $doc_type = in_array($_POST['doc_type'] ?? '', ['contract','poa','other'], true) ? $_POST['doc_type'] : 'other';
    $doc_id = !empty($_POST['doc_id']) ? (int)$_POST['doc_id'] : 'NULL';
    $title = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $sname = $conn->real_escape_string(trim($_POST['signer_name'] ?? ''));
    $sphone = $conn->real_escape_string(trim($_POST['signer_phone'] ?? ''));
    $semail = $conn->real_escape_string(trim($_POST['signer_email'] ?? ''));
    $token = bin2hex(random_bytes(24));
    $conn->query("INSERT INTO esign_requests (office_id,doc_type,doc_id,title,signer_name,signer_phone,signer_email,token,created_by)
        VALUES ($oid,'$doc_type',$doc_id,'$title','$sname','$sphone','$semail','$token',".($uid ?: 'NULL').")");
    header("Location: esignature.php?msg=saved"); exit;
}

/* ── بيانات ── موظف عادي يشوف فقط طلبات التوقيع التي أنشأها بنفسه؛ المدير يشوف الكل ── */
$requests = [];
$_ownScope = !$_canApprove ? " AND created_by=$uid" : "";
$rr = $conn->query("SELECT * FROM esign_requests WHERE office_id=$oid$_ownScope ORDER BY id DESC LIMIT 300");
if ($rr) while ($r = $rr->fetch_assoc()) $requests[] = $r;

$contracts_arr = [];
if ($_canContracts) {
    $ctr = $conn->query("SELECT id, contract_number, title FROM contracts WHERE office_id=$oid ORDER BY id DESC LIMIT 200");
    if ($ctr) while ($r = $ctr->fetch_assoc()) $contracts_arr[$r['id']] = $r['contract_number'].' — '.mb_substr($r['title'],0,30);
    $por = $conn->query("SELECT id, poa_number, title FROM poa WHERE office_id=$oid ORDER BY id DESC LIMIT 200");
    $poa_arr = [];
    if ($por) while ($r = $por->fetch_assoc()) $poa_arr[$r['id']] = $r['poa_number'].' — '.mb_substr($r['title'],0,30);
} else { $poa_arr = []; }

$_site = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'mehkam.app');
$_statusMap = ['pending'=>['بانتظار التوقيع','warning'],'signed'=>['تم التوقيع','success'],'declined'=>['مرفوض','danger']];

// فتح نافذة «طلب توقيع جديد» مباشرة مع تحديد المستند مسبقاً — من صفحة العقود/الوكالات
$_newDocType = in_array($_GET['new_doc_type'] ?? '', ['contract','poa'], true) ? $_GET['new_doc_type'] : '';
$_newDocId   = $_newDocType ? (int)($_GET['new_doc_id'] ?? 0) : 0;
$_newTitle   = trim($_GET['new_title'] ?? '');

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-signature"></i> التوقيع الإلكتروني</div>
<div class="mk-page-sub mb-3">أرسل رابط توقيع لعقودك ووكالاتك وتابع حالتها</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'إنشاء طلب التوقيع بنجاح' ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-end mb-3">
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#esignModal"><i class="fas fa-plus me-1"></i>طلب توقيع جديد</button>
  <?php endif; ?>
</div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>المستند</th><th>الموقّع</th><th>الحالة</th><th>التاريخ</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$requests): ?><tr><td colspan="5" class="text-center text-muted py-4">لا توجد طلبات توقيع بعد</td></tr>
<?php else: foreach ($requests as $r): $sm = $_statusMap[$r['status']] ?? ['—','secondary'];
  $link = $_site . '/public/sign.php?t=' . $r['token']; ?>
<tr>
  <td class="fw-semibold"><?= e($r['title']) ?></td>
  <td><?= e($r['signer_name']) ?><?php if ($r['signer_phone']): ?><br><small class="text-muted"><?= e($r['signer_phone']) ?></small><?php endif; ?></td>
  <td><span class="badge bg-<?= $sm[1] ?> bg-opacity-10 text-<?= $sm[1] ?>"><?= $sm[0] ?></span>
      <?php if ($r['status']==='signed'): ?><br><small class="text-muted" style="font-size:11px"><?= dDate($r['signed_at'], true) ?></small><?php endif; ?></td>
  <td style="font-size:12px"><?= dDate($r['created_at'], true) ?></td>
  <td class="d-flex gap-1">
    <?php if ($r['status']==='pending'): ?>
    <button class="btn btn-sm btn-outline-primary" onclick="navigator.clipboard.writeText('<?= e($link) ?>');this.innerHTML='<i class=\'fas fa-check\'></i>';" title="نسخ رابط التوقيع"><i class="fas fa-link"></i></button>
    <?php else: ?>
    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#viewSigModal" data-sig="<?= e($r['signature_data']) ?>" onclick="viewSig(this)" title="عرض التوقيع"><i class="fas fa-eye"></i></button>
    <?php endif; ?>
    <?php if ($_canDel): ?><a href="esignature.php?delete=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا الطلب؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>

<!-- Modal: طلب توقيع -->
<div class="modal fade" id="esignModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-signature me-2"></i>طلب توقيع جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="esign">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">نوع المستند</label>
        <select name="doc_type" id="es_doctype" class="form-select" onchange="esToggleDoc()">
          <option value="other">مستند آخر</option>
          <?php if ($_canContracts): ?>
          <option value="contract">عقد</option>
          <option value="poa">وكالة</option>
          <?php endif; ?>
        </select>
      </div>
      <div class="mb-3" id="es_doc_wrap" style="display:none">
        <label class="form-label fw-semibold">اختر المستند</label>
        <select name="doc_id" id="es_docid" class="form-select"></select>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">عنوان المستند *</label><input type="text" name="title" id="es_title" class="form-control" required placeholder="مثال: اتفاقية أتعاب — محمد أحمد"></div>
      <div class="mb-3"><label class="form-label fw-semibold">اسم الموقِّع *</label><input type="text" name="signer_name" class="form-control" required></div>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label fw-semibold">جوال الموقِّع</label><input type="text" name="signer_phone" class="form-control"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">بريد الموقِّع</label><input type="email" name="signer_email" class="form-control"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إنشاء رابط التوقيع</button></div>
  </form>
</div></div></div>

<!-- Modal: عرض التوقيع -->
<div class="modal fade" id="viewSigModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-signature me-2"></i>التوقيع</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body text-center"><img id="sigImg" style="max-width:100%;border:1px solid #eef1f6;border-radius:8px"></div>
</div></div></div>

<script>
var CONTRACTS = <?= json_encode($contracts_arr, JSON_UNESCAPED_UNICODE) ?>;
var POAS = <?= json_encode($poa_arr, JSON_UNESCAPED_UNICODE) ?>;
function esToggleDoc() {
  var t = document.getElementById('es_doctype').value;
  var wrap = document.getElementById('es_doc_wrap');
  var sel = document.getElementById('es_docid');
  sel.innerHTML = '<option value="">— اختر —</option>';
  if (t === 'other') { wrap.style.display = 'none'; return; }
  wrap.style.display = '';
  var list = t === 'contract' ? CONTRACTS : POAS;
  Object.keys(list).forEach(function (k) {
    var o = document.createElement('option'); o.value = k; o.textContent = list[k]; sel.appendChild(o);
  });
}
function viewSig(btn) {
  document.getElementById('sigImg').src = btn.getAttribute('data-sig') || '';
}
<?php if ($_newDocType): ?>
document.addEventListener('DOMContentLoaded', function () {
  document.getElementById('es_doctype').value = <?= json_encode($_newDocType) ?>;
  esToggleDoc();
  document.getElementById('es_docid').value = <?= json_encode((string)$_newDocId) ?>;
  document.getElementById('es_title').value = <?= json_encode($_newTitle) ?>;
  new bootstrap.Modal(document.getElementById('esignModal')).show();
});
<?php endif; ?>
</script>

<?php include '../includes/office_footer.php'; ?>
