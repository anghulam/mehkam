<?php
require_once '../includes/functions.php';
require_once '../includes/storage.php';
require_once '../config/db.php';
requireOffice();
if (!can('library','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'المكتبة القانونية';
$oid = (int)$_SESSION['office_id'];

// التحقق من ميزة المكتبة
if (!hasFeature($conn, $oid, 'has_library')) {
    header("Location: profile.php?tab=upgrade&feature=library"); exit;
}

if (isset($_GET['delete'])) {
    $conn->query("DELETE FROM library WHERE id=".(int)$_GET['delete']." AND (office_id=$oid OR office_id IS NULL)");
    header("Location: library.php?msg=deleted"); exit;
}

// عمود ناشر — للعرض بين المكاتب
try { $conn->query("ALTER TABLE library ADD COLUMN file_size INT DEFAULT 0"); } catch (\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title   = $conn->real_escape_string($_POST['title']);
    $cat     = $conn->real_escape_string($_POST['category']);
    $content = $conn->real_escape_string($_POST['content'] ?? '');
    $pub     = isset($_POST['is_public']) ? 1 : 0;

    // رفع الملف(ات) — أول ملف يُحفظ في العمود الرئيسي، وأي ملفات إضافية تُضاف كمرفقات تابعة للمادة
    $libFiles = normalizeFilesArray($_FILES['lib_file'] ?? [], $_POST['lib_file_label'] ?? null);
    $fn=''; $fp=''; $fd='local'; $furl=''; $fsize=0;
    if ($libFiles) {
        $up = storage_upload($libFiles[0], 'library', $oid, $_SESSION['office_name'] ?? '');
        if ($up['success']) {
            $fn   = $conn->real_escape_string($up['name']);
            $fp   = $conn->real_escape_string($up['path']);
            $fd   = $conn->real_escape_string($up['driver']);
            $furl = $conn->real_escape_string($up['url'] ?? '');
            $fsize = (int)($libFiles[0]['size'] ?? 0);
        }
    }

    if (!empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $fsql = $fp ? ",file_name='$fn',file_path='$fp',file_driver='$fd',file_url='$furl',file_size=$fsize" : '';
        $conn->query("UPDATE library SET title='$title',category='$cat',content='$content',is_public=$pub$fsql WHERE id=$id AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO library (office_id,title,category,content,is_public,file_name,file_path,file_driver,file_url,file_size) VALUES ($oid,'$title','$cat','$content',$pub,'$fn','$fp','$fd','$furl',$fsize)");
        $id = $conn->insert_id;
    }
    // أي ملفات إضافية (بعد الأول) تُحفَظ كمرفقات تابعة لهذه المادة
    for ($i = 1; $i < count($libFiles); $i++) {
        $up2 = storage_upload($libFiles[$i], 'library', $oid, $_SESSION['office_name'] ?? '');
        if ($up2['success']) {
            $conn->query("INSERT INTO file_attachments
                (office_id,entity_type,entity_id,original_name,stored_name,file_path,file_size,driver,uploaded_by)
                VALUES ($oid,'library',$id,'".$conn->real_escape_string($up2['name'])."','".$conn->real_escape_string(basename($up2['path']))."','".$conn->real_escape_string($up2['path'])."',".(int)($libFiles[$i]['size'] ?? 0).",'".$conn->real_escape_string($up2['driver'])."',{$_SESSION['user_id']})");
        }
    }
    header("Location: library.php?msg=saved"); exit;
}

// يرى المكتب: مواده + المواد العامة من المنصة + المواد العامة من مكاتب أخرى
$where = "(office_id=$oid OR is_public=1)";
if (!empty($_GET['cat_f'])) $where .= " AND category='".$conn->real_escape_string($_GET['cat_f'])."'";
if (!empty($_GET['q'])) {
    $q = $conn->real_escape_string($_GET['q']);
    $where .= " AND title LIKE '%$q%'";
}
// نسخة بأسماء معرّفة لاستعلام الربط
$where_l = str_replace(['office_id=', 'is_public='], ['l.office_id=', 'l.is_public='], $where);
$where_l = str_replace('category=', 'l.category=', $where_l);
$where_l = str_replace('title LIKE', 'l.title LIKE', $where_l);
$LIB_SELECT = "SELECT l.*, o.name AS pub_name, os.office_logo AS pub_logo
    FROM library l
    LEFT JOIN offices o ON l.office_id = o.id
    LEFT JOIN office_settings os ON os.office_id = l.office_id
    WHERE $where_l";

$cats  = $conn->query("SELECT DISTINCT category FROM library WHERE $where ORDER BY category");

$edit = null;
$view = null;
if (isset($_GET['edit'])) $edit = $conn->query("SELECT * FROM library WHERE id=".(int)$_GET['edit']." AND office_id=$oid")->fetch_assoc();
if (isset($_GET['view'])) $view = $conn->query($LIB_SELECT . " AND l.id=".(int)$_GET['view']." LIMIT 1")->fetch_assoc();

include '../includes/office_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($view): ?>
<!-- عرض محتوى -->
<div class="card mb-3">
  <div class="card-header">
    <span><i class="fas fa-book-open me-2"></i><?= e($view['title']) ?></span>
    <a href="library.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-right me-1"></i>رجوع</a>
  </div>
  <div class="card-body">
    <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
      <span class="badge bg-primary"><?= e($view['category']) ?></span>
      <?php if($view['is_public'] && (int)$view['office_id']===$oid): ?><span class="badge bg-success">منشورة للمكاتب</span><?php endif; ?>
      <?php
        $v_other = ((int)$view['office_id'] !== $oid && !empty($view['office_id']));
        $v_logo  = $view['pub_logo'] ?? '';
      ?>
      <?php if($v_other): ?>
      <span class="d-inline-flex align-items-center gap-2 px-2 py-1 rounded" style="background:#f1f5f9;font-size:12px;color:#475569">
        <?php if($v_logo && file_exists('../'.$v_logo)): ?><img src="../<?= e($v_logo) ?>" style="width:18px;height:18px;object-fit:contain"><?php else: ?><i class="fas fa-building"></i><?php endif; ?>
        نُشرت بواسطة <b><?= e($view['pub_name'] ?: 'مكتب') ?></b>
      </span>
      <?php elseif(empty($view['office_id'])): ?>
      <span class="badge bg-dark"><i class="fas fa-shield-halved me-1"></i>مكتبة المنصة</span>
      <?php endif; ?>
    </div>
    <?php if(!empty($view['file_url'])): ?>
    <div class="mb-3 p-3 border rounded" style="background:#f8f9fa">
      <strong><i class="fas fa-link me-1 text-primary"></i>رابط الملف:</strong>
      <a href="<?= e($view['file_url']) ?>" target="_blank" rel="noopener" class="text-primary"><?= e($view['file_name'] ?: $view['file_url']) ?></a>
    </div>
    <?php elseif(!empty($view['file_path'])): ?>
    <div class="mb-3 p-3 border rounded" style="background:#f8f9fa">
      <strong><i class="fas fa-paperclip me-1 text-primary"></i>الملف المرفق:</strong>
      <?= storage_file_link($conn, 'library', $view) ?>
    </div>
    <?php endif; ?>
    <?php if(!empty($view['content'])): ?>
    <div style="line-height:2;font-size:15px"><?= nl2br(e($view['content'])) ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- فلتر -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="GET">
      <div class="col-auto flex-grow-1">
        <input type="text" name="q" class="form-control" placeholder="بحث في المكتبة..." value="<?= e($_GET['q'] ?? '') ?>">
      </div>
      <div class="col-auto">
        <select name="cat_f" class="form-select">
          <option value="">جميع التصنيفات</option>
          <?php while($cat = $cats->fetch_assoc()): ?>
          <option value="<?= e($cat['category']) ?>" <?= ($_GET['cat_f']??'')===$cat['category']?'selected':'' ?>><?= e($cat['category']) ?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary"><i class="fas fa-search me-1"></i>بحث</button>
        <a href="library.php" class="btn btn-outline-secondary ms-1">إعادة</a>
      </div>
      <div class="col-auto">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#libModal">
          <i class="fas fa-plus me-1"></i>إضافة
        </button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3">
<?php
$items2 = $conn->query($LIB_SELECT . " ORDER BY l.id DESC");
while($item = $items2->fetch_assoc()):
  $cat_icons = ['قوانين'=>'gavel','نماذج'=>'file-alt','أحكام'=>'balance-scale','مقالات'=>'newspaper','أخرى'=>'book'];
  $ico = $cat_icons[$item['category']] ?? 'book-open';
  $is_mine  = ((int)$item['office_id'] === $oid);
  $is_other = (!$is_mine && !empty($item['office_id']));      // منشورة من مكتب آخر
  $pub_logo = $item['pub_logo'] ?? '';
?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100">
    <div class="card-body">
      <div class="d-flex align-items-start gap-3">
        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center" style="width:44px;height:44px;font-size:18px;flex-shrink:0">
          <i class="fas fa-<?= $ico ?>"></i>
        </div>
        <div class="flex-grow-1">
          <h6 class="fw-bold mb-1"><?= e($item['title']) ?></h6>
          <span class="badge bg-secondary mb-2"><?= e($item['category']) ?></span>
          <?php if($item['is_public'] && $is_mine): ?><span class="badge bg-success mb-2 ms-1">منشورة</span><?php endif; ?>
          <?php if(!empty($item['file_path']) || !empty($item['file_url'])): ?><span class="badge bg-info mb-2 ms-1"><i class="fas fa-paperclip"></i> مرفق</span><?php endif; ?>
          <?php if($item['content']): ?>
          <p class="text-muted mb-0" style="font-size:12px"><?= e(mb_substr($item['content'],0,100)) ?><?= mb_strlen($item['content'])>100?'…':'' ?></p>
          <?php endif; ?>
        </div>
      </div>
      <?php if($is_other): ?>
      <div class="d-flex align-items-center gap-2 mt-3 pt-2 border-top" style="font-size:11.5px;color:#64748b">
        <?php if($pub_logo && file_exists('../'.$pub_logo)): ?>
        <img src="../<?= e($pub_logo) ?>" alt="" style="width:20px;height:20px;object-fit:contain;border-radius:4px">
        <?php else: ?>
        <i class="fas fa-building"></i>
        <?php endif; ?>
        <span>نُشرت بواسطة: <b><?= e($item['pub_name'] ?: 'مكتب') ?></b></span>
      </div>
      <?php elseif(empty($item['office_id'])): ?>
      <div class="mt-3 pt-2 border-top" style="font-size:11.5px;color:#64748b"><i class="fas fa-shield-halved me-1"></i>من مكتبة منصة مِحكام</div>
      <?php endif; ?>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
      <a href="library.php?view=<?= $item['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill"><i class="fas fa-eye me-1"></i>عرض</a>
      <?php if($is_mine): ?>
      <a href="library.php?edit=<?= $item['id'] ?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-edit"></i></a>
      <a href="library.php?delete=<?= $item['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف؟')"><i class="fas fa-trash"></i></a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endwhile; ?>
</div>

<!-- Modal -->
<div class="modal fade" id="libModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-book-open me-2"></i><?= $edit ? 'تعديل' : 'إضافة' ?> مادة قانونية</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <div class="modal-body">
          <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">العنوان *</label>
              <input type="text" name="title" class="form-control" required value="<?= e($edit['title'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">التصنيف</label>
              <input type="text" name="category" class="form-control" list="cat_list" placeholder="قوانين / نماذج / أحكام ..." value="<?= e($edit['category'] ?? '') ?>">
              <datalist id="cat_list">
                <option value="قوانين"><option value="نماذج"><option value="أحكام"><option value="مقالات"><option value="أخرى">
              </datalist>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">المحتوى <small class="text-muted">(أو ارفع ملفاً بدلاً منه)</small></label>
              <textarea name="content" class="form-control" rows="8"><?= e($edit['content'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold"><i class="fas fa-paperclip me-1"></i>رفع ملف <small class="text-muted">(PDF / Word / صورة — حتى 20MB)</small></label>
              <input type="file" name="lib_file" class="form-control mk-multi" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.txt">
              <?php if($edit && !empty($edit['file_path'])): ?>
              <div class="small text-muted mt-1">المرفق الحالي: <?= storage_file_link($conn, 'library', $edit) ?> — ارفع ملفاً جديداً لاستبداله</div>
              <?php endif; ?>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_public" id="is_pub" <?= ($edit['is_public'] ?? 0) ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_pub">نشر للمكاتب الأخرى — سيظهر اسم مكتبك وشعاره على المادة عند اطّلاعهم عليها</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($edit): ?>
<script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('libModal')).show();});</script>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
