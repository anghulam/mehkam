<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('client_surveys','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'client_surveys')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'استطلاعات رضا العملاء';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('client_surveys','add'); $_canDel = can('client_surveys','delete');

$conn->query("CREATE TABLE IF NOT EXISTS client_survey_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    client_id INT NOT NULL,
    case_id INT DEFAULT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('pending','completed') DEFAULT 'pending',
    rating TINYINT DEFAULT NULL,
    comment TEXT DEFAULT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    created_by INT DEFAULT NULL,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM client_survey_requests WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header("Location: client_surveys.php?msg=deleted"); exit;
}

/* ── إرسال استطلاع جديد ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'survey') {
    requirePerm('client_surveys', 'add', 'client_surveys.php?msg=denied');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $case_id = !empty($_POST['case_id']) ? (int)$_POST['case_id'] : 'NULL';
    $chk = $conn->query("SELECT id FROM clients WHERE id=$client_id AND office_id=$oid")->num_rows;
    if ($chk) {
        $token = bin2hex(random_bytes(24));
        $conn->query("INSERT INTO client_survey_requests (office_id,client_id,case_id,token,created_by) VALUES ($oid,$client_id,$case_id,'$token',".($uid ?: 'NULL').")");
    }
    header("Location: client_surveys.php?msg=saved"); exit;
}

/* ── بيانات ── */
$surveys = [];
$sr = $conn->query("SELECT s.*, cl.full_name client_name, c.case_number FROM client_survey_requests s
    LEFT JOIN clients cl ON s.client_id=cl.id LEFT JOIN cases c ON s.case_id=c.id
    WHERE s.office_id=$oid ORDER BY s.id DESC LIMIT 300");
if ($sr) while ($r = $sr->fetch_assoc()) $surveys[] = $r;

$clients_arr = [];
$clr = $conn->query("SELECT id, full_name FROM clients WHERE office_id=$oid ORDER BY full_name");
if ($clr) while ($r = $clr->fetch_assoc()) $clients_arr[$r['id']] = $r['full_name'];

$cases_by_client = [];
$ccr = $conn->query("SELECT id, client_id, case_number, title FROM cases WHERE office_id=$oid AND client_id IS NOT NULL");
if ($ccr) while ($r = $ccr->fetch_assoc()) $cases_by_client[$r['client_id']][] = $r;

$completed = array_filter($surveys, fn($s) => $s['status']==='completed');
$avg_rating = count($completed) ? round(array_sum(array_column($completed,'rating')) / count($completed), 1) : null;

$_site = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'mehkam.app');

include '../includes/office_header.php';
?>

<style>.cs-star{color:#e2e8f0}.cs-star.filled{color:#f59e0b}</style>

<div class="mk-page-title mb-1"><i class="fas fa-star-half-stroke"></i> استطلاعات رضا العملاء</div>
<div class="mk-page-sub mb-3">اقِس رضا عملائك بعد إغلاق قضية أو فاتورة</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'إرسال الاستطلاع بنجاح' ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#fef3c7;color:#d97706"><i class="fas fa-star"></i></div>
      <div><div class="fw-bold"><?= $avg_rating !== null ? $avg_rating.' / 5' : '—' ?></div><div class="text-muted" style="font-size:11px">متوسط التقييم</div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#f0fdf4;color:#16a34a"><i class="fas fa-check-circle"></i></div>
      <div><div class="fw-bold"><?= count($completed) ?> / <?= count($surveys) ?></div><div class="text-muted" style="font-size:11px">استجاب من إجمالي المُرسَل</div></div>
    </div></div>
  </div>
</div>

<div class="d-flex justify-content-end mb-3">
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#surveyModal"><i class="fas fa-paper-plane me-1"></i>إرسال استطلاع جديد</button>
  <?php endif; ?>
</div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>العميل</th><th>القضية</th><th>الحالة</th><th>التقييم</th><th>التعليق</th><th>التاريخ</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$surveys): ?><tr><td colspan="7" class="text-center text-muted py-4">لا توجد استطلاعات مُرسَلة بعد</td></tr>
<?php else: foreach ($surveys as $s):
  $link = $_site . '/public/survey.php?t=' . $s['token']; ?>
<tr>
  <td class="fw-semibold"><?= e($s['client_name'] ?: '—') ?></td>
  <td style="font-size:12px"><?= e($s['case_number'] ?: '—') ?></td>
  <td><?= $s['status']==='completed' ? '<span class="badge bg-success bg-opacity-10 text-success">استجاب</span>' : '<span class="badge bg-warning bg-opacity-10 text-warning">بانتظار الرد</span>' ?></td>
  <td><?php if ($s['rating']): for($i=1;$i<=5;$i++): ?><i class="fas fa-star cs-star <?= $i<=$s['rating']?'filled':'' ?>" style="font-size:11px"></i><?php endfor; else: echo '—'; endif; ?></td>
  <td style="font-size:12px;max-width:200px"><?= e(mb_substr($s['comment'] ?? '',0,80)) ?></td>
  <td style="font-size:12px"><?= dDate($s['sent_at'], true) ?></td>
  <td class="d-flex gap-1">
    <?php if ($s['status']==='pending'): ?>
    <button class="btn btn-sm btn-outline-primary" onclick="navigator.clipboard.writeText('<?= e($link) ?>');this.innerHTML='<i class=\'fas fa-check\'></i>';" title="نسخ رابط الاستطلاع"><i class="fas fa-link"></i></button>
    <?php endif; ?>
    <?php if ($_canDel): ?><a href="client_surveys.php?delete=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>

<!-- Modal -->
<div class="modal fade" id="surveyModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-star-half-stroke me-2"></i>إرسال استطلاع جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="survey">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">العميل *</label>
        <select name="client_id" id="sv_client" class="form-select" required onchange="svUpdateCases()"><option value="">— اختر —</option>
          <?php foreach ($clients_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-1"><label class="form-label fw-semibold">القضية (اختياري)</label>
        <select name="case_id" id="sv_case" class="form-select"><option value="">— بدون —</option></select>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إنشاء رابط الاستطلاع</button></div>
  </form>
</div></div></div>

<script>
var CASES_BY_CLIENT = <?= json_encode($cases_by_client, JSON_UNESCAPED_UNICODE) ?>;
function svUpdateCases() {
  var cid = document.getElementById('sv_client').value;
  var sel = document.getElementById('sv_case');
  sel.innerHTML = '<option value="">— بدون —</option>';
  (CASES_BY_CLIENT[cid] || []).forEach(function (c) {
    var o = document.createElement('option'); o.value = c.id; o.textContent = c.case_number + ' — ' + c.title; sel.appendChild(o);
  });
}
</script>

<?php include '../includes/office_footer.php'; ?>
