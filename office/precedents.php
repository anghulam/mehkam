<?php
require_once '../includes/functions.php';
require_once '../includes/storage.php';
require_once '../config/db.php';
requireOffice();
if (!can('precedents','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'السوابق القضائية';
$oid = (int)$_SESSION['office_id'];

if (!hasFeature($conn, $oid, 'has_precedents')) {
    header("Location: profile.php?tab=upgrade&feature=precedents"); exit;
}

try {
    $conn->query("CREATE TABLE IF NOT EXISTS precedents (
        id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, title VARCHAR(500) NOT NULL,
        court VARCHAR(200) DEFAULT NULL, subject VARCHAR(200) DEFAULT NULL, case_type VARCHAR(100) DEFAULT NULL,
        law_system VARCHAR(200) DEFAULT NULL, article VARCHAR(200) DEFAULT NULL, ruling_number VARCHAR(100) DEFAULT NULL,
        ruling_date DATE DEFAULT NULL, summary TEXT DEFAULT NULL, full_text LONGTEXT DEFAULT NULL,
        keywords VARCHAR(500) DEFAULT NULL, lawyer_notes TEXT DEFAULT NULL, file_name VARCHAR(255) DEFAULT NULL,
        file_path VARCHAR(500) DEFAULT NULL, file_driver VARCHAR(20) DEFAULT 'local', created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_office (office_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS case_precedents (case_id INT NOT NULL, precedent_id INT NOT NULL,
        PRIMARY KEY (case_id, precedent_id), INDEX idx_prec (precedent_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

$esc = fn($k) => $conn->real_escape_string(trim($_POST[$k] ?? ''));

/* ── حذف ── */
if (isset($_GET['delete'])) {
    requirePerm('precedents','delete','precedents.php?msg=denied');
    $pid = (int)$_GET['delete'];
    $row = $conn->query("SELECT file_path,file_driver FROM precedents WHERE id=$pid AND office_id=$oid")->fetch_assoc();
    if ($row) {
        if (!empty($row['file_path'])) storage_delete($row['file_path'], $row['file_driver'] ?? 'local', $oid);
        $conn->query("DELETE FROM precedents WHERE id=$pid AND office_id=$oid");
        $conn->query("DELETE FROM case_precedents WHERE precedent_id=$pid");
        logAction($conn, 'delete', 'precedent', $pid, 'حذف سابقة قضائية');
    }
    header("Location: precedents.php?msg=deleted"); exit;
}

/* ── ربط/فك ربط بقضية ── */
if (isset($_GET['link_case'], $_GET['pid'])) {
    requirePerm('precedents','edit','precedents.php?msg=denied');
    $pid = (int)$_GET['pid']; $cid = (int)$_GET['link_case'];
    $okc = $conn->query("SELECT 1 FROM cases WHERE id=$cid AND office_id=$oid");
    $okp = $conn->query("SELECT 1 FROM precedents WHERE id=$pid AND office_id=$oid");
    if ($okc && $okc->num_rows && $okp && $okp->num_rows) {
        $conn->query("INSERT IGNORE INTO case_precedents (case_id,precedent_id) VALUES ($cid,$pid)");
    }
    header("Location: precedents.php?view=$pid&msg=saved"); exit;
}
if (isset($_GET['unlink_case'], $_GET['pid'])) {
    requirePerm('precedents','edit','precedents.php?msg=denied');
    $conn->query("DELETE FROM case_precedents WHERE precedent_id=".(int)$_GET['pid']." AND case_id=".(int)$_GET['unlink_case']);
    header("Location: precedents.php?view=".(int)$_GET['pid']."&msg=saved"); exit;
}

/* ── حفظ ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'precedent') {
    $isEdit = !empty($_POST['id']);
    requirePerm('precedents', $isEdit ? 'edit' : 'add', 'precedents.php?msg=denied');
    $F = [];
    foreach (['title','court','subject','case_type','law_system','article','ruling_number','summary','full_text','keywords','lawyer_notes'] as $k) $F[$k] = $esc($k);
    $rdate = trim($_POST['ruling_date'] ?? '') !== '' ? "'".$conn->real_escape_string($_POST['ruling_date'])."'" : 'NULL';

    // أول ملف يُحفظ في العمود الرئيسي، وأي ملفات إضافية تُضاف كمرفقات تابعة للسابقة
    $precFiles = normalizeFilesArray($_FILES['prec_file'] ?? [], $_POST['prec_file_label'] ?? null);
    $fn = $fp = ''; $fd = 'local';
    if ($precFiles) {
        $up = storage_upload($precFiles[0], 'library', $oid, $_SESSION['office_name'] ?? '');
        if ($up['success']) { $fn = $up['name']; $fp = $up['path']; $fd = $up['driver']; }
    }
    $fn_e = $conn->real_escape_string($fn); $fp_e = $conn->real_escape_string($fp); $fd_e = $conn->real_escape_string($fd);
    $fsql = $fp !== '' ? ",file_name='$fn_e',file_path='$fp_e',file_driver='$fd_e'" : '';

    if ($isEdit) {
        $id = (int)$_POST['id'];
        $conn->query("UPDATE precedents SET title='{$F['title']}',court='{$F['court']}',subject='{$F['subject']}',
            case_type='{$F['case_type']}',law_system='{$F['law_system']}',article='{$F['article']}',
            ruling_number='{$F['ruling_number']}',ruling_date=$rdate,summary='{$F['summary']}',full_text='{$F['full_text']}',
            keywords='{$F['keywords']}',lawyer_notes='{$F['lawyer_notes']}'$fsql WHERE id=$id AND office_id=$oid");
        logAction($conn, 'update', 'precedent', $id, 'تعديل سابقة: ' . mb_substr($F['title'], 0, 80));
    } else {
        $uid = (int)$_SESSION['user_id'];
        $conn->query("INSERT INTO precedents
            (office_id,title,court,subject,case_type,law_system,article,ruling_number,ruling_date,summary,full_text,keywords,lawyer_notes,created_by,file_name,file_path,file_driver)
            VALUES ($oid,'{$F['title']}','{$F['court']}','{$F['subject']}','{$F['case_type']}','{$F['law_system']}','{$F['article']}','{$F['ruling_number']}',$rdate,'{$F['summary']}','{$F['full_text']}','{$F['keywords']}','{$F['lawyer_notes']}',$uid,'$fn_e','$fp_e','$fd_e')");
        $id = $conn->insert_id;
        logAction($conn, 'create', 'precedent', $id, 'سابقة جديدة: ' . mb_substr($F['title'], 0, 80));
    }
    for ($i = 1; $i < count($precFiles); $i++) {
        $up2 = storage_upload($precFiles[$i], 'library', $oid, $_SESSION['office_name'] ?? '');
        if ($up2['success']) {
            $conn->query("INSERT INTO file_attachments
                (office_id,entity_type,entity_id,original_name,stored_name,file_path,file_size,driver,uploaded_by)
                VALUES ($oid,'precedent',$id,'".$conn->real_escape_string($up2['name'])."','".$conn->real_escape_string(basename($up2['path']))."','".$conn->real_escape_string($up2['path'])."',".(int)($precFiles[$i]['size'] ?? 0).",'".$conn->real_escape_string($up2['driver'])."',{$_SESSION['user_id']})");
        }
    }
    header("Location: precedents.php?msg=saved"); exit;
}

/* ── جلب ── */
$where = "p.office_id=$oid";
foreach (['court','subject','case_type','law_system'] as $ff) {
    if (!empty($_GET[$ff])) $where .= " AND p.$ff='".$conn->real_escape_string($_GET[$ff])."'";
}
if (!empty($_GET['q'])) {
    $q = $conn->real_escape_string($_GET['q']);
    $where .= " AND (p.title LIKE '%$q%' OR p.summary LIKE '%$q%' OR p.keywords LIKE '%$q%' OR p.full_text LIKE '%$q%' OR p.ruling_number LIKE '%$q%')";
}
$list = $conn->query("SELECT p.*, (SELECT COUNT(*) FROM case_precedents cp WHERE cp.precedent_id=p.id) linked
    FROM precedents p WHERE $where ORDER BY p.id DESC");

$distinct = fn($col) => $conn->query("SELECT DISTINCT $col v FROM precedents WHERE office_id=$oid AND $col<>'' AND $col IS NOT NULL ORDER BY $col");

$edit = null;
if (isset($_GET['edit'])) $edit = $conn->query("SELECT * FROM precedents WHERE id=".(int)$_GET['edit']." AND office_id=$oid")->fetch_assoc();

$view = null; $linkedCases = []; $officeCases = null;
if (isset($_GET['view'])) {
    $view = $conn->query("SELECT * FROM precedents WHERE id=".(int)$_GET['view']." AND office_id=$oid")->fetch_assoc();
    if ($view) {
        $lc = $conn->query("SELECT c.id,c.case_number,c.title FROM case_precedents cp JOIN cases c ON cp.case_id=c.id
            WHERE cp.precedent_id=".(int)$view['id']." AND c.office_id=$oid");
        if ($lc) while ($x = $lc->fetch_assoc()) $linkedCases[] = $x;
        $officeCases = $conn->query("SELECT id,case_number,title FROM cases WHERE office_id=$oid ORDER BY id DESC");
    }
}

$_can_add = can('precedents','add'); $_can_edit = can('precedents','edit'); $_can_del = can('precedents','delete');
include '../includes/office_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-<?= $_GET['msg']==='denied'?'danger':'success' ?> alert-dismissible fade show">
  <i class="fas fa-<?= $_GET['msg']==='denied'?'ban':'check-circle' ?> me-2"></i>
  <?= $_GET['msg']==='denied' ? 'ليست لديك صلاحية لهذا الإجراء' : ($_GET['msg']==='deleted'?'تم الحذف':'تم الحفظ') ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($view): ?>
<!-- ═══ عرض سابقة ═══ -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="fas fa-scale-balanced me-2 text-primary"></i><?= e($view['title']) ?></h5>
  <div class="d-flex gap-2">
    <?php if ($_can_edit): ?><a href="precedents.php?edit=<?= $view['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit me-1"></i>تعديل</a><?php endif; ?>
    <a href="precedents.php" class="btn btn-sm btn-outline-secondary">رجوع</a>
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-body">
      <table class="table table-sm mb-0" style="font-size:13px">
        <?php foreach (['court'=>'المحكمة','subject'=>'الموضوع','case_type'=>'نوع القضية','law_system'=>'النظام','article'=>'المادة','ruling_number'=>'رقم الحكم'] as $k=>$l): if(!empty($view[$k])): ?>
        <tr><th class="text-muted" style="width:130px"><?= $l ?></th><td><?= e($view[$k]) ?></td></tr>
        <?php endif; endforeach; ?>
        <?php if(!empty($view['ruling_date'])): ?><tr><th class="text-muted">تاريخ الحكم</th><td><?= dDate($view['ruling_date']) ?></td></tr><?php endif; ?>
        <?php if(!empty($view['keywords'])): ?><tr><th class="text-muted">كلمات مفتاحية</th><td><?php foreach(array_filter(array_map('trim',explode(',',$view['keywords']))) as $kw): ?><span class="badge bg-light text-dark border me-1"><?= e($kw) ?></span><?php endforeach; ?></td></tr><?php endif; ?>
      </table>
    </div></div>
    <?php if(!empty($view['summary'])): ?>
    <div class="card mb-3"><div class="card-header fw-bold">الملخّص / المبدأ</div><div class="card-body" style="white-space:pre-wrap;line-height:1.9;font-size:14px"><?= e($view['summary']) ?></div></div>
    <?php endif; ?>
    <?php if(!empty($view['full_text'])): ?>
    <div class="card mb-3"><div class="card-header fw-bold">النص الكامل</div><div class="card-body" style="white-space:pre-wrap;line-height:1.9;font-size:13.5px"><?= e($view['full_text']) ?></div></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-4">
    <?php if(!empty($view['file_path'])): ?>
    <div class="card mb-3"><div class="card-body">
      <strong><i class="fas fa-paperclip me-1 text-primary"></i>مرفق:</strong>
      <?= storage_file_link($conn, 'precedent', $view) ?>
    </div></div>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-header fw-bold">ملاحظات المحامي</div>
      <div class="card-body" style="white-space:pre-wrap;font-size:13px;min-height:60px"><?= e($view['lawyer_notes'] ?: '—') ?></div>
    </div>
    <div class="card"><div class="card-header fw-bold">القضايا المرتبطة</div><div class="card-body">
      <?php foreach ($linkedCases as $lc): ?>
      <div class="d-flex justify-content-between align-items-center py-1" style="font-size:13px;border-bottom:1px solid #f1f5f9">
        <a href="cases.php?edit=<?= $lc['id'] ?>"><?= e($lc['case_number'] . ' — ' . mb_substr($lc['title'],0,40)) ?></a>
        <?php if ($_can_edit): ?><a href="precedents.php?view=<?= $view['id'] ?>&unlink_case=<?= $lc['id'] ?>&pid=<?= $view['id'] ?>" class="text-danger" onclick="return confirm('فك الربط؟')"><i class="fas fa-times"></i></a><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if (!$linkedCases): ?><div class="text-muted" style="font-size:13px">لا قضايا مرتبطة</div><?php endif; ?>
      <?php if ($_can_edit && $officeCases): ?>
      <form class="mt-2 d-flex gap-1" method="GET">
        <input type="hidden" name="view" value="<?= $view['id'] ?>">
        <input type="hidden" name="pid" value="<?= $view['id'] ?>">
        <select name="link_case" class="form-select form-select-sm">
          <option value="">— اربط بقضية —</option>
          <?php while ($c = $officeCases->fetch_assoc()): ?>
          <option value="<?= $c['id'] ?>"><?= e($c['case_number'] . ' — ' . mb_substr($c['title'],0,40)) ?></option>
          <?php endwhile; ?>
        </select>
        <button class="btn btn-sm btn-primary">ربط</button>
      </form>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<?php else: ?>
<!-- ═══ القائمة ═══ -->
<div class="card mb-3"><div class="card-body py-2">
  <form class="row g-2 align-items-end">
    <div class="col-md flex-grow-1">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="بحث: عنوان، مبدأ، كلمة مفتاحية، رقم حكم…" value="<?= e($_GET['q'] ?? '') ?>">
    </div>
    <?php foreach (['court'=>'المحكمة','subject'=>'الموضوع','case_type'=>'نوع القضية','law_system'=>'النظام'] as $ff=>$fl):
      $opts = $distinct($ff); ?>
    <div class="col-auto">
      <select name="<?= $ff ?>" class="form-select form-select-sm">
        <option value=""><?= $fl ?></option>
        <?php if ($opts) while ($o = $opts->fetch_assoc()): ?>
        <option value="<?= e($o['v']) ?>" <?= ($_GET[$ff]??'')===$o['v']?'selected':'' ?>><?= e($o['v']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <?php endforeach; ?>
    <div class="col-auto">
      <button class="btn btn-primary btn-sm">بحث</button>
      <a href="precedents.php" class="btn btn-outline-secondary btn-sm">إعادة</a>
    </div>
  </form>
</div></div>

<?php if ($_can_add): ?>
<div class="d-flex justify-content-end mb-3">
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#precModal"><i class="fas fa-plus me-1"></i>إضافة سابقة</button>
</div>
<?php endif; ?>

<div class="row g-3">
<?php if ($list && $list->num_rows): while ($p = $list->fetch_assoc()): ?>
<div class="col-md-6 col-xl-4">
  <div class="card h-100">
    <div class="card-body">
      <a href="precedents.php?view=<?= $p['id'] ?>" class="fw-bold text-decoration-none" style="font-size:14px"><?= e($p['title']) ?></a>
      <div class="mt-2" style="font-size:12px">
        <?php foreach (['court','subject','case_type'] as $k): if(!empty($p[$k])): ?>
        <span class="badge bg-light text-dark border me-1"><?= e($p[$k]) ?></span>
        <?php endif; endforeach; ?>
      </div>
      <?php if(!empty($p['ruling_number']) || !empty($p['ruling_date'])): ?>
      <div class="text-muted mt-1" style="font-size:11.5px">
        <?= e($p['ruling_number']) ?> <?= !empty($p['ruling_date']) ? ' · ' . dDate($p['ruling_date'], false, false) : '' ?>
      </div>
      <?php endif; ?>
      <?php if(!empty($p['summary'])): ?>
      <div class="text-muted mt-2" style="font-size:12.5px;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden"><?= e($p['summary']) ?></div>
      <?php endif; ?>
    </div>
    <div class="card-footer bg-white d-flex gap-2" style="font-size:12px">
      <span class="text-muted"><i class="fas fa-link me-1"></i><?= (int)$p['linked'] ?> قضية</span>
      <div class="ms-auto d-flex gap-1">
        <?php if ($_can_edit): ?><a href="precedents.php?edit=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a><?php endif; ?>
        <?php if ($_can_del): ?><a href="precedents.php?delete=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذه السابقة؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endwhile; else: ?>
<div class="col-12"><div class="alert alert-light border text-center text-muted">لا توجد سوابق. أضف أول سابقة قضائية لمكتبك.</div></div>
<?php endif; ?>
</div>

<!-- Modal -->
<div class="modal fade" id="precModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-scale-balanced me-2"></i><?= $edit ? 'تعديل سابقة' : 'إضافة سابقة قضائية' ?></h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST" enctype="multipart/form-data">
    <div class="modal-body">
      <input type="hidden" name="form_type" value="precedent">
      <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
      <div class="row g-3">
        <div class="col-12"><label class="form-label fw-semibold">العنوان *</label>
          <input type="text" name="title" class="form-control" required value="<?= e($edit['title'] ?? '') ?>"></div>
        <?php foreach (['court'=>'المحكمة','subject'=>'الموضوع','case_type'=>'نوع القضية','law_system'=>'النظام','article'=>'المادة','ruling_number'=>'رقم الحكم'] as $k=>$l): ?>
        <div class="col-md-4"><label class="form-label fw-semibold"><?= $l ?></label>
          <input type="text" name="<?= $k ?>" class="form-control" value="<?= e($edit[$k] ?? '') ?>"></div>
        <?php endforeach; ?>
        <div class="col-md-4"><label class="form-label fw-semibold">تاريخ الحكم</label>
          <input type="date" name="ruling_date" class="form-control" value="<?= e($edit['ruling_date'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label fw-semibold">الملخّص / المبدأ</label>
          <textarea name="summary" class="form-control" rows="3"><?= e($edit['summary'] ?? '') ?></textarea></div>
        <div class="col-12"><label class="form-label fw-semibold">النص الكامل</label>
          <textarea name="full_text" class="form-control" rows="5"><?= e($edit['full_text'] ?? '') ?></textarea></div>
        <div class="col-md-6"><label class="form-label fw-semibold">كلمات مفتاحية <small class="text-muted">(مفصولة بفاصلة)</small></label>
          <input type="text" name="keywords" class="form-control" value="<?= e($edit['keywords'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">مرفق (PDF/صورة)</label>
          <input type="file" name="prec_file" class="form-control mk-multi" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
          <?php if ($edit && !empty($edit['file_path'])): ?><small class="d-block mt-1"><?= storage_file_link($conn,'precedent',$edit) ?></small><?php endif; ?></div>
        <div class="col-12"><label class="form-label fw-semibold">ملاحظات المحامي</label>
          <textarea name="lawyer_notes" class="form-control" rows="2"><?= e($edit['lawyer_notes'] ?? '') ?></textarea></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
    </div>
  </form>
</div></div></div>
<?php if ($edit): ?>
<script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('precModal')).show();});</script>
<?php endif; ?>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
