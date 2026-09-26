<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('client_action_link','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'client_action_link')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'رابط إنجاز العميل';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('client_action_link','add'); $_canDel = can('client_action_link','delete');
$_canApprove = can('client_action_link','approve'); // مستوى المدير: يرى روابط الجميع

$conn->query("CREATE TABLE IF NOT EXISTS client_action_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    client_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    expires_at DATE DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("CREATE TABLE IF NOT EXISTS client_action_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    link_id INT NOT NULL,
    office_id INT NOT NULL,
    item_type ENUM('sign','invoice','upload','confirm') NOT NULL,
    label VARCHAR(255) NOT NULL,
    ref_id INT DEFAULT NULL,
    status ENUM('pending','done') DEFAULT 'pending',
    file_name VARCHAR(255) DEFAULT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    file_driver VARCHAR(20) DEFAULT 'local',
    done_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_link (link_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$_ownScope = !$_canApprove ? " AND created_by=$uid" : "";
$_hasSign = hasModule($conn, $oid, 'esignature');

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $lid = (int)$_GET['delete'];
    $ok = $conn->query("SELECT id FROM client_action_links WHERE id=$lid AND office_id=$oid$_ownScope")->fetch_assoc();
    if ($ok) {
        $conn->query("DELETE FROM client_action_items WHERE link_id=$lid AND office_id=$oid");
        $conn->query("DELETE FROM client_action_links WHERE id=$lid");
    }
    header('Location: client_action_link.php?msg=deleted'); exit;
}

/* ── إنشاء رابط ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'link') {
    requirePerm('client_action_link', 'add', 'client_action_link.php?msg=denied');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $client = $conn->query("SELECT id FROM clients WHERE id=$client_id AND office_id=$oid")->fetch_assoc();
    $title = trim($_POST['title'] ?? '');
    if (!$client || $title === '') { header('Location: client_action_link.php?msg=invalid'); exit; }
    $msg = $conn->real_escape_string(trim($_POST['message'] ?? ''));
    $exp = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['expires_at'] ?? '') ? "'".$_POST['expires_at']."'" : 'NULL';
    $token = bin2hex(random_bytes(24));
    $items = [];
    if ($_hasSign) foreach ((array)($_POST['sign_ids'] ?? []) as $sid) {
        $sid = (int)$sid;
        $r = $conn->query("SELECT id, title FROM esign_requests WHERE id=$sid AND office_id=$oid AND status='pending'")->fetch_assoc();
        if ($r) $items[] = ['sign', 'توقيع: ' . $r['title'], $sid];
    }
    foreach ((array)($_POST['invoice_ids'] ?? []) as $iid) {
        $iid = (int)$iid;
        $r = $conn->query("SELECT id, invoice_number, total FROM invoices WHERE id=$iid AND office_id=$oid AND client_id=$client_id AND status IN ('sent','overdue','draft')")->fetch_assoc();
        if ($r) $items[] = ['invoice', 'فاتورة ' . $r['invoice_number'] . ' — ' . number_format((float)$r['total'], 2) . ' ر.س', $iid];
    }
    foreach (preg_split('/\R/u', (string)($_POST['upload_labels'] ?? '')) as $l) { $l = trim($l); if ($l !== '') $items[] = ['upload', mb_substr($l, 0, 200), null]; }
    foreach (preg_split('/\R/u', (string)($_POST['confirm_labels'] ?? '')) as $l) { $l = trim($l); if ($l !== '') $items[] = ['confirm', mb_substr($l, 0, 200), null]; }
    if (!$items) { header('Location: client_action_link.php?msg=noitems'); exit; }
    $t = $conn->real_escape_string($title);
    $conn->query("INSERT INTO client_action_links (office_id,client_id,token,title,message,expires_at,created_by) VALUES ($oid,$client_id,'$token','$t','$msg',$exp,".($uid ?: 'NULL').")");
    $lid = (int)$conn->insert_id;
    foreach ($items as [$type, $label, $ref]) {
        $lb = $conn->real_escape_string($label);
        $conn->query("INSERT INTO client_action_items (link_id,office_id,item_type,label,ref_id) VALUES ($lid,$oid,'$type','$lb',".($ref ?: 'NULL').")");
    }
    header('Location: client_action_link.php?msg=saved'); exit;
}

/* ── بيانات ── */
$clients_arr = [];
$cr = $conn->query("SELECT id, full_name FROM clients WHERE office_id=$oid ORDER BY full_name LIMIT 500");
if ($cr) while ($x = $cr->fetch_assoc()) $clients_arr[$x['id']] = $x['full_name'];

$invoices = [];
$ir = $conn->query("SELECT id, client_id, invoice_number, total FROM invoices WHERE office_id=$oid AND client_id IS NOT NULL AND status IN ('sent','overdue','draft') ORDER BY id DESC LIMIT 300");
if ($ir) while ($x = $ir->fetch_assoc()) $invoices[] = $x;

$signs = [];
if ($_hasSign) { $sr = $conn->query("SELECT id, title, signer_name FROM esign_requests WHERE office_id=$oid AND status='pending' ORDER BY id DESC LIMIT 200"); if ($sr) while ($x = $sr->fetch_assoc()) $signs[] = $x; }

$links = [];
$lr = $conn->query("SELECT l.*, c.full_name client_name FROM client_action_links l LEFT JOIN clients c ON c.id=l.client_id WHERE l.office_id=$oid$_ownScope ORDER BY l.id DESC LIMIT 100");
if ($lr) while ($x = $lr->fetch_assoc()) $links[] = $x;
$itemsBy = [];
if ($links) {
    $ids = implode(',', array_map(fn($l) => (int)$l['id'], $links));
    $it = $conn->query("SELECT it.*, (SELECT status FROM invoices WHERE id=it.ref_id AND it.item_type='invoice') inv_status,
        (SELECT status FROM esign_requests WHERE id=it.ref_id AND it.item_type='sign') sign_status FROM client_action_items it WHERE it.link_id IN ($ids) ORDER BY it.id");
    if (!$it) { // جدول التواقيع غير موجود (موديول التوقيع غير مفعّل)
        $it = $conn->query("SELECT it.*, (SELECT status FROM invoices WHERE id=it.ref_id AND it.item_type='invoice') inv_status, NULL sign_status
            FROM client_action_items it WHERE it.link_id IN ($ids) ORDER BY it.id");
    }
    if ($it) while ($x = $it->fetch_assoc()) $itemsBy[$x['link_id']][] = $x;
}
$_base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/') . '/public/client_action.php?t=';
$_typeIco = ['sign'=>'signature','invoice'=>'file-invoice-dollar','upload'=>'cloud-arrow-up','confirm'=>'circle-check'];

function cal_item_done($x) {
    if ($x['item_type'] === 'invoice') return $x['inv_status'] === 'paid';
    if ($x['item_type'] === 'sign')    return $x['sign_status'] === 'signed';
    return $x['status'] === 'done';
}

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-link"></i> رابط إنجاز العميل</div>
<div class="mk-page-sub mb-3">رابط واحد يرسله المكتب للعميل: يوقّع العقد، ويطّلع على فاتورته، ويرفع مستنداته، ويوافق — بدون حساب ولا تسجيل دخول</div>

<?php if (isset($_GET['msg'])):
  $mm = ['saved'=>['success','تم إنشاء الرابط — انسخه وأرسله للعميل'],'deleted'=>['success','تم الحذف'],'invalid'=>['danger','اختر العميل واكتب عنواناً'],'noitems'=>['danger','أضف بنداً واحداً على الأقل'],'denied'=>['danger','ليست لديك صلاحية']];
  $m = $mm[$_GET['msg']] ?? ['success','تم']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-end mb-3"><?php if ($_canAdd): ?><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#linkModal"><i class="fas fa-plus me-1"></i>رابط جديد</button><?php endif; ?></div>

<div class="row g-3">
<?php if (!$links): ?><div class="col-12"><div class="text-muted text-center py-5">لا توجد روابط بعد</div></div>
<?php else: foreach ($links as $l):
  $its = $itemsBy[$l['id']] ?? []; $done = 0; foreach ($its as $x) if (cal_item_done($x)) $done++;
  $url = $_base . $l['token'];
  $expired = $l['expires_at'] && strtotime($l['expires_at']) < strtotime(date('Y-m-d')); ?>
<div class="col-lg-6"><div class="card h-100"><div class="card-body">
  <div class="d-flex justify-content-between align-items-start">
    <div><div class="fw-bold"><?= e($l['title']) ?></div><div class="text-muted" style="font-size:12px"><i class="fas fa-user me-1"></i><?= e($l['client_name'] ?: '—') ?><?= $l['expires_at'] ? ' · ينتهي '.e($l['expires_at']) : '' ?><?= $expired ? ' <span class="badge bg-danger">منتهي</span>' : '' ?></div></div>
    <span class="badge bg-<?= $done==count($its)&&$its ? 'success' : 'warning' ?> bg-opacity-10 text-<?= $done==count($its)&&$its ? 'success' : 'warning' ?>"><?= $done ?>/<?= count($its) ?></span>
  </div>
  <ul class="list-unstyled mt-2 mb-2" style="font-size:12.5px">
  <?php foreach ($its as $x): $d = cal_item_done($x); ?>
    <li class="d-flex align-items-center gap-2 py-1"><i class="fas fa-<?= $_typeIco[$x['item_type']] ?> text-muted" style="width:16px"></i>
      <span class="flex-grow-1"><?= e($x['label']) ?></span>
      <?php if ($x['item_type']==='upload' && $x['file_path']): ?><a href="file.php?t=clientup&id=<?= (int)$x['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0"><i class="fas fa-eye"></i></a><?php endif; ?>
      <i class="fas fa-<?= $d ? 'circle-check text-success' : 'clock text-warning' ?>"></i></li>
  <?php endforeach; ?>
  </ul>
  <div class="input-group input-group-sm mb-2"><input type="text" class="form-control" readonly value="<?= e($url) ?>" onclick="this.select()"><button class="btn btn-outline-primary" onclick="navigator.clipboard.writeText('<?= e($url) ?>');this.innerHTML='<i class=&quot;fas fa-check&quot;></i>'"><i class="fas fa-copy"></i></button></div>
  <div class="d-flex gap-1">
    <a class="btn btn-sm btn-success" target="_blank" href="https://wa.me/?text=<?= urlencode($l['title'] . "\n" . $url) ?>"><i class="fab fa-whatsapp me-1"></i>واتساب</a>
    <?php if ($_canDel): ?><a href="client_action_link.php?delete=<?= (int)$l['id'] ?>" class="btn btn-sm btn-outline-danger ms-auto" onclick="return confirm('حذف الرابط وبنوده؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </div>
</div></div></div>
<?php endforeach; endif; ?>
</div>

<div class="modal fade" id="linkModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-link me-2"></i>رابط إنجاز جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="link">
    <div class="modal-body">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label fw-semibold">العميل *</label><select name="client_id" id="cal_client" class="form-select" required onchange="calFilter()"><option value="">— اختر —</option>
          <?php foreach ($clients_arr as $cid => $cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label fw-semibold">العنوان *</label><input name="title" class="form-control" required placeholder="مثال: إنجاز إجراءات قضيتك"></div>
        <div class="col-12"><label class="form-label fw-semibold">رسالة للعميل</label><textarea name="message" class="form-control" rows="2"></textarea></div>
        <div class="col-md-6"><label class="form-label fw-semibold">ينتهي في</label><input type="date" name="expires_at" class="form-control"></div>
      </div>
      <hr>
      <div class="row g-3">
        <?php if ($_hasSign): ?>
        <div class="col-md-6"><label class="form-label fw-semibold"><i class="fas fa-signature me-1"></i>طلبات توقيع معلّقة</label>
          <div style="max-height:110px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;padding:6px 10px;font-size:13px">
            <?php if (!$signs): ?><span class="text-muted">لا توجد</span><?php endif; ?>
            <?php foreach ($signs as $s): ?><label class="d-block"><input type="checkbox" name="sign_ids[]" value="<?= (int)$s['id'] ?>"> <?= e($s['title']) ?> <small class="text-muted">(<?= e($s['signer_name']) ?>)</small></label><?php endforeach; ?></div></div>
        <?php endif; ?>
        <div class="col-md-6"><label class="form-label fw-semibold"><i class="fas fa-file-invoice-dollar me-1"></i>فواتير العميل غير المسدّدة</label>
          <div id="cal_invoices" style="max-height:110px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;padding:6px 10px;font-size:13px">
            <?php foreach ($invoices as $iv): ?><label class="cal-inv" data-client="<?= (int)$iv['client_id'] ?>" style="display:none"><input type="checkbox" name="invoice_ids[]" value="<?= (int)$iv['id'] ?>"> <?= e($iv['invoice_number']) ?> — <?= number_format((float)$iv['total'],2) ?> ر.س</label><?php endforeach; ?>
            <span class="text-muted" id="cal_inv_hint">اختر العميل أولاً</span></div></div>
        <div class="col-md-6"><label class="form-label fw-semibold"><i class="fas fa-cloud-arrow-up me-1"></i>مستندات مطلوبة من العميل (سطر لكل مستند)</label><textarea name="upload_labels" class="form-control" rows="3" placeholder="صورة الهوية&#10;عقد الإيجار"></textarea></div>
        <div class="col-md-6"><label class="form-label fw-semibold"><i class="fas fa-circle-check me-1"></i>إقرارات/موافقات (سطر لكل بند)</label><textarea name="confirm_labels" class="form-control" rows="3" placeholder="أوافق على عرض الأتعاب المرسل&#10;أقرّ بصحة البيانات"></textarea></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary"><i class="fas fa-link me-1"></i>إنشاء الرابط</button></div>
  </form>
</div></div></div>
<script>
function calFilter(){
  var c=document.getElementById('cal_client').value, any=false;
  document.querySelectorAll('.cal-inv').forEach(function(l){var show=(l.dataset.client===c&&c!=='');l.style.display=show?'block':'none';if(!show)l.querySelector('input').checked=false;if(show)any=true;});
  document.getElementById('cal_inv_hint').style.display=any?'none':'inline';
  document.getElementById('cal_inv_hint').textContent=c?'لا توجد فواتير غير مسدّدة لهذا العميل':'اختر العميل أولاً';
}
</script>

<?php include '../includes/office_footer.php'; ?>
