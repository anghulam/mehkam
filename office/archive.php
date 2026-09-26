<?php
require_once '../includes/functions.php';
require_once '../includes/storage.php';
require_once '../config/db.php';
requireOffice();
if (!can('archive','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'الأرشيف الإلكتروني';
$oid = (int)$_SESSION['office_id'];

// التحقق من الإذن
if (!hasFeature($conn, $oid, 'has_archive')) {
    header("Location: dashboard.php?msg=feature_locked"); exit;
}

/* ── معالجة الحذف ── */
if (isset($_GET['delete'])) {
    // حذف من file_attachments أيضاً
    $item = $conn->query("SELECT * FROM archive WHERE id=".(int)$_GET['delete']." AND office_id=$oid")->fetch_assoc();
    if ($item) {
        storage_delete($item['file_path'] ?? '', $item['file_driver'] ?? 'local');
        $conn->query("DELETE FROM archive WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    }
    header("Location: archive.php?msg=deleted"); exit;
}

/* ── حفظ سجل ── */
$upload_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title  = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $cat    = $conn->real_escape_string(trim($_POST['category'] ?? ''));
    $desc   = $conn->real_escape_string($_POST['description'] ?? '');
    $edit_id = (int)($_POST['id'] ?? 0);
    $storage_mb = getFeatureLimit($conn, $oid, 'storage_mb');

    // يقبل هذا الحقل عدة ملفات دفعة واحدة (name="file[]") — يرفع كل ملف عبر طبقة
    // التخزين (تحترم إعدادات المكتب + التشفير) ويسجّله كمرفق عام + سجل أرشيف مستقل
    $files = normalizeFilesArray($_FILES['file'] ?? [], $_POST['file_label'] ?? null);

    if ($edit_id) {
        // تعديل سجل قائم: أول ملف مُختار (إن وُجد) يستبدل ملف السجل الحالي
        $fname = $fsize_str = $fpath = $fdriver = '';
        if ($files) {
            $used_mb = getStorageUsedMB($conn, $oid);
            $file_mb = ($files[0]['size'] ?? 0) / (1024 * 1024);
            if ($storage_mb > 0 && ($used_mb + $file_mb) > $storage_mb) {
                $upload_error = 'تجاوزت سعة التخزين المتاحة لباقتك';
            } else {
                $up = storage_upload($files[0], 'archive', $oid, $_SESSION['office_name'] ?? '');
                if ($up['success']) {
                    $fsize_bytes = (int)($files[0]['size'] ?? 0);
                    $fname       = $conn->real_escape_string($up['name']);
                    $fsize_str   = $conn->real_escape_string(formatSize($fsize_bytes));
                    $fpath       = $conn->real_escape_string($up['path']);
                    $fdriver     = $conn->real_escape_string($up['driver']);
                    $stored_esc  = $conn->real_escape_string(basename($up['path']));
                    $conn->query("INSERT INTO file_attachments
                        (office_id,entity_type,entity_id,original_name,stored_name,file_path,file_size,driver,uploaded_by)
                        VALUES ($oid,'general',0,'$fname','$stored_esc','$fpath',$fsize_bytes,'$fdriver',{$_SESSION['user_id']})");
                } else {
                    $upload_error = $up['error'] ?: 'فشل رفع الملف، تأكد من صلاحيات المجلد';
                }
            }
        }
        if (!$upload_error) {
            $file_sql = $fpath ? ",file_name='$fname',file_size='$fsize_str',file_path='$fpath',file_driver='$fdriver'" : "";
            $conn->query("UPDATE archive SET title='$title',category='$cat',description='$desc'$file_sql WHERE id=$edit_id AND office_id=$oid");
            header("Location: archive.php?msg=saved"); exit;
        }
    } elseif (!$files) {
        $upload_error = 'اختر ملفاً واحداً على الأقل';
    } else {
        // إنشاء سجلات جديدة — كل ملف يُنشئ سجل أرشيف مستقلاً (العنوان والتصنيف والوصف مشتركة)
        $multi = count($files) > 1;
        $created = 0;
        foreach ($files as $f) {
            $used_mb = getStorageUsedMB($conn, $oid);
            $file_mb = ($f['size'] ?? 0) / (1024 * 1024);
            if ($storage_mb > 0 && ($used_mb + $file_mb) > $storage_mb) { $upload_error = 'تجاوزت سعة التخزين المتاحة لباقتك'; break; }
            $up = storage_upload($f, 'archive', $oid, $_SESSION['office_name'] ?? '');
            if (!$up['success']) { $upload_error = $up['error'] ?: 'فشل رفع أحد الملفات، تأكد من صلاحيات المجلد'; break; }
            $fsize_bytes = (int)($f['size'] ?? 0);
            $fname      = $conn->real_escape_string($up['name']);
            $fsize_str  = $conn->real_escape_string(formatSize($fsize_bytes));
            $fpath      = $conn->real_escape_string($up['path']);
            $fdriver    = $conn->real_escape_string($up['driver']);
            $stored_esc = $conn->real_escape_string(basename($up['path']));
            $conn->query("INSERT INTO file_attachments
                (office_id,entity_type,entity_id,original_name,stored_name,file_path,file_size,driver,uploaded_by)
                VALUES ($oid,'general',0,'$fname','$stored_esc','$fpath',$fsize_bytes,'$fdriver',{$_SESSION['user_id']})");
            $rowTitle = $multi ? $conn->real_escape_string((trim($_POST['title'] ?? '') !== '' ? trim($_POST['title']) . ' — ' : '') . $f['name']) : $title;
            $conn->query("INSERT INTO archive (office_id,title,category,file_name,file_size,file_path,file_driver,description)
                VALUES ($oid,'$rowTitle','$cat','$fname','$fsize_str','$fpath','$fdriver','$desc')");
            $created++;
        }
        if ($created && !$upload_error) { header("Location: archive.php?msg=saved"); exit; }
    }
}

/* ── جلب البيانات ── */
$where = "office_id=$oid";
if (!empty($_GET['cat_f'])) $where .= " AND category='".$conn->real_escape_string($_GET['cat_f'])."'";
if (!empty($_GET['q'])) {
    $q = $conn->real_escape_string($_GET['q']);
    $where .= " AND (title LIKE '%$q%' OR file_name LIKE '%$q%')";
}

$items = $conn->query("SELECT * FROM archive WHERE $where ORDER BY id DESC");
$total = (int)dbVal($conn,"SELECT COUNT(*) FROM archive WHERE office_id=$oid");
$total_size_mb = getStorageUsedMB($conn, $oid);
$storage_limit = getFeatureLimit($conn, $oid, 'storage_mb');

$cats = $conn->query("SELECT DISTINCT category FROM archive WHERE office_id=$oid ORDER BY category");

$edit = null;
if (isset($_GET['edit'])) {
    $edit = $conn->query("SELECT * FROM archive WHERE id=".(int)$_GET['edit']." AND office_id=$oid")->fetch_assoc();
}

// إحصائيات التصنيفات
$cat_stats = [];
$q_cats = $conn->query("SELECT category, COUNT(*) cnt FROM archive WHERE office_id=$oid GROUP BY category ORDER BY cnt DESC");
while ($r = $q_cats->fetch_assoc()) $cat_stats[] = $r;

include '../includes/office_header.php';
?>

<?php if ($upload_error): ?>
<div class="alert alert-danger alert-dismissible mb-3">
  <i class="fas fa-times-circle me-2"></i><?= e($upload_error) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="mk-page-hdr">
  <div>
    <div class="mk-page-title"><i class="fas fa-box-archive"></i> الأرشيف الإلكتروني</div>
    <div class="mk-page-sub"><?= $total ?> ملف · <?= $total_size_mb ?> MB مستخدم<?= $storage_limit > 0 ? " من {$storage_limit} MB" : '' ?></div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#archModal">
    <i class="fas fa-cloud-upload-alt"></i> رفع ملف جديد
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="mk-stat info">
      <div class="mk-stat-icon info"><i class="fas fa-files"></i></div>
      <div class="mk-stat-body">
        <div class="mk-stat-val"><?= $total ?></div>
        <div class="mk-stat-lbl">إجمالي الملفات</div>
      </div>
    </div>
  </div>
  <?php foreach (array_slice($cat_stats, 0, 3) as $cs): ?>
  <div class="col-6 col-md-3">
    <div class="mk-stat gold">
      <div class="mk-stat-icon gold"><i class="fas fa-folder-open"></i></div>
      <div class="mk-stat-body">
        <div class="mk-stat-val"><?= $cs['cnt'] ?></div>
        <div class="mk-stat-lbl"><?= e($cs['category']) ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- مؤشر التخزين -->
<?php if ($storage_limit > 0): ?>
<?php $storage_pct = min(100, round($total_size_mb / $storage_limit * 100)); ?>
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="row align-items-center g-2">
      <div class="col-auto"><i class="fas fa-hard-drive text-primary"></i></div>
      <div class="col">
        <div class="d-flex justify-content-between mb-1" style="font-size:12px;color:var(--mk-t3)">
          <span>مساحة التخزين</span>
          <span><?= $total_size_mb ?> / <?= $storage_limit ?> MB</span>
        </div>
        <div class="progress" style="height:6px">
          <div class="progress-bar bg-<?= $storage_pct>80?'danger':($storage_pct>60?'warning':'primary') ?>"
               style="width:<?= $storage_pct ?>%"></div>
        </div>
      </div>
      <div class="col-auto">
        <span class="badge bg-primary-subtle text-primary"><?= $storage_pct ?>%</span>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="GET">
      <div class="col-auto flex-grow-1">
        <input type="text" name="q" class="form-control" placeholder="بحث بعنوان أو اسم الملف..."
               value="<?= e($_GET['q'] ?? '') ?>">
      </div>
      <div class="col-auto">
        <select name="cat_f" class="form-select">
          <option value="">جميع التصنيفات</option>
          <?php
          $cats->data_seek(0);
          while ($cat = $cats->fetch_assoc()):
          ?>
          <option value="<?= e($cat['category']) ?>" <?= (($_GET['cat_f'] ?? '') === $cat['category']) ? 'selected' : '' ?>>
            <?= e($cat['category']) ?>
          </option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary"><i class="fas fa-search"></i></button>
        <a href="archive.php" class="btn btn-outline-secondary ms-1"><i class="fas fa-undo"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Files Grid View -->
<?php if ($items->num_rows > 0): ?>
<div class="row g-3">
<?php while ($item = $items->fetch_assoc()):
  [$iconClass, $iconName] = fileTypeIcon($item['file_name'] ?? '');
  $has_file = !empty($item['file_path']);
?>
<div class="col-12 col-md-6 col-xl-4">
  <div class="card h-100" style="transition:var(--t)">
    <div class="card-body">
      <div class="d-flex align-items-start gap-3">
        <div class="mk-file-icon <?= $iconClass ?>" style="width:48px;height:48px;font-size:22px;flex-shrink:0">
          <i class="fas <?= $iconName ?>"></i>
        </div>
        <div style="flex:1;min-width:0">
          <div class="fw-bold" style="font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= e($item['title']) ?>
          </div>
          <div style="font-size:12px;margin-top:3px">
            <span class="badge bg-primary-subtle text-primary"><?= e($item['category']) ?></span>
            <?php if ($item['file_size']): ?>
            <span class="text-muted ms-1"><?= e($item['file_size']) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($item['description']): ?>
          <div class="text-muted mt-1" style="font-size:12px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">
            <?= e($item['description']) ?>
          </div>
          <?php endif; ?>
          <div class="text-muted mt-1" style="font-size:11px">
            <i class="fas fa-calendar-alt me-1"></i><?= dDate($item['created_at']) ?>
          </div>
        </div>
      </div>
    </div>
    <div class="card-footer py-2">
      <div class="d-flex gap-2 justify-content-end">
        <?php if ($has_file): ?>
        <a href="file.php?t=archive&id=<?= (int)$item['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="عرض">
          <i class="fas fa-eye"></i>
        </a>
        <a href="file.php?t=archive&id=<?= (int)$item['id'] ?>&dl=1" class="btn btn-sm btn-outline-secondary" title="تحميل">
          <i class="fas fa-download"></i>
        </a>
        <?php endif; ?>
        <a href="archive.php?edit=<?= $item['id'] ?>" class="btn btn-sm btn-outline-warning" title="تعديل">
          <i class="fas fa-edit"></i>
        </a>
        <a href="archive.php?delete=<?= $item['id'] ?>" class="btn btn-sm btn-outline-danger"
           data-confirm="هل تريد حذف هذا الملف نهائياً؟" title="حذف">
          <i class="fas fa-trash"></i>
        </a>
      </div>
    </div>
  </div>
</div>
<?php endwhile; ?>
</div>
<?php else: ?>
<div class="card">
  <div class="card-body">
    <div class="mk-empty">
      <div class="mk-empty-icon"><i class="fas fa-folder-open"></i></div>
      <div class="mk-empty-title">الأرشيف فارغ</div>
      <div class="mk-empty-desc">ابدأ بإضافة الوثائق والملفات لأرشيف مكتبك</div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ Modal رفع الملف ═══ -->
<div class="modal fade" id="archModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-cloud-upload-alt me-2"></i>
          <?= $edit ? 'تعديل سجل' : 'رفع ملف جديد' ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <div class="modal-body row g-3">
          <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

          <div class="col-12">
            <label class="form-label">العنوان *</label>
            <input type="text" name="title" class="form-control" required value="<?= e($edit['title'] ?? '') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">التصنيف</label>
            <input type="text" name="category" class="form-control" list="arch_cats"
                   value="<?= e($edit['category'] ?? '') ?>" placeholder="أحكام / عقود / محاضر...">
            <datalist id="arch_cats">
              <option value="أحكام"><option value="عقود"><option value="محاضر">
              <option value="مستندات"><option value="صور"><option value="تقارير"><option value="أخرى">
            </datalist>
          </div>
          <div class="col-12">
            <label class="form-label">الملف <small class="text-muted">(PDF, Word, Excel, صورة — حتى 50MB لكل ملف)</small></label>
            <!-- Drag & Drop Zone -->
            <div class="mk-dropzone p-4 text-center" style="border:2px dashed var(--mk-border2);border-radius:var(--r2);cursor:pointer;transition:var(--t)">
              <i class="fas fa-cloud-upload-alt" style="font-size:28px;color:var(--mk-t4)"></i>
              <div class="mk-dropzone-label mt-2" style="font-size:13px;color:var(--mk-t3)">
                اسحب ملفاً أو عدة ملفات هنا أو اضغط للاختيار
              </div>
              <input type="file" name="file[]" class="d-none" <?= $edit ? '' : 'multiple' ?>
                     accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip,.rar,.txt">
            </div>
            <?php if (!$edit): ?>
            <div class="form-text">اختيار عدة ملفات دفعة واحدة ينشئ سجل أرشيف مستقلاً لكل ملف بنفس العنوان/التصنيف/الوصف.</div>
            <?php endif; ?>
            <?php if ($edit && !empty($edit['file_path'])): ?>
            <div class="mt-2 p-2" style="background:#f0fdf4;border-radius:var(--r);font-size:12.5px">
              <i class="fas fa-check-circle text-success me-1"></i>
              ملف محفوظ: <a href="file.php?t=archive&id=<?= (int)$edit['id'] ?>" target="_blank" class="text-primary"><?= e($edit['file_name']) ?></a>
              (الرفع الجديد سيستبدله)
            </div>
            <?php endif; ?>
          </div>
          <div class="col-12">
            <label class="form-label">الوصف</label>
            <textarea name="description" class="form-control" rows="2"><?= e($edit['description'] ?? '') ?></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.mk-dropzone.dragover { border-color: var(--mk-gold3) !important; background: var(--mk-gold-bg); }
.mk-dropzone.has-file { border-color: var(--c-success) !important; background: rgba(5,150,105,.05); }
.mk-dropzone.has-file .mk-dropzone-label { color: var(--c-success); font-weight: 600; }
</style>

<?php if ($edit): ?>
<script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('archModal')).show();});</script>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
