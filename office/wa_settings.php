<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('clients','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
$page_title = 'إعدادات واتساب';

/* ── جدول الإعدادات ── */
$conn->query("CREATE TABLE IF NOT EXISTS office_wa_settings (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    office_id  INT NOT NULL UNIQUE,
    provider   ENUM('greenapi','ultramsg','meta') NOT NULL DEFAULT 'greenapi',
    instance   VARCHAR(200) DEFAULT NULL,
    token      VARCHAR(500) DEFAULT NULL,
    extra      VARCHAR(500) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$msg = '';
$msgType = 'success';

/* ── حفظ الإعدادات ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'wa_cfg') {
    $prov  = in_array($_POST['provider'] ?? '', ['greenapi','ultramsg','meta']) ? $_POST['provider'] : 'greenapi';
    $inst  = $conn->real_escape_string(mb_substr(trim($_POST['instance'] ?? ''), 0, 200));
    $tok   = $conn->real_escape_string(mb_substr(trim($_POST['token'] ?? ''), 0, 500));
    $extra = $conn->real_escape_string(mb_substr(trim($_POST['extra'] ?? ''), 0, 500));
    $pe    = $conn->real_escape_string($prov);

    $conn->query("INSERT INTO office_wa_settings (office_id,provider,instance,token,extra)
        VALUES ($oid,'$pe','$inst','$tok','$extra')
        ON DUPLICATE KEY UPDATE provider='$pe', instance='$inst', token='$tok', extra='$extra'");

    $msg = 'تم حفظ الإعدادات بنجاح';
}

/* ── قراءة الإعدادات الحالية ── */
$cfg = ['provider'=>'greenapi','instance'=>'','token'=>'','extra'=>''];
$r = $conn->query("SELECT provider,instance,token,extra FROM office_wa_settings WHERE office_id=$oid");
if ($r && $row = $r->fetch_assoc()) $cfg = $row;

include '../includes/office_header.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <div class="me-auto">
    <div class="mk-page-title mb-0"><i class="fab fa-whatsapp" style="color:#25d366"></i> إعدادات واتساب الدعائي</div>
    <div class="mk-page-sub">اربط رقم واتساب خاص بمكتبك لإرسال رسائل دعائية وعروض</div>
  </div>
  <a href="wa_broadcast.php" class="btn btn-success btn-sm"><i class="fab fa-whatsapp me-1"></i>إرسال جماعي</a>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button><?= e($msg) ?></div>
<?php endif; ?>

<!-- مقارنة المزوّدات -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card h-100 <?= $cfg['provider']==='greenapi' ? 'border-success' : '' ?>">
      <div class="card-body text-center py-3">
        <div class="fs-4 mb-1">🟢</div>
        <div class="fw-bold">Green API</div>
        <span class="badge bg-success-subtle text-success border border-success-subtle mb-2">مجاني</span>
        <div class="text-muted" style="font-size:12px">مسح QR بجوالك — يرسل من رقمك الحالي</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100 <?= $cfg['provider']==='ultramsg' ? 'border-primary' : '' ?>">
      <div class="card-body text-center py-3">
        <div class="fs-4 mb-1">💬</div>
        <div class="fw-bold">UltraMsg</div>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle mb-2">مدفوع</span>
        <div class="text-muted" style="font-size:12px">رقم واتساب عادي — رسائل بدون قوالب</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100 <?= $cfg['provider']==='meta' ? 'border-primary' : '' ?>">
      <div class="card-body text-center py-3">
        <div class="fs-4 mb-1">📱</div>
        <div class="fw-bold">Meta (رسمي)</div>
        <span class="badge bg-warning-subtle text-warning border border-warning-subtle mb-2">قوالب معتمدة</span>
        <div class="text-muted" style="font-size:12px">WhatsApp Business API الرسمي من ميتا</div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header fw-bold"><i class="fas fa-gear me-1"></i>إعداد المزوّد</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="form_type" value="wa_cfg">

      <!-- اختيار المزوّد -->
      <div class="mb-4">
        <label class="form-label fw-semibold">اختر المزوّد</label>
        <div class="d-flex gap-3 flex-wrap">
          <label class="border rounded-3 p-3 d-flex align-items-center gap-2 provider-opt <?= $cfg['provider']==='greenapi'?'border-success bg-success-subtle':'' ?>" style="cursor:pointer;min-width:160px">
            <input type="radio" name="provider" value="greenapi" <?= $cfg['provider']==='greenapi'?'checked':'' ?> onchange="switchProvider(this.value)">
            <div><div class="fw-semibold">Green API</div><div class="text-muted" style="font-size:11px">مجاني — مسح QR</div></div>
          </label>
          <label class="border rounded-3 p-3 d-flex align-items-center gap-2 provider-opt <?= $cfg['provider']==='ultramsg'?'border-primary bg-primary-subtle':'' ?>" style="cursor:pointer;min-width:160px">
            <input type="radio" name="provider" value="ultramsg" <?= $cfg['provider']==='ultramsg'?'checked':'' ?> onchange="switchProvider(this.value)">
            <div><div class="fw-semibold">UltraMsg</div><div class="text-muted" style="font-size:11px">مدفوع</div></div>
          </label>
          <label class="border rounded-3 p-3 d-flex align-items-center gap-2 provider-opt <?= $cfg['provider']==='meta'?'border-primary bg-primary-subtle':'' ?>" style="cursor:pointer;min-width:160px">
            <input type="radio" name="provider" value="meta" <?= $cfg['provider']==='meta'?'checked':'' ?> onchange="switchProvider(this.value)">
            <div><div class="fw-semibold">Meta (رسمي)</div><div class="text-muted" style="font-size:11px">Business API</div></div>
          </label>
        </div>
      </div>

      <!-- حقول Green API -->
      <div id="fields-greenapi" class="provider-fields <?= $cfg['provider']!=='greenapi'?'d-none':'' ?>">
        <div class="alert alert-info d-flex gap-2 py-2 mb-3" style="font-size:13px">
          <i class="fas fa-circle-info mt-1 flex-shrink-0"></i>
          <div>
            سجّل في <strong>green-api.com</strong> → أنشئ Instance مجاني (Developer plan) → امسح QR بجوالك → انسخ
            <strong>Instance ID</strong> و <strong>API Token</strong> من لوحة التحكم.
          </div>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Instance ID <span class="text-danger">*</span></label>
            <input type="text" name="instance" class="form-control" dir="ltr"
              placeholder="1101234567" value="<?= $cfg['provider']==='greenapi' ? e($cfg['instance']) : '' ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">API Token <span class="text-danger">*</span></label>
            <input type="text" name="token" class="form-control" dir="ltr"
              placeholder="d75b3a66374942c5b3c650b4..." value="<?= $cfg['provider']==='greenapi' ? e($cfg['token']) : '' ?>">
          </div>
        </div>
        <input type="hidden" name="extra" value="">
      </div>

      <!-- حقول UltraMsg -->
      <div id="fields-ultramsg" class="provider-fields <?= $cfg['provider']!=='ultramsg'?'d-none':'' ?>">
        <div class="alert alert-info d-flex gap-2 py-2 mb-3" style="font-size:13px">
          <i class="fas fa-circle-info mt-1 flex-shrink-0"></i>
          <div>
            سجّل في <strong>ultramsg.com</strong> → أنشئ Instance → امسح QR → انسخ
            <strong>Instance ID</strong> و <strong>Token</strong>.
          </div>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Instance ID <span class="text-danger">*</span></label>
            <input type="text" name="instance" class="form-control" dir="ltr"
              placeholder="instance12345" value="<?= $cfg['provider']==='ultramsg' ? e($cfg['instance']) : '' ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Token <span class="text-danger">*</span></label>
            <input type="text" name="token" class="form-control" dir="ltr"
              placeholder="abc123..." value="<?= $cfg['provider']==='ultramsg' ? e($cfg['token']) : '' ?>">
          </div>
        </div>
        <input type="hidden" name="extra" value="">
      </div>

      <!-- حقول Meta -->
      <div id="fields-meta" class="provider-fields <?= $cfg['provider']!=='meta'?'d-none':'' ?>">
        <div class="alert alert-warning d-flex gap-2 py-2 mb-3" style="font-size:13px">
          <i class="fas fa-triangle-exclamation mt-1 flex-shrink-0"></i>
          <div>
            يتطلب حساب Meta Business + قالب رسالة معتمد مسبقاً من Meta.
            من <strong>developers.facebook.com</strong> → WhatsApp → Getting Started → Phone Number ID + Access Token.
          </div>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Phone Number ID <span class="text-danger">*</span></label>
            <input type="text" name="instance" class="form-control" dir="ltr"
              placeholder="1234567890" value="<?= $cfg['provider']==='meta' ? e($cfg['instance']) : '' ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Access Token <span class="text-danger">*</span></label>
            <input type="text" name="token" class="form-control" dir="ltr"
              placeholder="EAAxxxxx..." value="<?= $cfg['provider']==='meta' ? e($cfg['token']) : '' ?>">
          </div>
        </div>
        <input type="hidden" name="extra" value="">
      </div>

      <div class="mt-4">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ الإعدادات</button>
        <?php if ($cfg['instance'] && $cfg['token']): ?>
        <a href="wa_broadcast.php" class="btn btn-success ms-2"><i class="fab fa-whatsapp me-1"></i>اذهب للإرسال الجماعي</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if ($cfg['instance'] && $cfg['token']): ?>
<div class="alert alert-success mt-3 d-flex gap-2">
  <i class="fas fa-circle-check mt-1"></i>
  <div>الإعدادات مضبوطة — المزوّد الحالي: <strong><?= e($cfg['provider']) ?></strong>. يمكنك الآن إرسال رسائل دعائية.</div>
</div>
<?php endif; ?>

<script>
function switchProvider(val) {
  document.querySelectorAll('.provider-fields').forEach(el => el.classList.add('d-none'));
  document.getElementById('fields-' + val)?.classList.remove('d-none');
  /* مسح الحقول المخفية */
  document.querySelectorAll('.provider-fields.d-none input[name="instance"], .provider-fields.d-none input[name="token"]').forEach(i => i.value = '');
}
</script>

<?php include '../includes/office_footer.php'; ?>
