<?php
require_once '../includes/functions.php';
require_once '../includes/storage.php';
require_once '../config/db.php';
requireOffice();
if (!can('correspondence','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'الصادر والوارد';
$oid = (int)$_SESSION['office_id'];

if (!hasFeature($conn, $oid, 'has_correspondence')) {
    header("Location: profile.php?tab=upgrade&feature=correspondence"); exit;
}

// إضافة عمود notes إن لم يكن موجوداً — يجب أن يسبق أي INSERT/UPDATE يستخدمه
try { $conn->query("ALTER TABLE correspondence ADD COLUMN notes TEXT DEFAULT NULL"); } catch (\Throwable $e) {}

/* ── حذف ── */
if (isset($_GET['delete'])) {
    $conn->query("DELETE FROM correspondence WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    $back = $_GET['tab'] ?? 'incoming';
    header("Location: correspondence.php?tab=$back&msg=deleted"); exit;
}

/* ── تعليم مقروء ── */
if (isset($_GET['mark_read'])) {
    $conn->query("UPDATE correspondence SET status='read' WHERE id=".(int)$_GET['mark_read']." AND office_id=$oid");
    header("Location: correspondence.php?tab=incoming&open=".(int)$_GET['mark_read']); exit;
}

/* ── حفظ / تعديل ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ref    = $conn->real_escape_string($_POST['reference_number'] ?? '');
    $subj   = $conn->real_escape_string($_POST['subject'] ?? '');
    $type   = $conn->real_escape_string($_POST['type'] ?? 'incoming');
    $from   = $conn->real_escape_string($_POST['from_entity'] ?? '');
    $to     = $conn->real_escape_string($_POST['to_entity'] ?? '');
    $cdate  = $conn->real_escape_string(trim($_POST['correspondence_date'] ?? '') !== '' ? $_POST['correspondence_date'] : date('Y-m-d'));
    $status = $conn->real_escape_string($_POST['status'] ?? 'new');
    $notes  = $conn->real_escape_string($_POST['notes'] ?? '');

    // أول ملف يُحفظ في العمود الرئيسي، وأي ملفات إضافية تُضاف كمرفقات تابعة لهذه المراسلة
    $corrFiles = normalizeFilesArray($_FILES['corr_file'] ?? [], $_POST['corr_file_label'] ?? null);
    $file_name = ''; $file_path = ''; $file_driver = 'local';
    if ($corrFiles) {
        $up = storage_upload($corrFiles[0], 'correspondence', $oid, $_SESSION['office_name'] ?? '');
        if ($up['success']) {
            $file_name   = $conn->real_escape_string($up['name']);
            $file_path   = $conn->real_escape_string($up['path']);
            $file_driver = $conn->real_escape_string($up['driver']);
        }
    }

    if (!empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $file_sql = $file_path ? ",file_name='$file_name',file_path='$file_path',file_driver='$file_driver'" : '';
        $conn->query("UPDATE correspondence SET reference_number='$ref',subject='$subj',type='$type',from_entity='$from',to_entity='$to',correspondence_date='$cdate',status='$status',notes='$notes'$file_sql WHERE id=$id AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO correspondence (office_id,reference_number,subject,type,from_entity,to_entity,correspondence_date,status,notes,file_name,file_path,file_driver) VALUES ($oid,'$ref','$subj','$type','$from','$to','$cdate','$status','$notes','$file_name','$file_path','$file_driver')");
        $id = $conn->insert_id;
    }
    for ($i = 1; $i < count($corrFiles); $i++) {
        $up2 = storage_upload($corrFiles[$i], 'correspondence', $oid, $_SESSION['office_name'] ?? '');
        if ($up2['success']) {
            $conn->query("INSERT INTO file_attachments
                (office_id,entity_type,entity_id,original_name,stored_name,file_path,file_size,driver,uploaded_by)
                VALUES ($oid,'correspondence',$id,'".$conn->real_escape_string($up2['name'])."','".$conn->real_escape_string(basename($up2['path']))."','".$conn->real_escape_string($up2['path'])."',".(int)($corrFiles[$i]['size'] ?? 0).",'".$conn->real_escape_string($up2['driver'])."',{$_SESSION['user_id']})");
        }
    }
    $redir_tab = ($type === 'outgoing') ? 'outgoing' : 'incoming';
    header("Location: correspondence.php?tab=$redir_tab&msg=saved"); exit;
}

/* ── التبويب والبحث ── */
$tab    = in_array($_GET['tab'] ?? '', ['outgoing']) ? 'outgoing' : 'incoming';
$open   = isset($_GET['open']) ? (int)$_GET['open'] : 0;
$q_val  = trim($_GET['q'] ?? '');
$st_val = trim($_GET['st'] ?? '');

$where = "office_id=$oid AND type='$tab'";
if ($q_val !== '') {
    $qe = $conn->real_escape_string($q_val);
    $where .= " AND (subject LIKE '%$qe%' OR reference_number LIKE '%$qe%' OR from_entity LIKE '%$qe%' OR to_entity LIKE '%$qe%')";
}
if ($st_val !== '') {
    $ste = $conn->real_escape_string($st_val);
    $where .= " AND status='$ste'";
}

$corrs = $conn->query("SELECT * FROM correspondence WHERE $where ORDER BY correspondence_date DESC, id DESC");

/* ── الرسالة المفتوحة ── */
$opened = null;
if ($open) {
    $opened = $conn->query("SELECT * FROM correspondence WHERE id=$open AND office_id=$oid")->fetch_assoc();
    // تعليم كمقروء تلقائياً
    if ($opened && $opened['status'] === 'new') {
        $conn->query("UPDATE correspondence SET status='read' WHERE id=$open");
        $opened['status'] = 'read';
    }
}

/* ── الأعداد ── */
$cnt_in  = (int)$conn->query("SELECT COUNT(*) c FROM correspondence WHERE office_id=$oid AND type='incoming'")->fetch_assoc()['c'];
$cnt_out = (int)$conn->query("SELECT COUNT(*) c FROM correspondence WHERE office_id=$oid AND type='outgoing'")->fetch_assoc()['c'];
$cnt_new = (int)$conn->query("SELECT COUNT(*) c FROM correspondence WHERE office_id=$oid AND type='incoming' AND status='new'")->fetch_assoc()['c'];

/* ── للتعديل ── */
$edit = null;
if (isset($_GET['edit'])) $edit = $conn->query("SELECT * FROM correspondence WHERE id=".(int)$_GET['edit']." AND office_id=$oid")->fetch_assoc();

/* ── دالة مساعدة ── */
function statusLabel($s) {
    return ['new'=>'جديد','read'=>'مقروء','replied'=>'رُدّ عليه','archived'=>'مؤرشف'][$s] ?? $s;
}
function statusColor($s) {
    return ['new'=>'#f59e0b','read'=>'#6b7280','replied'=>'#10b981','archived'=>'#8b5cf6'][$s] ?? '#6b7280';
}

include '../includes/office_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show py-2">
  <i class="fas fa-check-circle me-2"></i><?= $_GET['msg']==='deleted'?'تم الحذف بنجاح':'تم الحفظ بنجاح' ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<style>
/* ── Inbox Layout ── */
.inbox-wrap { display:flex; gap:0; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; background:#fff; min-height:580px; }

/* Sidebar */
.inbox-sidebar { width:300px; min-width:300px; border-left:1px solid #e5e7eb; display:flex; flex-direction:column; }
.inbox-tabs { display:flex; border-bottom:1px solid #e5e7eb; }
.inbox-tab { flex:1; padding:13px 8px; text-align:center; font-size:13px; font-weight:600; cursor:pointer; color:#6b7280; border:none; background:none; border-bottom:3px solid transparent; transition:.2s; }
.inbox-tab.active { color:#1d4ed8; border-bottom-color:#1d4ed8; background:#eff6ff; }
.inbox-tab .badge-count { background:#dc2626; color:#fff; border-radius:99px; font-size:10px; padding:1px 6px; margin-right:4px; }

.inbox-search { padding:10px; border-bottom:1px solid #f3f4f6; }
.inbox-search input { width:100%; border:1px solid #e5e7eb; border-radius:8px; padding:7px 12px; font-size:13px; outline:none; background:#f9fafb; }
.inbox-search input:focus { border-color:#93c5fd; background:#fff; }

.inbox-list { flex:1; overflow-y:auto; }
.inbox-item { display:flex; align-items:flex-start; gap:10px; padding:13px 14px; border-bottom:1px solid #f3f4f6; cursor:pointer; transition:background .15s; text-decoration:none; color:inherit; }
.inbox-item:hover { background:#f8fafc; }
.inbox-item.active { background:#eff6ff; border-right:3px solid #1d4ed8; }
.inbox-item.unread .inbox-subject { font-weight:700; color:#111827; }
.inbox-item.unread { background:#fefce8; }
.inbox-item.unread:hover { background:#fef9c3; }
.inbox-item.unread.active { background:#eff6ff; }

.inbox-avatar { width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; color:#fff; flex-shrink:0; margin-top:2px; }
.inbox-avatar.in  { background:linear-gradient(135deg,#3b82f6,#1d4ed8); }
.inbox-avatar.out { background:linear-gradient(135deg,#10b981,#059669); }

.inbox-meta { flex:1; min-width:0; }
.inbox-entity { font-size:12px; color:#6b7280; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.inbox-subject { font-size:13px; color:#374151; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin:2px 0; }
.inbox-date { font-size:11px; color:#9ca3af; }
.inbox-ref { font-size:10px; color:#9ca3af; }
.inbox-unread-dot { width:8px; height:8px; border-radius:50%; background:#f59e0b; flex-shrink:0; margin-top:6px; }

.inbox-empty { padding:50px 20px; text-align:center; color:#9ca3af; font-size:14px; }
.inbox-empty i { font-size:36px; display:block; margin-bottom:10px; color:#d1d5db; }

/* Detail Panel */
.inbox-detail { flex:1; display:flex; flex-direction:column; min-width:0; }
.inbox-detail-placeholder { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#d1d5db; }
.inbox-detail-placeholder i { font-size:56px; margin-bottom:14px; }
.inbox-detail-placeholder p { font-size:15px; color:#9ca3af; }

.inbox-detail-header { padding:18px 22px 14px; border-bottom:1px solid #f3f4f6; display:flex; align-items:flex-start; gap:14px; }
.detail-avatar { width:46px; height:46px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:18px; font-weight:700; color:#fff; flex-shrink:0; }
.detail-avatar.in  { background:linear-gradient(135deg,#3b82f6,#1d4ed8); }
.detail-avatar.out { background:linear-gradient(135deg,#10b981,#059669); }
.detail-title { flex:1; }
.detail-subject { font-size:17px; font-weight:700; color:#111827; margin-bottom:4px; }
.detail-meta { font-size:12px; color:#6b7280; display:flex; flex-wrap:wrap; gap:12px; }
.detail-meta span { display:flex; align-items:center; gap:4px; }
.detail-actions { display:flex; gap:6px; align-items:center; }

.inbox-detail-body { padding:20px 22px; flex:1; }
.detail-card { background:#f9fafb; border-radius:10px; padding:16px 18px; margin-bottom:14px; }
.detail-card-title { font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px; }
.detail-row { display:flex; gap:30px; flex-wrap:wrap; }
.detail-field { flex:1; min-width:140px; }
.detail-field label { font-size:11px; color:#9ca3af; display:block; margin-bottom:3px; }
.detail-field span { font-size:13px; color:#374151; font-weight:600; }

.status-pill { display:inline-flex; align-items:center; gap:5px; border-radius:99px; padding:3px 10px; font-size:12px; font-weight:600; }

.detail-notes { background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px 16px; font-size:13px; color:#92400e; line-height:1.7; }

.detail-attach { display:inline-flex; align-items:center; gap:8px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:8px 14px; font-size:13px; color:#1d4ed8; text-decoration:none; }
.detail-attach:hover { background:#eff6ff; }
.detail-attach i { font-size:16px; }

/* Compose Button */
.compose-btn { margin:12px; }
.compose-btn button { width:100%; padding:10px; border-radius:10px; font-size:13px; font-weight:600; background:linear-gradient(135deg,#1d4ed8,#3b82f6); border:none; color:#fff; display:flex; align-items:center; justify-content:center; gap:8px; cursor:pointer; transition:.2s; }
.compose-btn button:hover { opacity:.9; transform:translateY(-1px); box-shadow:0 4px 12px rgba(29,78,216,.3); }

@media(max-width:768px){
  .inbox-wrap { flex-direction:column; }
  .inbox-sidebar { width:100%; min-width:0; border-left:none; border-bottom:1px solid #e5e7eb; }
  .inbox-detail { display:<?= $open ? 'flex' : 'none' ?>; }
  .inbox-sidebar { display:<?= $open ? 'none' : 'flex' ?>; }
}
</style>

<!-- Toolbar -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <h5 class="mb-0 fw-bold"><i class="fas fa-inbox me-2 text-primary"></i>الصادر والوارد</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#corrModal">
    <i class="fas fa-pen me-1"></i>مراسلة جديدة
  </button>
</div>

<div class="inbox-wrap">

  <!-- ══ Sidebar ══ -->
  <div class="inbox-sidebar">

    <!-- Tabs -->
    <div class="inbox-tabs">
      <a href="correspondence.php?tab=incoming<?= $q_val?'&q='.urlencode($q_val):'' ?>" class="inbox-tab <?= $tab==='incoming'?'active':'' ?>">
        <i class="fas fa-inbox me-1"></i>الوارد
        <?php if($cnt_new>0): ?><span class="badge-count"><?= $cnt_new ?></span><?php endif; ?>
        <small class="d-block text-muted" style="font-weight:400;font-size:10px"><?= $cnt_in ?> رسالة</small>
      </a>
      <a href="correspondence.php?tab=outgoing<?= $q_val?'&q='.urlencode($q_val):'' ?>" class="inbox-tab <?= $tab==='outgoing'?'active':'' ?>">
        <i class="fas fa-paper-plane me-1"></i>الصادر
        <small class="d-block text-muted" style="font-weight:400;font-size:10px"><?= $cnt_out ?> رسالة</small>
      </a>
    </div>

    <!-- Search -->
    <div class="inbox-search">
      <form method="GET">
        <input type="hidden" name="tab" value="<?= $tab ?>">
        <input type="text" name="q" value="<?= e($q_val) ?>" placeholder="بحث في <?= $tab==='incoming'?'الوارد':'الصادر' ?>...">
      </form>
    </div>

    <!-- Compose -->
    <div class="compose-btn">
      <button data-bs-toggle="modal" data-bs-target="#corrModal"
              onclick="setComposeType('<?= $tab==='outgoing'?'outgoing':'incoming' ?>')">
        <i class="fas fa-pen-to-square"></i> إنشاء مراسلة جديدة
      </button>
    </div>

    <!-- List -->
    <div class="inbox-list">
      <?php
      $items = [];
      while ($c = $corrs->fetch_assoc()) $items[] = $c;
      if (empty($items)):
      ?>
      <div class="inbox-empty">
        <i class="fas fa-<?= $tab==='incoming'?'inbox':'paper-plane' ?>"></i>
        لا توجد رسائل <?= $tab==='incoming'?'واردة':'صادرة' ?>
        <?= $q_val ? '<br><small>جرّب تغيير كلمة البحث</small>' : '' ?>
      </div>
      <?php else: foreach($items as $c):
        $is_unread = ($c['status'] === 'new');
        $is_active = ($open === (int)$c['id']);
        $entity = $tab==='incoming' ? ($c['from_entity'] ?: 'غير محدد') : ($c['to_entity'] ?: 'غير محدد');
        $initial = mb_substr($entity, 0, 1, 'UTF-8');
        $cls = ($is_unread?'unread ':'') . ($is_active?'active':'');
      ?>
      <a class="inbox-item <?= $cls ?>"
         href="correspondence.php?tab=<?= $tab ?>&open=<?= $c['id'] ?><?= $q_val?'&q='.urlencode($q_val):'' ?><?= $st_val?'&st='.urlencode($st_val):'' ?>">
        <div class="inbox-avatar <?= $tab==='incoming'?'in':'out' ?>"><?= e($initial) ?></div>
        <div class="inbox-meta">
          <div class="inbox-entity"><?= e($entity) ?></div>
          <div class="inbox-subject"><?= e($c['subject']) ?></div>
          <div class="d-flex align-items-center justify-content-between">
            <span class="inbox-date"><i class="fas fa-calendar-alt me-1"></i><?= dDate($c['correspondence_date']) ?></span>
            <span class="inbox-ref"><?= e($c['reference_number']) ?></span>
          </div>
        </div>
        <?php if($is_unread): ?><div class="inbox-unread-dot"></div><?php endif; ?>
      </a>
      <?php endforeach; endif; ?>
    </div>
  </div><!-- /sidebar -->

  <!-- ══ Detail Panel ══ -->
  <div class="inbox-detail">
    <?php if (!$opened): ?>
    <div class="inbox-detail-placeholder">
      <i class="fas fa-<?= $tab==='incoming'?'inbox':'paper-plane' ?>"></i>
      <p>اختر رسالة للعرض</p>
    </div>
    <?php else:
      $entity_from = $opened['from_entity'] ?: '—';
      $entity_to   = $opened['to_entity']   ?: '—';
      $initial_d   = mb_substr($tab==='incoming' ? $entity_from : $entity_to, 0, 1, 'UTF-8');
    ?>
    <div class="inbox-detail-header">
      <div class="detail-avatar <?= $opened['type']==='incoming'?'in':'out' ?>"><?= e($initial_d) ?></div>
      <div class="detail-title">
        <div class="detail-subject"><?= e($opened['subject']) ?></div>
        <div class="detail-meta">
          <span><i class="fas fa-hashtag"></i><?= e($opened['reference_number']) ?></span>
          <span><i class="fas fa-calendar"></i><?= dDate($opened['correspondence_date']) ?></span>
          <span>
            <span class="status-pill" style="background:<?= statusColor($opened['status']) ?>22;color:<?= statusColor($opened['status']) ?>">
              <i class="fas fa-circle" style="font-size:7px"></i>
              <?= statusLabel($opened['status']) ?>
            </span>
          </span>
        </div>
      </div>
      <div class="detail-actions">
        <?php if($opened['status']==='new'): ?>
        <a href="correspondence.php?mark_read=<?= $opened['id'] ?>&tab=<?= $tab ?>"
           class="btn btn-sm btn-outline-info" title="تعليم كمقروء">
          <i class="fas fa-eye"></i>
        </a>
        <?php endif; ?>
        <a href="correspondence.php?edit=<?= $opened['id'] ?>&tab=<?= $tab ?>&open=<?= $opened['id'] ?>"
           class="btn btn-sm btn-outline-primary" title="تعديل"><i class="fas fa-edit"></i></a>
        <a href="correspondence.php?delete=<?= $opened['id'] ?>&tab=<?= $tab ?>"
           class="btn btn-sm btn-outline-danger"
           onclick="return confirm('حذف هذه المراسلة؟')" title="حذف"><i class="fas fa-trash"></i></a>
      </div>
    </div>

    <div class="inbox-detail-body">
      <!-- بيانات المراسلة -->
      <div class="detail-card">
        <div class="detail-card-title"><i class="fas fa-info-circle me-1"></i>تفاصيل المراسلة</div>
        <div class="detail-row">
          <div class="detail-field">
            <label><i class="fas fa-arrow-down me-1 text-primary"></i>من</label>
            <span><?= e($entity_from) ?></span>
          </div>
          <div class="detail-field">
            <label><i class="fas fa-arrow-up me-1 text-success"></i>إلى</label>
            <span><?= e($entity_to) ?></span>
          </div>
          <div class="detail-field">
            <label><i class="fas fa-tag me-1 text-warning"></i>النوع</label>
            <span><?= $opened['type']==='incoming'?'وارد':'صادر' ?></span>
          </div>
          <div class="detail-field">
            <label><i class="fas fa-calendar me-1"></i>تاريخ المراسلة</label>
            <span><?= dDate($opened['correspondence_date']) ?></span>
          </div>
        </div>
      </div>

      <?php if (!empty($opened['notes'])): ?>
      <!-- ملاحظات -->
      <div class="mb-3">
        <div class="detail-card-title mb-2"><i class="fas fa-sticky-note me-1"></i>ملاحظات</div>
        <div class="detail-notes"><?= nl2br(e($opened['notes'])) ?></div>
      </div>
      <?php endif; ?>

      <?php if (!empty($opened['file_path'])): ?>
      <!-- مرفق -->
      <div>
        <div class="detail-card-title mb-2"><i class="fas fa-paperclip me-1"></i>المرفق</div>
        <?= storage_file_link($conn, 'corr', $opened) ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div><!-- /detail -->

</div><!-- /inbox-wrap -->

<!-- ══ Modal إضافة / تعديل ══ -->
<div class="modal fade" id="corrModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-pen me-2"></i><?= $edit ? 'تعديل المراسلة' : 'مراسلة جديدة' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <div class="modal-body">
          <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">الرقم المرجعي <span class="text-danger">*</span></label>
              <input type="text" name="reference_number" class="form-control" required value="<?= e($edit['reference_number'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">النوع <span class="text-danger">*</span></label>
              <select name="type" id="corr-type" class="form-select">
                <option value="incoming" <?= ($edit['type']??$tab)==='incoming'?'selected':'' ?>>📥 وارد</option>
                <option value="outgoing" <?= ($edit['type']??$tab)==='outgoing'?'selected':'' ?>>📤 صادر</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">الموضوع <span class="text-danger">*</span></label>
              <input type="text" name="subject" class="form-control" required value="<?= e($edit['subject'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">من</label>
              <input type="text" name="from_entity" class="form-control" value="<?= e($edit['from_entity'] ?? '') ?>" placeholder="اسم الجهة أو الشخص">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">إلى</label>
              <input type="text" name="to_entity" class="form-control" value="<?= e($edit['to_entity'] ?? '') ?>" placeholder="اسم الجهة أو الشخص">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">تاريخ المراسلة</label>
              <input type="date" name="correspondence_date" class="form-control" value="<?= e($edit['correspondence_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">الحالة</label>
              <select name="status" class="form-select">
                <?php foreach(['new'=>'جديد','read'=>'مقروء','replied'=>'رُدّ عليه','archived'=>'مؤرشف'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= ($edit['status']??'new')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">ملاحظات</label>
              <textarea name="notes" class="form-control" rows="3" placeholder="أي ملاحظات إضافية..."><?= e($edit['notes'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">إرفاق ملف <small class="text-muted">(PDF, Word, صورة — حد 10MB)</small></label>
              <input type="file" name="corr_file" class="form-control mk-multi" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
              <?php if($edit && !empty($edit['file_path'])): ?>
              <small class="text-muted mt-1 d-block"><?= storage_file_link($conn, 'corr', $edit) ?></small>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i><?= $edit ? 'حفظ التعديلات' : 'إرسال' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
<?php if ($edit): ?>
document.addEventListener('DOMContentLoaded', function(){
  new bootstrap.Modal(document.getElementById('corrModal')).show();
});
<?php endif; ?>

function setComposeType(type) {
  var sel = document.getElementById('corr-type');
  if (sel) sel.value = type;
}
</script>

<?php
include '../includes/office_footer.php';
?>
