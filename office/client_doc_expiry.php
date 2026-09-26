<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('client_doc_expiry','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'client_doc_expiry')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'متتبّع انتهاء وثائق العميل';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('client_doc_expiry','add'); $_canEdit = can('client_doc_expiry','edit'); $_canDel = can('client_doc_expiry','delete');

$conn->query("CREATE TABLE IF NOT EXISTS client_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    client_id INT NOT NULL,
    doc_type VARCHAR(100) NOT NULL,
    doc_number VARCHAR(100) DEFAULT NULL,
    expiry_date DATE NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id), INDEX idx_expiry (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$_docTypes = ['سجل تجاري','هوية وطنية/إقامة','وكالة/تفويض','رخصة مهنية','عقد إيجار','جواز سفر','أخرى'];

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM client_documents WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header('Location: client_doc_expiry.php?msg=deleted'); exit;
}

/* ── حفظ/تعديل ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'doc') {
    $did = (int)($_POST['id'] ?? 0);
    requirePerm('client_doc_expiry', $did ? 'edit' : 'add', 'client_doc_expiry.php?msg=denied');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $okClient = $conn->query("SELECT id FROM clients WHERE id=$client_id AND office_id=$oid")->fetch_assoc();
    $exp = $_POST['expiry_date'] ?? '';
    if (!$okClient || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) { header('Location: client_doc_expiry.php?msg=invalid'); exit; }
    $type = $conn->real_escape_string(trim($_POST['doc_type'] ?? '') ?: 'أخرى');
    $num = $conn->real_escape_string(trim($_POST['doc_number'] ?? ''));
    $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    if ($did) {
        $conn->query("UPDATE client_documents SET client_id=$client_id,doc_type='$type',doc_number='$num',expiry_date='$exp',notes='$notes' WHERE id=$did AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO client_documents (office_id,client_id,doc_type,doc_number,expiry_date,notes,created_by) VALUES ($oid,$client_id,'$type','$num','$exp','$notes',".($uid ?: 'NULL').")");
    }
    header('Location: client_doc_expiry.php?msg=saved'); exit;
}

/* ── بيانات ── */
$clients_arr = [];
$cr = $conn->query("SELECT id, full_name FROM clients WHERE office_id=$oid ORDER BY full_name LIMIT 500");
if ($cr) while ($x = $cr->fetch_assoc()) $clients_arr[$x['id']] = $x['full_name'];

$filter = $_GET['f'] ?? '';
$where = "d.office_id=$oid";
if ($filter === 'expired') $where .= " AND d.expiry_date < CURDATE()";
elseif ($filter === 'soon') $where .= " AND d.expiry_date >= CURDATE() AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";

$docs = [];
$dr = $conn->query("SELECT d.*, c.full_name client_name FROM client_documents d LEFT JOIN clients c ON c.id=d.client_id WHERE $where ORDER BY d.expiry_date ASC LIMIT 300");
if ($dr) while ($x = $dr->fetch_assoc()) $docs[] = $x;

$expired_count = (int)$conn->query("SELECT COUNT(*) c FROM client_documents WHERE office_id=$oid AND expiry_date < CURDATE()")->fetch_assoc()['c'];
$soon_count = (int)$conn->query("SELECT COUNT(*) c FROM client_documents WHERE office_id=$oid AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetch_assoc()['c'];
$total_count = (int)$conn->query("SELECT COUNT(*) c FROM client_documents WHERE office_id=$oid")->fetch_assoc()['c'];

$edit = null;
if (isset($_GET['edit']) && $_canEdit) $edit = $conn->query("SELECT * FROM client_documents WHERE id=".(int)$_GET['edit']." AND office_id=$oid")->fetch_assoc();

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-file-circle-exclamation"></i> متتبّع انتهاء وثائق العميل</div>
<div class="mk-page-sub mb-3">سجّل وثائق عملائك (سجل تجاري، هوية، تفويض...) واحصل على تنبيه قبل انتهاء صلاحيتها</div>

<?php if (isset($_GET['msg'])):
  $mm = ['saved'=>['success','تم الحفظ بنجاح'],'deleted'=>['success','تم الحذف'],'invalid'=>['danger','أكمل العميل وتاريخ الانتهاء بشكل صحيح'],'denied'=>['danger','ليست لديك صلاحية']];
  $m = $mm[$_GET['msg']] ?? ['success','تم']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-4"><a href="client_doc_expiry.php" class="text-decoration-none"><div class="card h-100 <?= $filter===''?'border-primary':'' ?>"><div class="card-body"><div class="fw-bold" style="font-size:19px"><?= $total_count ?></div><div class="text-muted" style="font-size:12px">إجمالي الوثائق</div></div></div></a></div>
  <div class="col-4"><a href="client_doc_expiry.php?f=soon" class="text-decoration-none"><div class="card h-100 <?= $filter==='soon'?'border-warning':'' ?>"><div class="card-body"><div class="fw-bold text-warning" style="font-size:19px"><?= $soon_count ?></div><div class="text-muted" style="font-size:12px">تنتهي خلال 30 يوم</div></div></div></a></div>
  <div class="col-4"><a href="client_doc_expiry.php?f=expired" class="text-decoration-none"><div class="card h-100 <?= $filter==='expired'?'border-danger':'' ?>"><div class="card-body"><div class="fw-bold text-danger" style="font-size:19px"><?= $expired_count ?></div><div class="text-muted" style="font-size:12px">منتهية</div></div></div></a></div>
</div>

<div class="d-flex justify-content-end mb-3"><?php if ($_canAdd): ?><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#docModal" onclick="docNew()"><i class="fas fa-plus me-1"></i>وثيقة جديدة</button><?php endif; ?></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>العميل</th><th>نوع الوثيقة</th><th>الرقم</th><th>تاريخ الانتهاء</th><th>الحالة</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$docs): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد وثائق مسجّلة</td></tr>
<?php else: foreach ($docs as $d):
  $days = (int)((strtotime($d['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
  if ($days < 0) { $lbl = 'منتهية منذ '.abs($days).' يوم'; $cls = 'danger'; }
  elseif ($days <= 30) { $lbl = 'تنتهي خلال '.$days.' يوم'; $cls = 'warning'; }
  else { $lbl = 'سارية'; $cls = 'success'; }
?>
<tr>
  <td class="fw-semibold"><?= e($d['client_name'] ?: '—') ?></td>
  <td><?= e($d['doc_type']) ?></td>
  <td style="font-size:12px"><?= e($d['doc_number'] ?: '—') ?></td>
  <td><?= e($d['expiry_date']) ?></td>
  <td><span class="badge bg-<?= $cls ?> bg-opacity-10 text-<?= $cls ?>"><?= $lbl ?></span></td>
  <td class="d-flex gap-1">
    <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='docEdit(<?= json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
    <?php if ($_canDel): ?><a href="client_doc_expiry.php?delete=<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذه الوثيقة؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div>

<div class="modal fade" id="docModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="docTitle">وثيقة جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="doc"><input type="hidden" name="id" id="d_id">
    <div class="modal-body">
      <div class="mb-2"><label class="form-label fw-semibold">العميل *</label><select name="client_id" id="d_client" class="form-select" required><option value="">— اختر —</option>
        <?php foreach ($clients_arr as $cid => $cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?></select></div>
      <div class="row g-2">
        <div class="col-6"><label class="form-label fw-semibold">نوع الوثيقة *</label><select name="doc_type" id="d_type" class="form-select">
          <?php foreach ($_docTypes as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label fw-semibold">رقم الوثيقة</label><input name="doc_number" id="d_num" class="form-control"></div>
        <div class="col-6"><label class="form-label fw-semibold">تاريخ الانتهاء *</label><input type="date" name="expiry_date" id="d_exp" class="form-control" required></div>
        <div class="col-12"><label class="form-label fw-semibold">ملاحظات</label><input name="notes" id="d_notes" class="form-control"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<script>
function docNew(){document.getElementById('docTitle').innerText='وثيقة جديدة';document.getElementById('d_id').value='';document.getElementById('d_client').value='';document.getElementById('d_type').value='سجل تجاري';document.getElementById('d_num').value='';document.getElementById('d_exp').value='';document.getElementById('d_notes').value='';}
function docEdit(d){document.getElementById('docTitle').innerText='تعديل وثيقة';document.getElementById('d_id').value=d.id;document.getElementById('d_client').value=d.client_id;document.getElementById('d_type').value=d.doc_type;document.getElementById('d_num').value=d.doc_number||'';document.getElementById('d_exp').value=d.expiry_date;document.getElementById('d_notes').value=d.notes||'';new bootstrap.Modal(document.getElementById('docModal')).show();}
<?php if ($edit): ?>document.addEventListener('DOMContentLoaded', function(){ docEdit(<?= json_encode($edit, JSON_HEX_APOS|JSON_HEX_QUOT) ?>); });<?php endif; ?>
</script>

<?php include '../includes/office_footer.php'; ?>
