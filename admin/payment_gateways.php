<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/content_helper.php';
requireAdmin();
$page_title = 'بوابات الدفع';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        // PayPal
        'paypal_enabled','paypal_mode','paypal_client_id','paypal_secret',
        // Paymob
        'paymob_enabled','paymob_api_key','paymob_card_integration_id',
        'paymob_applepay_integration_id','paymob_iframe_id','paymob_iframe_base_url',
        'paymob_api_base_url','paymob_currency','paymob_hmac_secret',
        // تحويل بنكي
        'bank_enabled','platform_bank_name','platform_bank_iban',
        'platform_bank_account','platform_payment_instructions',
    ];
    foreach ($fields as $key) {
        $val = $conn->real_escape_string(trim($_POST[$key] ?? ''));
        $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('$key','$val')
                      ON DUPLICATE KEY UPDATE setting_value='$val'");
    }
    header("Location: payment_gateways.php?msg=saved"); exit;
}

include '../includes/admin_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show py-2">
  <i class="fas fa-check-circle me-2"></i>تم حفظ إعدادات بوابات الدفع
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="mk-page-hdr mb-4">
  <div>
    <div class="mk-page-title"><i class="fas fa-credit-card"></i> بوابات الدفع</div>
    <div class="mk-page-sub">اربط PayPal وPaymob (بطاقة + Apple Pay) بالمنصة</div>
  </div>
</div>

<form method="POST">
<div class="row g-4">

  <!-- ══ PayPal ══ -->
  <div class="col-lg-6">
    <div class="card h-100" style="border:2px solid #e0e7ef;border-top:4px solid #0070ba">
      <div class="card-header d-flex align-items-center gap-3 py-3"
           style="background:#f0f6ff;border-bottom:1px solid #d0e4f7">
        <div style="width:42px;height:42px;background:linear-gradient(135deg,#003087,#0070ba);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fab fa-paypal" style="color:#fff;font-size:20px"></i>
        </div>
        <div>
          <div style="font-size:16px;font-weight:800;color:#003087;line-height:1.2">PayPal</div>
          <div style="font-size:11px;color:#5a7fa8">بوابة دفع عالمية</div>
        </div>
        <div class="ms-auto d-flex align-items-center gap-2">
          <?php $pp_active = sc($conn,'paypal_enabled','0')==='1'; ?>
          <span style="font-size:12px;font-weight:700;color:<?= $pp_active?'#16a34a':'#9ca3af' ?>">
            <?= $pp_active?'مفعّل':'معطّل' ?>
          </span>
          <div class="gw-toggle" onclick="gwToggle(this,'paypal_enabled')" data-on="<?= $pp_active?'1':'0' ?>"
               style="width:48px;height:26px;border-radius:50px;background:<?= $pp_active?'#16a34a':'#d1d5db' ?>;position:relative;cursor:pointer;transition:background .2s;flex-shrink:0">
            <div style="position:absolute;top:3px;<?= $pp_active?'right:3px':'left:3px' ?>;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.25);transition:all .2s"></div>
          </div>
          <input type="hidden" name="paypal_enabled" id="paypal_enabled_val" value="<?= $pp_active?'1':'0' ?>">
        </div>
      </div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">وضع التشغيل</label>
          <select name="paypal_mode" class="form-select">
            <option value="sandbox" <?= sc($conn,'paypal_mode','sandbox')==='sandbox'?'selected':'' ?>>
              Sandbox (تجريبي)
            </option>
            <option value="live" <?= sc($conn,'paypal_mode','sandbox')==='live'?'selected':'' ?>>
              Live (إنتاج)
            </option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Client ID <span class="text-danger">*</span></label>
          <input type="text" name="paypal_client_id" class="form-control font-monospace"
                 value="<?= e(sc($conn,'paypal_client_id','')) ?>"
                 placeholder="AXxxx...">
          <div class="form-text">من <strong>developer.paypal.com</strong> → My Apps → REST API apps</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Secret <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="password" name="paypal_secret" id="pp_secret" class="form-control font-monospace"
                   value="<?= e(sc($conn,'paypal_secret','')) ?>"
                   placeholder="EXxxx...">
            <button type="button" class="btn btn-outline-secondary" onclick="toggleVis('pp_secret',this)">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>
        <div class="p-3 rounded" style="background:#e8f4fd;border:1px solid #bee3f8;font-size:12px;color:#1a6696">
          <i class="fas fa-info-circle me-1"></i>
          Return URL يُعيّنها النظام تلقائياً على:
          <code style="background:none"><?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].'/mehkam/public/pay_callback.php' ?></code>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ Paymob ══ -->
  <div class="col-lg-6">
    <div class="card h-100" style="border:2px solid #e0e7ef;border-top:4px solid #6c47ff">
      <div class="card-header d-flex align-items-center gap-3 py-3"
           style="background:#f5f3ff;border-bottom:1px solid #ddd6fe">
        <div style="width:42px;height:42px;background:linear-gradient(135deg,#4c1d95,#6c47ff);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <span style="color:#fff;font-weight:900;font-size:13px;letter-spacing:-0.5px">PAY</span>
        </div>
        <div>
          <div style="font-size:16px;font-weight:800;color:#4c1d95;line-height:1.2">Paymob</div>
          <div style="font-size:11px;color:#7c5cbf">
            <i class="fas fa-credit-card me-1"></i>بطاقة
            <span style="margin:0 4px">·</span>
            <i class="fab fa-apple me-1"></i>Apple Pay
          </div>
        </div>
        <div class="ms-auto d-flex align-items-center gap-2">
          <?php $pm_active = sc($conn,'paymob_enabled','0')==='1'; ?>
          <span style="font-size:12px;font-weight:700;color:<?= $pm_active?'#16a34a':'#9ca3af' ?>">
            <?= $pm_active?'مفعّل':'معطّل' ?>
          </span>
          <div class="gw-toggle" onclick="gwToggle(this,'paymob_enabled')" data-on="<?= $pm_active?'1':'0' ?>"
               style="width:48px;height:26px;border-radius:50px;background:<?= $pm_active?'#16a34a':'#d1d5db' ?>;position:relative;cursor:pointer;transition:background .2s;flex-shrink:0">
            <div style="position:absolute;top:3px;<?= $pm_active?'right:3px':'left:3px' ?>;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.25);transition:all .2s"></div>
          </div>
          <input type="hidden" name="paymob_enabled" id="paymob_enabled_val" value="<?= $pm_active?'1':'0' ?>">
        </div>
      </div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">API Key <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="password" name="paymob_api_key" id="pm_key" class="form-control font-monospace"
                   value="<?= e(sc($conn,'paymob_api_key','')) ?>"
                   placeholder="ZXZhb...">
            <button type="button" class="btn btn-outline-secondary" onclick="toggleVis('pm_key',this)">
              <i class="fas fa-eye"></i>
            </button>
          </div>
          <div class="form-text">من accept.paymob.com → Settings → Account Info</div>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-6">
            <label class="form-label fw-semibold">Card Integration ID</label>
            <input type="text" name="paymob_card_integration_id" class="form-control font-monospace"
                   value="<?= e(sc($conn,'paymob_card_integration_id','')) ?>"
                   placeholder="123456">
            <div class="form-text">Payment Integrations → Online Card</div>
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold">Apple Pay Integration ID</label>
            <input type="text" name="paymob_applepay_integration_id" class="form-control font-monospace"
                   value="<?= e(sc($conn,'paymob_applepay_integration_id','')) ?>"
                   placeholder="789012">
            <div class="form-text">Payment Integrations → Apple Pay</div>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">iframe ID <span class="text-danger">*</span></label>
          <input type="text" name="paymob_iframe_id" class="form-control font-monospace"
                 value="<?= e(sc($conn,'paymob_iframe_id','')) ?>"
                 placeholder="12345">
          <div class="form-text">Payment Integrations → iFrames → رقم الـ iframe</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">رابط الـ iframe (Base URL)</label>
          <input type="text" name="paymob_iframe_base_url" class="form-control font-monospace"
                 value="<?= e(sc($conn,'paymob_iframe_base_url','https://ksa.paymob.com/api/acceptance/iframes')) ?>"
                 placeholder="https://ksa.paymob.com/api/acceptance/iframes">
          <div class="form-text">
            الرابط قبل <code>/{iframe_id}?payment_token=...</code> —
            السعودية: <code>https://ksa.paymob.com/api/acceptance/iframes</code> |
            مصر: <code>https://accept.paymob.com/api/acceptance/iframes</code>
          </div>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-8">
            <label class="form-label fw-semibold">API Base URL <span class="text-danger">*</span></label>
            <input type="text" name="paymob_api_base_url" class="form-control font-monospace"
                   value="<?= e(sc($conn,'paymob_api_base_url','https://ksa.paymob.com')) ?>"
                   placeholder="https://ksa.paymob.com">
            <div class="form-text">
              السعودية: <code>https://ksa.paymob.com</code> | مصر: <code>https://accept.paymob.com</code>
            </div>
          </div>
          <div class="col-4">
            <label class="form-label fw-semibold">العملة</label>
            <input type="text" name="paymob_currency" class="form-control font-monospace"
                   value="<?= e(sc($conn,'paymob_currency','SAR')) ?>"
                   placeholder="SAR">
            <div class="form-text">SAR أو EGP</div>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">HMAC Secret <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="password" name="paymob_hmac_secret" id="pm_hmac" class="form-control font-monospace"
                   value="<?= e(sc($conn,'paymob_hmac_secret','')) ?>"
                   placeholder="abc123...">
            <button type="button" class="btn btn-outline-secondary" onclick="toggleVis('pm_hmac',this)">
              <i class="fas fa-eye"></i>
            </button>
          </div>
          <div class="form-text">Settings → Account Info → HMAC Secret</div>
        </div>
        <div class="p-3 rounded" style="background:#e8f4fd;border:1px solid #bee3f8;font-size:12px;color:#1a6696">
          <i class="fas fa-info-circle me-1"></i>
          أضف Callback URL في لوحة Paymob:
          <code style="background:none"><?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].'/mehkam/public/pay_callback.php' ?></code>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ التحويل البنكي ══ -->
  <div class="col-12">
    <div class="card" style="border:2px solid #e0e7ef;border-top:4px solid #2563eb">
      <div class="card-header d-flex align-items-center gap-3 py-3"
           style="background:#eff6ff;border-bottom:1px solid #bfdbfe">
        <div style="width:42px;height:42px;background:linear-gradient(135deg,#1e3a5f,#2563eb);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-university" style="color:#fff;font-size:18px"></i>
        </div>
        <div>
          <div style="font-size:16px;font-weight:800;color:#1e3a5f;line-height:1.2">التحويل البنكي</div>
          <div style="font-size:11px;color:#3b6ca8">تُعرض بيانات البنك للمكاتب عند طلب الاشتراك</div>
        </div>
        <div class="ms-auto d-flex align-items-center gap-2">
          <?php $bk_active = sc($conn,'bank_enabled','1')==='1'; ?>
          <span style="font-size:12px;font-weight:700;color:<?= $bk_active?'#16a34a':'#9ca3af' ?>">
            <?= $bk_active?'مفعّل':'معطّل' ?>
          </span>
          <div class="gw-toggle" onclick="gwToggle(this,'bank_enabled')" data-on="<?= $bk_active?'1':'0' ?>"
               style="width:48px;height:26px;border-radius:50px;background:<?= $bk_active?'#16a34a':'#d1d5db' ?>;position:relative;cursor:pointer;transition:background .2s;flex-shrink:0">
            <div style="position:absolute;top:3px;<?= $bk_active?'right:3px':'left:3px' ?>;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.25);transition:all .2s"></div>
          </div>
          <input type="hidden" name="bank_enabled" id="bank_enabled_val" value="<?= $bk_active?'1':'0' ?>">
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold">اسم البنك</label>
            <input type="text" name="platform_bank_name" class="form-control"
                   placeholder="مثال: البنك الأهلي السعودي"
                   value="<?= e(sc($conn,'platform_bank_name','')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">رقم الآيبان IBAN</label>
            <input type="text" name="platform_bank_iban" class="form-control font-monospace"
                   placeholder="SA00 0000 0000 0000 0000 0000"
                   value="<?= e(sc($conn,'platform_bank_iban','')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">رقم الحساب (اختياري)</label>
            <input type="text" name="platform_bank_account" class="form-control font-monospace"
                   placeholder="xxxxxxxxxx"
                   value="<?= e(sc($conn,'platform_bank_account','')) ?>">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">تعليمات الدفع</label>
            <textarea name="platform_payment_instructions" class="form-control" rows="2"
                      placeholder="مثال: يرجى الإشارة إلى اسم المكتب في خانة البيان عند إجراء التحويل..."><?= e(sc($conn,'platform_payment_instructions','')) ?></textarea>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- زر الحفظ -->
  <div class="col-12">
    <button type="submit" class="btn btn-primary px-5">
      <i class="fas fa-save me-2"></i>حفظ إعدادات بوابات الدفع
    </button>
  </div>

</div>
</form>

<script>
function toggleVis(id, btn) {
  var inp = document.getElementById(id);
  var ico = btn.querySelector('i');
  if (inp.type === 'password') { inp.type = 'text';     ico.className = 'fas fa-eye-slash'; }
  else                         { inp.type = 'password'; ico.className = 'fas fa-eye'; }
}

function gwToggle(el, key) {
  var isOn = el.dataset.on === '1';
  isOn = !isOn;
  el.dataset.on = isOn ? '1' : '0';
  // حركة الكرة
  var knob = el.querySelector('div');
  el.style.background = isOn ? '#16a34a' : '#d1d5db';
  knob.style.right = isOn ? '3px' : '';
  knob.style.left  = isOn ? '' : '3px';
  // النص
  var label = el.previousElementSibling;
  label.textContent = isOn ? 'مفعّل' : 'معطّل';
  label.style.color = isOn ? '#16a34a' : '#9ca3af';
  // الحقل المخفي
  document.getElementById(key + '_val').value = isOn ? '1' : '0';
}
</script>

<?php include '../includes/admin_footer.php'; ?>
