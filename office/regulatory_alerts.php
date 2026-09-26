<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('regulatory_alerts','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'regulatory_alerts')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'تنبيهات التحديثات النظامية';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('regulatory_alerts','add'); $_canEdit = can('regulatory_alerts','edit'); $_canDel = can('regulatory_alerts','delete');
$_canApprove = can('regulatory_alerts','approve'); // مستوى المدير: ينشر التنبيهات للمكتب كله

$conn->query("CREATE TABLE IF NOT EXISTS regulatory_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT,
    source_url VARCHAR(500) DEFAULT NULL,
    severity ENUM('info','important','urgent') DEFAULT 'info',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS regulatory_alert_reads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_alert_user (alert_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── نشر / تعديل تنبيه (مستوى المدير فقط) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'alert') {
    if (!$_canApprove) { header('Location: regulatory_alerts.php?msg=denied'); exit; }
    $aid = (int)($_POST['id'] ?? 0);
    $title = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $body = $conn->real_escape_string(trim($_POST['body'] ?? ''));
    $url = $conn->real_escape_string(trim($_POST['source_url'] ?? ''));
    $sev = in_array($_POST['severity'] ?? '', ['info','important','urgent'], true) ? $_POST['severity'] : 'info';
    if ($aid) $conn->query("UPDATE regulatory_alerts SET title='$title',body='$body',source_url='$url',severity='$sev' WHERE id=$aid AND office_id=$oid");
    else $conn->query("INSERT INTO regulatory_alerts (office_id,title,body,source_url,severity,created_by) VALUES ($oid,'$title','$body','$url','$sev',".($uid ?: 'NULL').")");
    header("Location: regulatory_alerts.php?msg=saved"); exit;
}
if (isset($_GET['delete']) && ($_canDel || $_canApprove)) {
    $conn->query("DELETE FROM regulatory_alerts WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header("Location: regulatory_alerts.php?msg=deleted"); exit;
}
/* ── تعليم كمقروء ── */
if (isset($_GET['mark_read'])) {
    $aid = (int)$_GET['mark_read'];
    $conn->query("INSERT IGNORE INTO regulatory_alert_reads (alert_id,user_id) VALUES ($aid,$uid)");
    header("Location: regulatory_alerts.php?msg=read"); exit;
}

/* ── بيانات ── */
$alerts = [];
$ar = $conn->query("SELECT a.*, u.full_name author_name,
    (SELECT COUNT(*) FROM regulatory_alert_reads rr WHERE rr.alert_id=a.id AND rr.user_id=$uid) AS is_read
    FROM regulatory_alerts a LEFT JOIN users u ON a.created_by=u.id
    WHERE a.office_id=$oid ORDER BY a.created_at DESC LIMIT 300");
if ($ar) while ($r = $ar->fetch_assoc()) $alerts[] = $r;

$unread_count = 0; foreach ($alerts as $a) if (!$a['is_read']) $unread_count++;
$_sevMap = ['info'=>['عادي','secondary'],'important'=>['مهم','warning'],'urgent'=>['عاجل','danger']];

$edit = null;
if (isset($_GET['edit']) && $_canApprove) $edit = $conn->query("SELECT * FROM regulatory_alerts WHERE id=".(int)$_GET['edit']." AND office_id=$oid")->fetch_assoc();

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-triangle-exclamation"></i> تنبيهات التحديثات النظامية</div>
<div class="mk-page-sub mb-3">لوحة إعلانات يديرها المكتب لمتابعة آخر التحديثات النظامية ذات العلاقة بعملكم<?= $unread_count ? " — <span class='text-danger fw-bold'>$unread_count تنبيه غير مقروء</span>" : '' ?></div>

<?php if (isset($_GET['msg']) && $_GET['msg']!=='read'): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-end mb-3">
  <?php if ($_canApprove): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#alertModal" onclick="alertNew()"><i class="fas fa-plus me-1"></i>تنبيه جديد</button>
  <?php endif; ?>
</div>

<div class="d-flex flex-column gap-2">
<?php if (!$alerts): ?><div class="text-muted text-center py-5">لا توجد تنبيهات بعد</div>
<?php else: foreach ($alerts as $a): $sm = $_sevMap[$a['severity']] ?? ['—','secondary']; ?>
<div class="card <?= !$a['is_read']?'border-'.$sm[1]:'' ?>">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2">
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="badge bg-<?= $sm[1] ?> bg-opacity-10 text-<?= $sm[1] ?>"><?= $sm[0] ?></span>
          <span class="fw-bold"><?= e($a['title']) ?></span>
          <?php if (!$a['is_read']): ?><span class="badge bg-danger">جديد</span><?php endif; ?>
        </div>
        <?php if ($a['body']): ?><div class="text-muted mb-2" style="font-size:13px;white-space:pre-line"><?= e($a['body']) ?></div><?php endif; ?>
        <?php if ($a['source_url']): ?><a href="<?= e($a['source_url']) ?>" target="_blank" rel="noopener" class="d-inline-block mb-2" style="font-size:12px"><i class="fas fa-link me-1"></i>المصدر</a><?php endif; ?>
        <div class="text-muted" style="font-size:11px"><?= e($a['author_name'] ?: '—') ?> — <?= date('Y-m-d', strtotime($a['created_at'])) ?></div>
      </div>
      <div class="d-flex gap-1">
        <?php if (!$a['is_read']): ?><a href="regulatory_alerts.php?mark_read=<?= $a['id'] ?>" class="btn btn-sm btn-outline-success"><i class="fas fa-check"></i> تعليم كمقروء</a><?php endif; ?>
        <?php if ($_canApprove): ?>
        <button class="btn btn-sm btn-outline-primary" onclick='alertEdit(<?= json_encode($a, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
        <a href="regulatory_alerts.php?delete=<?= $a['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا التنبيه؟')"><i class="fas fa-trash"></i></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- Modal -->
<div class="modal fade" id="alertModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="alertModalTitle">تنبيه جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="alert"><input type="hidden" name="id" id="alert_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">العنوان *</label><input type="text" name="title" id="alert_title_inp" class="form-control" required></div>
      <div class="mb-3"><label class="form-label fw-semibold">التفاصيل</label><textarea name="body" id="alert_body" class="form-control" rows="4"></textarea></div>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label fw-semibold">رابط المصدر</label><input type="url" name="source_url" id="alert_url" class="form-control" placeholder="https://"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">الأهمية</label>
          <select name="severity" id="alert_severity" class="form-select">
            <option value="info">عادي</option><option value="important">مهم</option><option value="urgent">عاجل</option>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-bullhorn me-1"></i>نشر</button></div>
  </form>
</div></div></div>

<script>
function alertNew(){document.getElementById('alertModalTitle').innerText='تنبيه جديد';document.getElementById('alert_id').value='';document.getElementById('alert_title_inp').value='';document.getElementById('alert_body').value='';document.getElementById('alert_url').value='';document.getElementById('alert_severity').value='info';}
function alertEdit(a){document.getElementById('alertModalTitle').innerText='تعديل تنبيه';document.getElementById('alert_id').value=a.id;document.getElementById('alert_title_inp').value=a.title;document.getElementById('alert_body').value=a.body||'';document.getElementById('alert_url').value=a.source_url||'';document.getElementById('alert_severity').value=a.severity;new bootstrap.Modal(document.getElementById('alertModal')).show();}
<?php if ($edit): ?>
document.addEventListener('DOMContentLoaded', function(){ alertEdit(<?= json_encode($edit, JSON_HEX_APOS|JSON_HEX_QUOT) ?>); });
<?php endif; ?>
</script>

<?php include '../includes/office_footer.php'; ?>
