<?php
$page_title = 'أدوات التسويق';
require_once __DIR__ . '/../includes/affiliate_header.php';

$_site = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'mehkam.app');
$ref_link = rtrim($_site,'/') . '/public/register.php?ref=' . $_aff['ref_code'];
$pricing_link = rtrim($_site,'/') . '/public/pricing.php?ref=' . $_aff['ref_code'];
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=10&data=' . urlencode($ref_link);
?>
<style>
.mk-tool-card{background:#fff;border:1px solid #eef1f6;border-radius:14px;padding:20px}
.mk-tool-hdr{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.mk-tool-ico{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff}
.mk-snip{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;font-size:13px;color:#374151;line-height:1.7;white-space:pre-wrap;margin-bottom:10px}
.mk-copy-btn{font-size:12px}
.mk-link-box{background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px}
.mk-link-box code{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12.5px;color:#0c1b36}
</style>

<div class="row g-3">
  <!-- الرابط + QR -->
  <div class="col-lg-4">
    <div class="mk-tool-card text-center h-100">
      <div class="fw-bold mb-3"><i class="fas fa-qrcode me-1 text-primary"></i>رمز QR لرابطك</div>
      <img src="<?= e($qr_url) ?>" alt="QR" width="220" height="220" loading="eager" class="mb-3 mx-auto d-block" style="border:1px solid #eef1f6;border-radius:12px;padding:8px;width:220px;height:220px">
      <div class="mk-link-box mb-2">
        <code dir="ltr" id="mk_link"><?= e($ref_link) ?></code>
      </div>
      <button class="btn btn-sm btn-outline-primary w-100" onclick="mkCopy('mk_link',this)"><i class="fas fa-copy me-1"></i>نسخ الرابط</button>
      <div class="text-muted mt-2" style="font-size:11.5px">اطبع الرمز أو انشره — أي مسح له يفتح رابط تسجيلك مباشرة</div>
    </div>
  </div>

  <!-- نصوص واتساب -->
  <div class="col-lg-8">
    <div class="mk-tool-card mb-3">
      <div class="mk-tool-hdr">
        <div class="mk-tool-ico" style="background:#25d366"><i class="fab fa-whatsapp"></i></div>
        <div class="fw-bold">نصوص واتساب / تواصل مباشر</div>
      </div>
      <div class="mk-snip" id="mk_wa1">مرحباً 👋
تدير مكتب محاماة؟ أنصحك تجرّب منصة <b>مِحكام</b> — تنظّم القضايا والجلسات والعملاء والفواتير في مكان واحد، وفيها تجربة مجانية 14 يوم بدون التزام.
جرّبها من هنا: <?= e($ref_link) ?></div>
      <button class="btn btn-sm btn-outline-secondary mk-copy-btn" onclick="mkCopy('mk_wa1',this)"><i class="fas fa-copy me-1"></i>نسخ</button>
    </div>

    <div class="mk-tool-card mb-3">
      <div class="mk-tool-hdr">
        <div class="mk-tool-ico" style="background:#0c1b36"><i class="fab fa-x-twitter"></i></div>
        <div class="fw-bold">منشور X / تويتر <small class="text-muted fw-normal">(قصير)</small></div>
      </div>
      <div class="mk-snip" id="mk_x1">هل تدير مكتب محاماة بجداول Excel؟ 📁
مِحكام تنظّم لك كل شي في مكان واحد — قضايا، جلسات، فواتير ZATCA.
جرّب مجاناً 14 يوم 👇
<?= e($ref_link) ?></div>
      <button class="btn btn-sm btn-outline-secondary mk-copy-btn" onclick="mkCopy('mk_x1',this)"><i class="fas fa-copy me-1"></i>نسخ</button>
    </div>

    <div class="mk-tool-card">
      <div class="mk-tool-hdr">
        <div class="mk-tool-ico" style="background:#2563eb"><i class="fas fa-envelope"></i></div>
        <div class="fw-bold">قالب بريد إلكتروني</div>
      </div>
      <div class="mk-snip" id="mk_email1">الموضوع: منصة مِحكام لإدارة مكتبك القانوني

مرحباً،

أحببت أشاركك منصة "مِحكام" — منصة سعودية متخصصة بإدارة مكاتب المحاماة، تجمع لك القضايا والجلسات والعملاء والعقود والفواتير المتوافقة مع ZATCA في مكان واحد.

فيها تجربة مجانية 14 يوماً بدون أي التزام، تقدر تبدأها من الرابط التالي:
<?= e($ref_link) ?>

تحياتي</div>
      <button class="btn btn-sm btn-outline-secondary mk-copy-btn" onclick="mkCopy('mk_email1',this)"><i class="fas fa-copy me-1"></i>نسخ</button>
    </div>
  </div>

  <!-- روابط إضافية -->
  <div class="col-12">
    <div class="mk-tool-card">
      <div class="fw-bold mb-3"><i class="fas fa-link me-1 text-warning"></i>روابط جاهزة لصفحات أخرى</div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" style="font-size:12px;color:#64748b">صفحة الأسعار (بإحالتك)</label>
          <div class="mk-link-box">
            <code dir="ltr" id="mk_link2"><?= e($pricing_link) ?></code>
            <button class="btn btn-sm btn-outline-secondary flex-shrink-0" onclick="mkCopy('mk_link2',this)"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label" style="font-size:12px;color:#64748b">صفحة التسجيل المباشرة (بإحالتك)</label>
          <div class="mk-link-box">
            <code dir="ltr" id="mk_link3"><?= e($ref_link) ?></code>
            <button class="btn btn-sm btn-outline-secondary flex-shrink-0" onclick="mkCopy('mk_link3',this)"><i class="fas fa-copy"></i></button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function mkCopy(id, btn) {
  var txt = document.getElementById(id).innerText.trim();
  navigator.clipboard.writeText(txt).then(function () {
    var old = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check me-1"></i>تم';
    setTimeout(function () { btn.innerHTML = old; }, 1500);
  });
}
</script>

<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
