<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/module_helper.php';
requireOffice();
if (currentRole() !== 'office_owner') { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
$page_title = 'متجر الموديولات الإضافية';

/* ── الجداول ── */
$conn->query("CREATE TABLE IF NOT EXISTS modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    icon VARCHAR(50) DEFAULT 'puzzle-piece',
    base_price DECIMAL(10,2) DEFAULT 0,
    is_active TINYINT DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS office_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    module_key VARCHAR(60) NOT NULL,
    is_enabled TINYINT DEFAULT 1,
    price DECIMAL(10,2) DEFAULT NULL,
    enabled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    enabled_by INT DEFAULT NULL,
    package_id INT DEFAULT NULL,
    UNIQUE KEY uk_office_mod (office_id, module_key),
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $conn->query("ALTER TABLE office_modules ADD COLUMN package_id INT DEFAULT NULL"); } catch (\Throwable $e) {}

$conn->query("CREATE TABLE IF NOT EXISTS module_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    module_key VARCHAR(60) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_by INT DEFAULT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    resolved_by INT DEFAULT NULL,
    notes TEXT,
    amount DECIMAL(10,2) DEFAULT 0,
    gateway VARCHAR(20) DEFAULT 'free',
    txn_ref VARCHAR(100) DEFAULT NULL,
    INDEX idx_office (office_id), INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $conn->query("ALTER TABLE module_requests ADD COLUMN amount DECIMAL(10,2) DEFAULT 0"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE module_requests ADD COLUMN gateway VARCHAR(20) DEFAULT 'free'"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE module_requests ADD COLUMN txn_ref VARCHAR(100) DEFAULT NULL"); } catch (\Throwable $e) {}

$conn->query("CREATE TABLE IF NOT EXISTS module_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    module_key VARCHAR(60) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    gateway VARCHAR(20) DEFAULT 'free',
    txn_ref VARCHAR(100) DEFAULT NULL,
    purchased_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/** يحسب السعر الفعلي لموديول عند هذا المكتب: سعر مخصّص إن وُجد وإلا السعر الأساسي */
function mod_effective_price($conn, $oid, $module_key, $base_price) {
    $mk = $conn->real_escape_string($module_key);
    $r = $conn->query("SELECT price FROM office_modules WHERE office_id=$oid AND module_key='$mk' LIMIT 1")->fetch_assoc();
    if ($r && $r['price'] !== null) return (float)$r['price'];
    return (float)$base_price;
}

/** يمنع تكرار طلب معلّق لنفس الموديول عند نفس المكتب */
function mod_has_pending($conn, $oid, $mk) {
    return (bool)$conn->query("SELECT id FROM module_requests WHERE office_id=$oid AND module_key='$mk' AND status='pending' LIMIT 1")->num_rows;
}

/* ── طلب تفعيل موديول مجاني — يُرفع للأدمن للمراجعة، لا تفعيل فوري ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'activate_free') {
    $mk = $conn->real_escape_string(trim($_POST['module_key'] ?? ''));
    $mod = $mk !== '' ? store_find_item($conn, $oid, $mk) : null;
    if ($mod && !mod_has_pending($conn, $oid, $mk)) {
        $price = mod_effective_price($conn, $oid, $mk, $mod['base_price']);
        if ($price <= 0) {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            $conn->query("INSERT INTO module_requests (office_id,module_key,requested_by,amount,gateway) VALUES ($oid,'$mk',".($uid ?: 'NULL').",0,'free')");
            header("Location: modules.php?msg=requested"); exit;
        }
    }
    header("Location: modules.php?msg=denied"); exit;
}

/* ── بعد نجاح الدفع (بطاقة/PayPal) أو تأكيد تحويل بنكي: تُسجَّل عملية الشراء فوراً للمحاسبة، ويُرفع طلب تفعيل للأدمن للمراجعة النهائية ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'confirm_purchase') {
    $mk  = $conn->real_escape_string(trim($_POST['module_key'] ?? ''));
    $txn = $conn->real_escape_string(trim($_POST['txn_ref'] ?? ''));
    $gw  = $_POST['gateway'] ?? 'paymob';
    $gw  = in_array($gw, ['paymob','paypal','bank'], true) ? $gw : 'paymob';
    $mod = $mk !== '' ? store_find_item($conn, $oid, $mk) : null;
    if ($mod && $txn !== '') {
        // السعر يُحسب من الخادم دوماً — لا نثق بأي مبلغ يصل من الواجهة
        $price = mod_effective_price($conn, $oid, $mk, $mod['base_price']);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $conn->query("INSERT INTO module_purchases (office_id,module_key,amount,gateway,txn_ref,purchased_by) VALUES ($oid,'$mk',$price,'$gw','$txn',".($uid ?: 'NULL').")");
        $conn->query("INSERT INTO module_requests (office_id,module_key,requested_by,amount,gateway,txn_ref) VALUES ($oid,'$mk',".($uid ?: 'NULL').",$price,'$gw','$txn')");
        header("Location: modules.php?msg=requested"); exit;
    }
    header("Location: modules.php?msg=denied"); exit;
}

/* ── إلغاء اشتراك المكتب في موديول مفعّل ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'cancel_module') {
    $mk = $conn->real_escape_string(trim($_POST['module_key'] ?? ''));
    if ($mk !== '') {
        $conn->query("UPDATE office_modules SET is_enabled=0 WHERE office_id=$oid AND module_key='$mk'");
    }
    header("Location: modules.php?msg=cancelled"); exit;
}

/* ── تحميل البيانات ── */
$modules_res = $conn->query("SELECT * FROM modules WHERE is_active=1 ORDER BY sort_order, id");
$modules = [];
while ($m = $modules_res->fetch_assoc()) $modules[] = $m;
foreach (store_feature_items($conn, $oid) as $_fi) $modules[] = $_fi;
$_featPages = feature_page_map();

$om_map = [];
$omr = $conn->query("SELECT om.module_key, (om.is_enabled=1 AND (om.package_id IS NULL OR om.package_id=o.package_id)) AS is_enabled, om.price, om.package_id
    FROM office_modules om JOIN offices o ON o.id=om.office_id WHERE om.office_id=$oid");
while ($r = $omr->fetch_assoc()) $om_map[$r['module_key']] = $r;

$req_map = [];
$reqr = $conn->query("SELECT module_key, amount, gateway FROM module_requests WHERE office_id=$oid AND status='pending'");
while ($r = $reqr->fetch_assoc()) $req_map[$r['module_key']] = $r;

$pm_on = sc($conn,'paymob_enabled','0')==='1' && sc($conn,'paymob_api_key','')!=='';
$pp_on = sc($conn,'paypal_enabled','0')==='1' && sc($conn,'paypal_client_id','')!=='';
$bk_on = sc($conn,'bank_enabled','1')==='1' && (sc($conn,'platform_bank_iban','')!=='' || sc($conn,'platform_bank_account','')!=='');
$_any_pay_on = $pm_on || $pp_on || $bk_on;
$pp_cid = sc($conn,'paypal_client_id','');
$_bk_name = sc($conn,'platform_bank_name','');
$_bk_iban = sc($conn,'platform_bank_iban','');
$_bk_acct = sc($conn,'platform_bank_account','');
$_office_owner_name = $_SESSION['full_name'] ?? '';
$_office_email = $conn->query("SELECT email FROM users WHERE id=".(int)($_SESSION['user_id'] ?? 0))->fetch_assoc()['email'] ?? '';

include '../includes/office_header.php';
?>

<style>
.mm-card{background:#fff;border:1px solid #eef1f6;border-radius:14px;padding:18px;height:100%;display:flex;flex-direction:column}
.mm-ico{width:46px;height:46px;border-radius:12px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:12px}
.mm-price{font-size:13px;color:#64748b;margin-bottom:10px}
.mm-price b{font-size:17px;color:#0c1b36}
</style>

<div class="mk-page-title mb-1"><i class="fas fa-store"></i> متجر الموديولات الإضافية</div>
<div class="mk-page-sub mb-4">تصفّح واطلب تفعيل أي موديول لمكتبك — يراجعه فريق الدعم ويفعّله لك</div>

<?php if (isset($_GET['msg']) && $_GET['msg']==='requested'): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم إرسال طلبك بنجاح — سيراجعه فريق الدعم ويُفعَّل لك قريباً
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_GET['msg']) && $_GET['msg']==='denied'): ?>
<div class="alert alert-danger alert-dismissible fade show">
  <i class="fas fa-exclamation-triangle me-2"></i>تعذّر إتمام العملية، حاول مجدداً
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_GET['msg']) && $_GET['msg']==='cancelled'): ?>
<div class="alert alert-warning alert-dismissible fade show">
  <i class="fas fa-circle-info me-2"></i>تم إلغاء اشتراكك في الموديول، ولن يظهر بعد الآن في قائمتك الجانبية
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3">
<?php if (!$modules): ?>
<div class="col-12"><div class="text-muted text-center py-5">لا توجد موديولات متاحة حالياً</div></div>
<?php else: $_feat_head = false; foreach ($modules as $m):
  if (!empty($m['is_feature']) && !$_feat_head):
    $_feat_head = true; ?>
<div class="col-12"><div class="fw-bold mt-2" style="color:#0c1b36"><i class="fas fa-layer-group me-2"></i>ميزات غير مضمّنة في باقتك الحالية</div>
  <div class="text-muted" style="font-size:12px">هذه ميزات أساسية ليست ضمن باقتك — يمكنك إضافتها لمكتبك بشكل منفصل</div></div>
<?php endif;
  $st = $om_map[$m['module_key']] ?? null;
  $enabled = $st && (int)$st['is_enabled'] === 1;
  $pendingReq = $req_map[$m['module_key']] ?? null;
  $price = mod_effective_price($conn, $oid, $m['module_key'], $m['base_price']);
?>
<div class="col-md-6 col-lg-4">
  <div class="mm-card">
    <div class="mm-ico"><i class="fas fa-<?= e($m['icon']) ?>"></i></div>
    <div class="fw-bold mb-1"><?= e($m['name']) ?></div>
    <div class="text-muted flex-grow-1 mb-2" style="font-size:12.5px"><?= e($m['description']) ?></div>
    <?php if (!$enabled && !$pendingReq): ?>
    <div class="mm-price"><?= $price > 0 ? '<b>'.number_format($price,2).'</b> ر.س/سنة' : '<b class="text-success">مجاني</b>' ?></div>
    <?php endif; ?>
    <div class="mt-auto">
      <?php if ($enabled): ?>
        <span class="badge bg-success bg-opacity-10 text-success w-100 py-2"><i class="fas fa-check-circle me-1"></i>مفعَّل لمكتبك<?= !empty($st['package_id']) ? ' — ضمن باقتك المخصصة' : '' ?></span>
        <a href="<?= e(!empty($m['is_feature']) ? ($_featPages[$m['module_key']] ?? 'dashboard.php') : $m['module_key'].'.php') ?>" class="btn btn-outline-primary btn-sm w-100 mt-2"><i class="fas fa-arrow-left me-1"></i>فتح</a>
        <form method="POST" class="mt-2" onsubmit="return confirm('إلغاء الاشتراك في «<?= e(addslashes($m['name'])) ?>»؟ ستفقد أنت وموظفوك الوصول لهذا الموديول فوراً.')">
          <input type="hidden" name="form_type" value="cancel_module">
          <input type="hidden" name="module_key" value="<?= e($m['module_key']) ?>">
          <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="fas fa-ban me-1"></i>إلغاء الاشتراك</button>
        </form>
      <?php elseif ($pendingReq): ?>
        <span class="badge bg-warning bg-opacity-10 text-warning w-100 py-2 d-block">
          <i class="fas fa-clock me-1"></i>طلبك قيد مراجعة الأدمن
          <?php if ((float)$pendingReq['amount'] > 0): ?><br><small>تم الدفع: <?= number_format((float)$pendingReq['amount'],2) ?> ر.س</small><?php endif; ?>
        </span>
      <?php elseif ($price <= 0): ?>
        <form method="POST">
          <input type="hidden" name="form_type" value="activate_free">
          <input type="hidden" name="module_key" value="<?= e($m['module_key']) ?>">
          <button type="submit" class="btn btn-success btn-sm w-100"><i class="fas fa-paper-plane me-1"></i>طلب التفعيل (مجاني)</button>
        </form>
      <?php elseif ($_any_pay_on): ?>
        <button type="button" class="btn btn-primary btn-sm w-100"
                onclick='mmOpenPurchase(<?= json_encode($m['module_key']) ?>, <?= (float)$price ?>, <?= json_encode($m['name']) ?>)'>
          <i class="fas fa-cart-shopping me-1"></i>اشترِ الآن
        </button>
      <?php else: ?>
        <span class="badge bg-secondary w-100 py-2">الدفع غير متاح حالياً — تواصل مع الدعم</span>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- نافذة الشراء (تختار طريقة الدفع حسب ما فعّله الأدمن في إعدادات بوابات الدفع) -->
<div id="mmPurchaseModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;overflow:hidden;width:min(520px,95vw);max-height:92vh;display:flex;flex-direction:column">
    <div style="padding:14px 18px;background:#0c1b36;color:#fff;display:flex;align-items:center;justify-content:space-between">
      <div>
        <div style="font-weight:700"><i class="fas fa-lock me-2"></i>إتمام الاشتراك</div>
        <div id="mmPurchaseTitle" style="font-size:12px;opacity:.85;margin-top:2px"></div>
      </div>
      <button onclick="mmClosePurchase()" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer">×</button>
    </div>
    <div style="padding:16px 18px;overflow-y:auto">
      <div class="d-flex gap-2 flex-wrap mb-3">
        <?php if ($pm_on): ?><button type="button" class="btn btn-outline-primary btn-sm mm-method-btn" id="mmBtn-card" onclick="mmSelectMethod('card')"><i class="fas fa-credit-card me-1"></i>بطاقة/Apple Pay</button><?php endif; ?>
        <?php if ($pp_on): ?><button type="button" class="btn btn-outline-primary btn-sm mm-method-btn" id="mmBtn-paypal" onclick="mmSelectMethod('paypal')"><i class="fab fa-paypal me-1"></i>PayPal</button><?php endif; ?>
        <?php if ($bk_on): ?><button type="button" class="btn btn-outline-primary btn-sm mm-method-btn" id="mmBtn-bank" onclick="mmSelectMethod('bank')"><i class="fas fa-university me-1"></i>تحويل بنكي</button><?php endif; ?>
      </div>

      <?php if ($pm_on): ?>
      <div id="mm-sec-card" style="display:none">
        <div id="mmCardStatus" class="text-muted mb-2" style="font-size:13px">اضغط الزر لتحميل صفحة الدفع الآمنة...</div>
        <button type="button" class="btn btn-primary btn-sm w-100 mb-2" id="mmCardStartBtn" onclick="mmStartCard()"><i class="fas fa-credit-card me-1"></i>الدفع بالبطاقة</button>
        <iframe id="mmPayFrame" src="" style="width:100%;border:none;min-height:460px;display:none"></iframe>
      </div>
      <?php endif; ?>

      <?php if ($pp_on): ?>
      <div id="mm-sec-paypal" style="display:none">
        <div id="mmPaypalButtons"></div>
      </div>
      <?php endif; ?>

      <?php if ($bk_on): ?>
      <div id="mm-sec-bank" style="display:none">
        <div class="mb-3" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px;font-size:13px">
          <?php if ($_bk_name): ?><div class="mb-1"><b>البنك:</b> <?= e($_bk_name) ?></div><?php endif; ?>
          <?php if ($_bk_iban): ?><div class="mb-1"><b>IBAN:</b> <span class="font-monospace"><?= e($_bk_iban) ?></span></div><?php endif; ?>
          <?php if ($_bk_acct): ?><div class="mb-1"><b>رقم الحساب:</b> <span class="font-monospace"><?= e($_bk_acct) ?></span></div><?php endif; ?>
          <div class="mt-2" style="color:#92400e"><i class="fas fa-info-circle me-1"></i>بعد التحويل اضغط تأكيد أدناه — سيراجع فريق الدعم التحويل ويفعّل الموديول بعد التأكد منه.</div>
        </div>
        <form method="POST" id="mmBankForm">
          <input type="hidden" name="form_type" value="confirm_purchase">
          <input type="hidden" name="gateway" value="bank">
          <input type="hidden" name="module_key" id="mmBankKey">
          <div class="mb-2">
            <label class="form-label" style="font-size:12px">رقم/مرجع التحويل (اختياري)</label>
            <input type="text" name="txn_ref" id="mmBankRef" class="form-control form-control-sm" placeholder="اتركه فارغاً إن لم يتوفر">
          </div>
          <button type="submit" class="btn btn-success btn-sm w-100"><i class="fas fa-check me-1"></i>تأكيد أنني قمت بالتحويل</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- فورم مخفي لتأكيد الشراء بعد نجاح الدفع (بطاقة/PayPal) -->
<form method="POST" id="mmConfirmForm" class="d-none">
  <input type="hidden" name="form_type" value="confirm_purchase">
  <input type="hidden" name="gateway" id="mmConfirmGateway" value="paymob">
  <input type="hidden" name="module_key" id="mmConfirmKey">
  <input type="hidden" name="txn_ref" id="mmConfirmTxn">
</form>

<script>
var _mmCurrent = {key:'', price:0, name:''};
var _mmPaypalRendered = false;
var _mmDefaultMethod = <?= $pm_on ? "'card'" : ($pp_on ? "'paypal'" : "'bank'") ?>;

function mmOpenPurchase(key, price, name) {
  _mmCurrent = {key:key, price:price, name:name};
  document.getElementById('mmPurchaseTitle').textContent = name + ' — ' + price.toFixed(2) + ' ر.س/سنة';
  <?php if ($bk_on): ?>document.getElementById('mmBankKey').value = key;<?php endif; ?>
  document.getElementById('mmPurchaseModal').style.display = 'flex';
  mmSelectMethod(_mmDefaultMethod);
}
function mmClosePurchase() {
  document.getElementById('mmPurchaseModal').style.display = 'none';
  <?php if ($pm_on): ?>
  document.getElementById('mmPayFrame').src = '';
  document.getElementById('mmPayFrame').style.display = 'none';
  document.getElementById('mmCardStartBtn').style.display = '';
  <?php endif; ?>
}
function mmSelectMethod(method) {
  ['card','paypal','bank'].forEach(function (m) {
    var sec = document.getElementById('mm-sec-' + m);
    if (sec) sec.style.display = (m === method) ? '' : 'none';
    var btn = document.getElementById('mmBtn-' + m);
    if (btn) btn.classList.toggle('btn-primary', m === method);
    if (btn) btn.classList.toggle('btn-outline-primary', m !== method);
  });
  <?php if ($pp_on): ?>if (method === 'paypal') mmStartPaypal();<?php endif; ?>
}

<?php if ($pm_on): ?>
function mmStartCard() {
  var btn = document.getElementById('mmCardStartBtn');
  btn.disabled = true; btn.innerHTML = 'جاري التحضير...';
  var fd = new FormData();
  fd.append('amount', _mmCurrent.price);
  fd.append('method', 'card');
  fd.append('ref', 'MODPUR_<?= $oid ?>_' + _mmCurrent.key + '_' + Date.now());
  fd.append('name', <?= json_encode($_office_owner_name) ?> || 'Office Owner');
  fd.append('email', <?= json_encode($_office_email) ?> || 'office@mehkam.app');
  fd.append('phone', '0500000000');
  fetch('../public/ajax_paymob_init.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-credit-card me-1"></i>الدفع بالبطاقة';
      if (d.ok && d.iframe_url) {
        var f = document.getElementById('mmPayFrame');
        f.src = d.iframe_url; f.style.display = '';
        btn.style.display = 'none';
      } else {
        alert(d.error || 'فشل الاتصال ببوابة الدفع');
      }
    })
    .catch(function () {
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-credit-card me-1"></i>الدفع بالبطاقة';
      alert('خطأ في الاتصال بالشبكة');
    });
}
window.addEventListener('message', function (e) {
  var d = e.data;
  if (!d || (d.type !== 'PAYMOB_CALLBACK' && d.type !== 'PAYMOB_SUCCESS')) return;
  if (d.success || d.type === 'PAYMOB_SUCCESS') {
    document.getElementById('mmConfirmGateway').value = 'paymob';
    document.getElementById('mmConfirmKey').value = _mmCurrent.key;
    document.getElementById('mmConfirmTxn').value = d.txn_id || d.ref || ('paymob_' + Date.now());
    document.getElementById('mmConfirmForm').submit();
  } else {
    alert('فشل الدفع. يرجى المحاولة مرة أخرى.');
  }
});
<?php endif; ?>

<?php if ($pp_on): ?>
function mmStartPaypal() {
  var box = document.getElementById('mmPaypalButtons');
  box.innerHTML = '';
  if (typeof paypal === 'undefined') { box.innerHTML = '<div class="text-danger" style="font-size:12px">تعذّر تحميل PayPal</div>'; return; }
  paypal.Buttons({
    createOrder: function () {
      var fd = new FormData();
      fd.append('amount', _mmCurrent.price);
      fd.append('ref', 'MODPUR_<?= $oid ?>_' + _mmCurrent.key + '_' + Date.now());
      return fetch('../public/ajax_paypal_order.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.error) throw new Error(d.error); return d.id; });
    },
    onApprove: function (data) {
      var fd = new FormData();
      fd.append('order_id', data.orderID);
      return fetch('../public/ajax_paypal_capture.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.ok) {
            document.getElementById('mmConfirmGateway').value = 'paypal';
            document.getElementById('mmConfirmKey').value = _mmCurrent.key;
            document.getElementById('mmConfirmTxn').value = data.orderID;
            document.getElementById('mmConfirmForm').submit();
          } else {
            alert('فشل تأكيد الدفع. يرجى المحاولة مجدداً.');
          }
        });
    },
    onError: function (err) { alert('خطأ في PayPal: ' + err); }
  }).render('#mmPaypalButtons');
}
<?php endif; ?>

<?php if ($bk_on): ?>
document.getElementById('mmBankForm').addEventListener('submit', function () {
  if (!document.getElementById('mmBankRef').value.trim()) {
    document.getElementById('mmBankRef').value = 'BANK_' + Date.now();
  }
});
<?php endif; ?>
</script>

<?php if ($pp_on): ?>
<script src="https://www.paypal.com/sdk/js?client-id=<?= e($pp_cid) ?>&currency=SAR&locale=ar_SA&components=buttons" data-sdk-integration-source="button-factory"></script>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
