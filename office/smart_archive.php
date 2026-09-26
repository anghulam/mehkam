<?php
require_once '../includes/functions.php';
require_once '../includes/storage.php';
require_once '../config/db.php';
requireOffice();
if (!can('smart_archive','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'smart_archive')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'الأرشفة الذكية بالبحث النصي';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('smart_archive','add'); $_canEdit = can('smart_archive','edit'); $_canDel = can('smart_archive','delete');

$conn->query("CREATE TABLE IF NOT EXISTS smart_archive_docs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    case_id INT DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) DEFAULT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    file_driver VARCHAR(20) DEFAULT 'local',
    extracted_text LONGTEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM smart_archive_docs WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header("Location: smart_archive.php?msg=deleted"); exit;
}

/* ── حفظ / تعديل مستند ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'doc') {
    $did = (int)($_POST['id'] ?? 0);
    requirePerm('smart_archive', $did ? 'edit' : 'add', 'smart_archive.php?msg=denied');
    $title = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $case_id = !empty($_POST['case_id']) ? (int)$_POST['case_id'] : 'NULL';
    $text = $conn->real_escape_string(trim($_POST['extracted_text'] ?? ''));

    $fn=''; $fp=''; $fd='local';
    if (!empty($_FILES['doc_file']['tmp_name'])) {
        $up = storage_upload($_FILES['doc_file'], 'smart_archive', $oid, $_SESSION['office_name'] ?? '');
        if ($up['success']) { $fn=$conn->real_escape_string($up['name']); $fp=$conn->real_escape_string($up['path']); $fd=$conn->real_escape_string($up['driver']); }
    }

    if ($did) {
        $fsql = $fp ? ",file_name='$fn',file_path='$fp',file_driver='$fd'" : '';
        $conn->query("UPDATE smart_archive_docs SET title='$title',case_id=$case_id,extracted_text='$text'$fsql WHERE id=$did AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO smart_archive_docs (office_id,case_id,title,file_name,file_path,file_driver,extracted_text,created_by)
            VALUES ($oid,$case_id,'$title','$fn','$fp','$fd','$text',".($uid ?: 'NULL').")");
    }
    header("Location: smart_archive.php?msg=saved"); exit;
}

/* ── البحث والبيانات ── */
$q = trim($_GET['q'] ?? '');
$where = "d.office_id=$oid";
if ($q !== '') {
    $qe = $conn->real_escape_string($q);
    $where .= " AND (d.title LIKE '%$qe%' OR d.extracted_text LIKE '%$qe%')";
}
$docs = [];
$dr = $conn->query("SELECT d.*, c.case_number, c.title case_title FROM smart_archive_docs d
    LEFT JOIN cases c ON d.case_id=c.id WHERE $where ORDER BY d.id DESC LIMIT 300");
if ($dr) while ($r = $dr->fetch_assoc()) $docs[] = $r;

$cases_arr = [];
$ccr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid" . caseScope('cases') . " ORDER BY id DESC LIMIT 300");
if ($ccr) while ($r = $ccr->fetch_assoc()) $cases_arr[$r['id']] = $r['case_number'].' — '.mb_substr($r['title'],0,40);

$edit = null;
if (isset($_GET['edit'])) $edit = $conn->query("SELECT * FROM smart_archive_docs WHERE id=".(int)$_GET['edit']." AND office_id=$oid")->fetch_assoc();

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-file-magnifying-glass"></i> الأرشفة الذكية بالبحث النصي</div>
<div class="mk-page-sub mb-3">ارفع أي مستند مع نصّه الكامل — يصبح قابلاً للبحث فوراً من محتواه</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form method="GET" class="d-flex gap-2" style="flex:1;max-width:400px">
    <input type="text" name="q" class="form-control form-control-sm" placeholder="بحث في العناوين أو نص المستندات..." value="<?= e($q) ?>">
    <button class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
    <?php if ($q !== ''): ?><a href="smart_archive.php" class="btn btn-outline-secondary btn-sm">إعادة</a><?php endif; ?>
  </form>
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#docModal" onclick="docNew()"><i class="fas fa-plus me-1"></i>مستند جديد</button>
  <?php endif; ?>
</div>

<div class="row g-3">
<?php if (!$docs): ?>
<div class="col-12"><div class="text-muted text-center py-5"><?= $q!==''?'لا نتائج مطابقة':'لا توجد مستندات مؤرشفة بعد' ?></div></div>
<?php else: foreach ($docs as $d): ?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div class="fw-bold"><?= e($d['title']) ?></div>
        <div class="d-flex gap-1">
          <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='docEdit(<?= json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
          <?php if ($_canDel): ?><a href="smart_archive.php?delete=<?= $d['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا المستند؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
        </div>
      </div>
      <?php if ($d['case_number']): ?><div class="text-muted mb-2" style="font-size:12px"><i class="fas fa-gavel me-1"></i><?= e($d['case_number']) ?> — <?= e(mb_substr($d['case_title'],0,30)) ?></div><?php endif; ?>
      <?php if ($d['extracted_text']): ?>
      <div class="text-muted mb-2" style="font-size:12px;max-height:60px;overflow:hidden"><?= e(mb_substr($d['extracted_text'],0,150)) ?>…</div>
      <?php endif; ?>
      <?php if (!empty($d['file_path'])): ?><?= storage_file_link($conn, 'smart', $d, 'view') ?><?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- Modal -->
<div class="modal fade" id="docModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="docTitle"><i class="fas fa-file-magnifying-glass me-2"></i>مستند جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST" enctype="multipart/form-data"><input type="hidden" name="form_type" value="doc"><input type="hidden" name="id" id="doc_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">عنوان المستند *</label><input type="text" name="title" id="doc_title_inp" class="form-control" required></div>
      <div class="mb-3"><label class="form-label fw-semibold">القضية المرتبطة (اختياري)</label>
        <select name="case_id" id="doc_case" class="form-select"><option value="">— بدون —</option>
          <?php foreach ($cases_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">الملف</label><input type="file" name="doc_file" class="form-control"></div>
      <div class="mb-1">
        <label class="form-label fw-semibold">نص المستند (للبحث)</label>
        <textarea name="extracted_text" id="doc_text" class="form-control" rows="6" placeholder="الصق نص المستند هنا حتى يصبح قابلاً للبحث..."></textarea>
        <div class="form-text">لا تتوفر قراءة تلقائية للنص من الصور حالياً — الصق النص يدوياً لضمان دقة البحث</div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<script>
function docNew(){document.getElementById('docTitle').innerHTML='<i class="fas fa-file-magnifying-glass me-2"></i>مستند جديد';document.getElementById('doc_id').value='';document.getElementById('doc_title_inp').value='';document.getElementById('doc_case').value='';document.getElementById('doc_text').value='';}
function docEdit(d){document.getElementById('docTitle').innerHTML='<i class="fas fa-edit me-2"></i>تعديل مستند';document.getElementById('doc_id').value=d.id;document.getElementById('doc_title_inp').value=d.title;document.getElementById('doc_case').value=d.case_id||'';document.getElementById('doc_text').value=d.extracted_text||'';new bootstrap.Modal(document.getElementById('docModal')).show();}
<?php if ($edit): ?>
document.addEventListener('DOMContentLoaded', function(){ docEdit(<?= json_encode($edit, JSON_HEX_APOS|JSON_HEX_QUOT) ?>); });
<?php endif; ?>
</script>

<?php include '../includes/office_footer.php'; ?>
