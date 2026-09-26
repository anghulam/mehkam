<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/mailer.php';

requireAdmin();
$page_title = 'إعدادات المنصة';

/* ── فحص بيانات OAuth لـ Google Drive (AJAX) ── */
if (isset($_GET['gdrive_check'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (is_file('../includes/gdrive_helper.php')) require_once '../includes/gdrive_helper.php';
    echo json_encode(function_exists('gd_oauth_verify_credentials')
        ? gd_oauth_verify_credentials()
        : ['ok' => false, 'msg' => 'حدّث ملف gdrive_helper.php']);
    exit;
}

/* ── تيليغرام: تفعيل الويبهوك / اختبار (AJAX) ── */
if (isset($_GET['tg_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once '../includes/telegram.php';
    $act = $_GET['tg_action'];
    if (!tg_configured($conn)) {
        echo json_encode(['ok' => false, 'msg' => 'الصق توكن البوت في الحقل أعلاه ثم اضغط «حفظ جميع الإعدادات» في أسفل الصفحة أولاً.']);
        exit;
    }
    if ($act === 'setwebhook') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/public/telegram_webhook.php';
        $r = tg_api($conn, 'setWebhook', ['url' => $url]);
        echo json_encode(['ok' => !empty($r['ok']), 'msg' => ($r['description'] ?? ($r['result'] ?? '')) . ' — ' . $url]);
        exit;
    }
    if ($act === 'info') {
        $r = tg_api($conn, 'getWebhookInfo');
        echo json_encode(['ok' => !empty($r['ok']), 'msg' => json_encode($r['result'] ?? $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        exit;
    }
    if ($act === 'test') {
        $me  = (int)($_SESSION['user_id'] ?? 0);
        $row = $conn->query("SELECT telegram_chat_id FROM users WHERE id=$me LIMIT 1");
        $cid = $row ? ($row->fetch_assoc()['telegram_chat_id'] ?? '') : '';
        if (!$cid) { echo json_encode(['ok' => false, 'msg' => 'اربط حسابك بتيليغرام أولاً من ملفك الشخصي (كمستخدم مكتب) — أو جرّب مع chat_id محدد.']); exit; }
        $ok = tg_send($conn, $cid, '✅ رسالة اختبار من منصة مِحكام. الإعداد يعمل.');
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'أُرسلت — تحقق من تيليغرام' : 'فشل الإرسال، راجع التوكن']);
        exit;
    }
    echo json_encode(['ok' => false, 'msg' => 'إجراء غير معروف']); exit;
}

/* ── اختبار التكامل الحكومي (AJAX) ── */
if (isset($_GET['gov_test'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once '../includes/gov_integrations.php';
    $svc = $_GET['gov_test'];
    if ($svc === 'wathiq') {
        $num = preg_replace('/\D/', '', $_GET['num'] ?? '');
        if (!$num) { echo json_encode(['ok' => false, 'msg' => 'أدخل رقم سجل تجاري للاختبار']); exit; }
        $r = wathiq_lookup($conn, $num);
        echo json_encode(['ok' => $r['ok'], 'msg' => $r['ok'] ? ('✔ ' . $r['name'] . ' — الحالة: ' . $r['status']) : $r['msg']]);
        exit;
    }
    if ($svc === 'nafath') {
        $nid = preg_replace('/\D/', '', $_GET['num'] ?? '');
        $r = nafath_request($conn, $nid);
        echo json_encode(['ok' => $r['ok'], 'msg' => $r['msg'] . ($r['ok'] && !empty($r['random']) ? ' — الرقم: ' . $r['random'] : '')]);
        exit;
    }
    $st = gov_status_all($conn);
    echo json_encode(['ok' => ($st[$svc]['ready'] ?? false), 'msg' => ($st[$svc]['ready'] ?? false) ? 'الإعداد مكتمل' : 'الإعداد غير مكتمل — راجع الحقول']);
    exit;
}

/* ── اختبار الإيميل (AJAX) ── */
if (isset($_POST['test_email'])) {
    $to = trim($_POST['test_to'] ?? sc($conn,'contact_email',''));
    if (!$to) { echo json_encode(['ok'=>false,'error'=>'أدخل بريداً للاختبار']); exit; }
    $site = sc($conn,'site_name','مِحكام');
    $html = mailHtml($site,'اختبار إعدادات البريد',"<p>هذا بريد اختباري من منصة <strong>{$site}</strong>. إذا وصلك هذا البريد، فإعدادات SMTP تعمل بشكل صحيح.</p>");
    $r = sendMail($conn,$to,'اختبار','اختبار إعدادات SMTP',$html);
    echo json_encode($r); exit;
}

/* ── حفظ الإعدادات ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $all_fields = [
        // عام
        'site_name','site_desc','contact_email','contact_phone','contact_address',
        'twitter','linkedin','whatsapp','hero_title','hero_text','btn_register','btn_learn',
        // SMTP
        'smtp_enabled','smtp_host','smtp_port','smtp_enc','smtp_user','smtp_from_name','smtp_from_email',
        // أمان
        'session_timeout','login_attempts','trial_days',
        // ZATCA
        'platform_vat','platform_cr',
        // التكامل الحكومي
        'nafath_base_url','nafath_app_id',
        'wathiq_base_url','wathiq_api_key',
        'zatca_env','zatca_base_url',
        'etimad_base_url','etimad_api_key',
        'ai_api_key','ai_model',
    ];
    // حقول بالتبديل (checkbox)
    $toggle_fields = ['two_factor_enabled','email_verify_enabled',
        'nafath_enabled','wathiq_enabled','zatca_enabled','etimad_enabled'];

    foreach ($all_fields as $key) {
        if (isset($_POST[$key])) {
            $val = $conn->real_escape_string(trim($_POST[$key]));
            $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('$key','$val') ON DUPLICATE KEY UPDATE setting_value='$val'");
        }
    }
    foreach ($toggle_fields as $key) {
        $val = isset($_POST[$key]) ? '1' : '0';
        $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('$key','$val') ON DUPLICATE KEY UPDATE setting_value='$val'");
    }

    // ── شعار المنصّة (يظهر في الدخول ولوحة الإدارة والموقع التعريفي) ──
    if (!empty($_POST['rm_site_logo'])) {
        $conn->query("DELETE FROM site_content WHERE setting_key='site_logo'");
    }
    if (!empty($_FILES['site_logo']['name']) && is_uploaded_file($_FILES['site_logo']['tmp_name'] ?? '')) {
        $lf   = $_FILES['site_logo'];
        $lext = strtolower(pathinfo($lf['name'], PATHINFO_EXTENSION));
        $allw = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'];
        if (($lf['error'] ?? 1) === 0 && $lf['size'] > 0 && $lf['size'] <= 3 * 1024 * 1024 && in_array($lext, $allw, true)) {
            $valid = ($lext === 'svg')
                ? (bool) preg_match('/<svg[\s>]/i', (string) @file_get_contents($lf['tmp_name'], false, null, 0, 4096))
                : (($gi = @getimagesize($lf['tmp_name'])) && in_array($gi[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true));
            if ($valid) {
                $ldir = dirname(__DIR__) . '/assets/img';
                if (!is_dir($ldir)) @mkdir($ldir, 0755, true);
                $lname = 'site-logo-' . time() . '.' . ($lext === 'jpeg' ? 'jpg' : $lext);
                if (@move_uploaded_file($lf['tmp_name'], $ldir . '/' . $lname)) {
                    // نظّف الشعارات القديمة
                    foreach (glob($ldir . '/site-logo-*.*') ?: [] as $old) {
                        if (basename($old) !== $lname) @unlink($old);
                    }
                    $lpath = $conn->real_escape_string('/assets/img/' . $lname);
                    $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('site_logo','$lpath') ON DUPLICATE KEY UPDATE setting_value='$lpath'");
                }
            }
        }
    }
    // كلمة مرور SMTP — لا تُحفظ إلا إذا أُدخلت
    if (!empty($_POST['smtp_pass'])) {
        $val = $conn->real_escape_string(trim($_POST['smtp_pass']));
        $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('smtp_pass','$val') ON DUPLICATE KEY UPDATE setting_value='$val'");
    }

    // مفاتيح سرية للتكامل الحكومي — تُحفظ فقط عند إدخالها
    foreach (['nafath_app_key','zatca_binary_token','zatca_secret'] as $sk) {
        if (!empty($_POST[$sk])) {
            $val = $conn->real_escape_string(trim($_POST[$sk]));
            $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('$sk','$val') ON DUPLICATE KEY UPDATE setting_value='$val'");
        }
    }

    // بيانات OAuth Client لـ Google Drive — تُحفظ في قاعدة البيانات
    if (isset($_POST['gdrive_oauth_client_id'])) {
        $cid = $conn->real_escape_string(trim($_POST['gdrive_oauth_client_id']));
        $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('gdrive_oauth_client_id','$cid') ON DUPLICATE KEY UPDATE setting_value='$cid'");
    }
    if (isset($_POST['gdrive_redirect_uri'])) {
        $ru = $conn->real_escape_string(rtrim(trim($_POST['gdrive_redirect_uri']), '/'));
        $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('gdrive_redirect_uri','$ru') ON DUPLICATE KEY UPDATE setting_value='$ru'");
    }

    // ── تيليغرام ──
    foreach (['telegram_bot_username','telegram_lead_hours','telegram_digest_hour'] as $tk) {
        if (isset($_POST[$tk])) {
            $tv = $conn->real_escape_string(trim($_POST[$tk]));
            if ($tk === 'telegram_bot_username') $tv = ltrim($tv, '@');
            $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('$tk','$tv') ON DUPLICATE KEY UPDATE setting_value='$tv'");
        }
    }
    $tg_token_saved = false;
    if (!empty($_POST['telegram_bot_token'])) {
        $tv = $conn->real_escape_string(trim($_POST['telegram_bot_token']));
        $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('telegram_bot_token','$tv') ON DUPLICATE KEY UPDATE setting_value='$tv'");
        $tg_token_saved = true;
    }
    // مفتاح تشغيل الكرون — يُولَّد تلقائياً إن لم يوجد
    $ck = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='cron_secret' LIMIT 1");
    if (!$ck || !$ck->num_rows) {
        $cs = bin2hex(random_bytes(12));
        $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('cron_secret','$cs') ON DUPLICATE KEY UPDATE setting_value=setting_value");
    }
    // تفعيل ويبهوك تيليغرام تلقائياً عند حفظ توكن جديد
    if ($tg_token_saved) {
        require_once '../includes/telegram.php';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $whurl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/public/telegram_webhook.php';
        @tg_api($conn, 'setWebhook', ['url' => $whurl]);
    }
    if (!empty($_POST['gdrive_oauth_client_secret'])) {
        $sec = $conn->real_escape_string(trim($_POST['gdrive_oauth_client_secret']));
        $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('gdrive_oauth_client_secret','$sec') ON DUPLICATE KEY UPDATE setting_value='$sec'");
    }

    header("Location: settings.php?msg=saved"); exit;
}

include '../includes/admin_header.php';

$s = fn($k,$d='') => sc($conn,$k,$d);
$ch = fn($k) => $s($k,'0')==='1'; // checkbox
?>

<form method="POST" id="settingsForm" enctype="multipart/form-data">
<div class="row g-3">

  <!-- ══ عام ══ -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header fw-bold"><i class="fas fa-globe me-2 text-primary"></i>إعدادات عامة</div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">اسم المنصة</label>
          <input type="text" name="site_name" class="form-control" value="<?= e($s('site_name','مِحكام')) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">وصف قصير</label>
          <input type="text" name="site_desc" class="form-control" value="<?= e($s('site_desc')) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">شعار المنصّة</label>
          <?php $__lg = $s('site_logo',''); ?>
          <?php if ($__lg): ?>
          <div class="mb-2 p-2 rounded d-inline-block" style="background:#0c1b36">
            <img src="<?= e($__lg) ?>" alt="شعار المنصّة" style="max-height:48px;max-width:200px;display:block">
          </div>
          <?php endif; ?>
          <input type="file" name="site_logo" accept=".png,.jpg,.jpeg,.webp,.svg,.gif" class="form-control">
          <div class="form-text">
            PNG أو SVG بخلفية شفافة يُفضّل · حتى ٣ ميغابايت.
            يظهر في صفحة تسجيل الدخول ولوحة الإدارة والموقع التعريفي. لا يؤثّر على شعار كل مكتب.
          </div>
          <?php if ($__lg): ?>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="rm_site_logo" id="rm_site_logo" value="1">
            <label class="form-check-label" for="rm_site_logo">حذف الشعار الحالي والرجوع للافتراضي</label>
          </div>
          <?php endif; ?>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">البريد الإلكتروني للتواصل</label>
          <input type="email" name="contact_email" class="form-control" value="<?= e($s('contact_email')) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">رقم الجوال للتواصل</label>
          <input type="text" name="contact_phone" class="form-control" value="<?= e($s('contact_phone')) ?>">
        </div>

        <hr>
        <div class="fw-bold mb-2" style="font-size:13px;color:#374151"><i class="fas fa-share-nodes me-1 text-primary"></i>روابط التواصل الاجتماعي (تظهر في تذييل الموقع)</div>
        <div class="mb-3">
          <label class="form-label fw-semibold"><i class="fab fa-twitter me-1"></i>تويتر (X)</label>
          <input type="url" name="twitter" class="form-control" placeholder="https://x.com/..." value="<?= e($s('twitter')) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold"><i class="fab fa-linkedin-in me-1"></i>لينكدإن</label>
          <input type="url" name="linkedin" class="form-control" placeholder="https://linkedin.com/company/..." value="<?= e($s('linkedin')) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold"><i class="fab fa-whatsapp me-1"></i>واتساب</label>
          <input type="text" name="whatsapp" class="form-control" placeholder="9665xxxxxxxx (بدون + أو صفر البداية)" value="<?= e($s('whatsapp')) ?>">
          <div class="form-text">يُستخدم لإنشاء رابط واتساب مباشر — أدخل الرقم بصيغة دولية بدون علامة + أو أصفار في البداية.</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">مدة التجربة المجانية (يوم)</label>
          <input type="number" name="trial_days" class="form-control" min="1" value="<?= e($s('trial_days','14')) ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- ══ أمان ══ -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-shield-alt me-2 text-warning"></i>إعدادات الأمان</div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">مدة الجلسة (دقيقة)</label>
          <input type="number" name="session_timeout" class="form-control" min="5" value="<?= e($s('session_timeout','120')) ?>">
          <div class="form-text">بعد هذه المدة من عدم النشاط سيتم تسجيل الخروج تلقائياً</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">أقصى محاولات دخول فاشلة</label>
          <input type="number" name="login_attempts" class="form-control" min="3" max="20" value="<?= e($s('login_attempts','5')) ?>">
        </div>

        <!-- toggle 2FA -->
        <div class="p-3 rounded-3 mb-3" style="background:#f8fafc;border:1px solid #e2e8f0">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-bold" style="font-size:14px"><i class="fas fa-mobile-alt me-2 text-primary"></i>التحقق الثنائي (2FA)</div>
              <div style="font-size:12px;color:#6b7280">إرسال رمز OTP للبريد عند كل دخول</div>
            </div>
            <label class="switch-lbl" style="cursor:pointer;margin:0">
              <input type="checkbox" name="two_factor_enabled" <?= $ch('two_factor_enabled') ? 'checked' : '' ?> style="display:none" onchange="this.closest('.switch-lbl').querySelector('.sw-track').style.background=this.checked?'#2563eb':'#e2e8f0';this.closest('.switch-lbl').querySelector('.sw-knob').style.right=this.checked?'2px':'calc(100% - 22px)'"
              >
              <div class="sw-track" style="position:relative;width:44px;height:24px;border-radius:50px;background:<?= $ch('two_factor_enabled')?'#2563eb':'#e2e8f0' ?>;transition:background .2s">
                <div class="sw-knob" style="position:absolute;top:3px;<?= $ch('two_factor_enabled')?'right:2px':'right:calc(100% - 22px)' ?>;width:18px;height:18px;border-radius:50%;background:#fff;transition:right .2s;box-shadow:0 1px 3px rgba(0,0,0,.3)"></div>
              </div>
            </label>
          </div>
        </div>

        <!-- toggle email verify -->
        <div class="p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-bold" style="font-size:14px"><i class="fas fa-envelope-circle-check me-2 text-success"></i>التحقق من البريد عند التسجيل</div>
              <div style="font-size:12px;color:#6b7280">إرسال رابط تأكيد للبريد بعد إنشاء الحساب</div>
            </div>
            <label class="switch-lbl" style="cursor:pointer;margin:0">
              <input type="checkbox" name="email_verify_enabled" <?= $ch('email_verify_enabled') ? 'checked' : '' ?> style="display:none" onchange="this.closest('.switch-lbl').querySelector('.sw-track').style.background=this.checked?'#16a34a':'#e2e8f0';this.closest('.switch-lbl').querySelector('.sw-knob').style.right=this.checked?'2px':'calc(100% - 22px)'"
              >
              <div class="sw-track" style="position:relative;width:44px;height:24px;border-radius:50px;background:<?= $ch('email_verify_enabled')?'#16a34a':'#e2e8f0' ?>;transition:background .2s">
                <div class="sw-knob" style="position:absolute;top:3px;<?= $ch('email_verify_enabled')?'right:2px':'right:calc(100% - 22px)' ?>;width:18px;height:18px;border-radius:50%;background:#fff;transition:right .2s;box-shadow:0 1px 3px rgba(0,0,0,.3)"></div>
              </div>
            </label>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ SMTP ══ -->
  <div class="col-12">
    <?php
      $smtp_ok_fields = [];
      $smtp_miss = [];
      foreach (['smtp_host'=>'SMTP Host','smtp_user'=>'اسم المستخدم','smtp_pass'=>'كلمة المرور','smtp_from_email'=>'بريد المرسل'] as $fk=>$fl) {
        if ($s($fk)) $smtp_ok_fields[] = $fl; else $smtp_miss[] = $fl;
      }
      $smtp_ready = empty($smtp_miss);
      $smtp_on    = $s('smtp_enabled','0') === '1';
    ?>
    <div class="card">
      <div class="card-header d-flex align-items-center gap-3 py-3"
           style="background:linear-gradient(135deg,#0e4d91,#1a73e8);color:#fff;border:none">
        <div>
          <i class="fas fa-envelope me-2"></i>إعدادات البريد الإلكتروني (SMTP)
          <span class="badge bg-warning text-dark ms-2" style="font-size:11px">مطلوب للـ 2FA وتأكيد البريد</span>
        </div>
        <div class="ms-auto d-flex align-items-center gap-2">
          <span style="font-size:12px;font-weight:700" id="smtpEnabledLbl"><?= $smtp_on ? 'مفعّل' : 'معطّل' ?></span>
          <div id="smtpToggle" onclick="gwToggleSMTP(this)" data-on="<?= $smtp_on?'1':'0' ?>"
               style="width:48px;height:26px;border-radius:50px;background:<?= $smtp_on?'#16a34a':'#d1d5db' ?>;position:relative;cursor:pointer;transition:background .2s;flex-shrink:0">
            <div style="position:absolute;top:3px;<?= $smtp_on?'right:3px':'left:3px' ?>;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.25);transition:all .2s"></div>
          </div>
          <input type="hidden" name="smtp_enabled" id="smtp_enabled_val" value="<?= $smtp_on?'1':'0' ?>">
        </div>
      </div>
      <div class="card-body">

        <!-- حالة الإعداد -->
        <?php if ($smtp_ready): ?>
        <div class="alert alert-success py-2 d-flex align-items-center gap-2 mb-3">
          <i class="fas fa-check-circle"></i>
          <span>جميع حقول SMTP مكتملة — يمكنك الاختبار الآن</span>
        </div>
        <?php else: ?>
        <div class="alert alert-warning py-2 mb-3" style="font-size:13px">
          <i class="fas fa-exclamation-triangle me-1"></i>
          <strong>حقول ناقصة:</strong>
          <?= implode('، ', array_map('htmlspecialchars', $smtp_miss)) ?>
          — يجب ملؤها لإرسال البريد
        </div>
        <?php endif; ?>

        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label fw-semibold">SMTP Host <span class="text-danger">*</span></label>
            <input type="text" name="smtp_host" id="smtpHost" class="form-control <?= $s('smtp_host')?'is-valid':'' ?>"
                   placeholder="smtp.gmail.com" value="<?= e($s('smtp_host')) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label fw-semibold">المنفذ (Port)</label>
            <input type="number" name="smtp_port" id="smtpPort" class="form-control" value="<?= e($s('smtp_port','587')) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label fw-semibold">التشفير</label>
            <select name="smtp_enc" id="smtpEnc" class="form-select">
              <option value="tls"  <?= $s('smtp_enc','tls')==='tls' ?'selected':'' ?>>TLS (587)</option>
              <option value="ssl"  <?= $s('smtp_enc')==='ssl'  ?'selected':'' ?>>SSL (465)</option>
              <option value="none" <?= $s('smtp_enc')==='none' ?'selected':'' ?>>None (25)</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">اسم المرسل</label>
            <input type="text" name="smtp_from_name" class="form-control"
                   placeholder="مِحكام" value="<?= e($s('smtp_from_name',$s('site_name','مِحكام'))) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">البريد المرسل (From Email) <span class="text-danger">*</span></label>
            <input type="email" name="smtp_from_email" class="form-control <?= $s('smtp_from_email')?'is-valid':'' ?>"
                   placeholder="noreply@example.com"
                   value="<?= e($s('smtp_from_email',$s('contact_email',''))) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">اسم المستخدم (SMTP User) <span class="text-danger">*</span></label>
            <input type="text" name="smtp_user" class="form-control <?= $s('smtp_user')?'is-valid':'' ?>"
                   placeholder="your@email.com"
                   value="<?= e($s('smtp_user')) ?>" autocomplete="new-password">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">
              كلمة المرور (SMTP Pass) <span class="text-danger">*</span>
              <span style="font-size:11px;color:#9ca3af">— اتركها فارغة للإبقاء على الحالية</span>
            </label>
            <div class="input-group">
              <input type="password" name="smtp_pass" id="smtpPassInput"
                     class="form-control <?= $s('smtp_pass')?'is-valid':'' ?>"
                     placeholder="••••••••" autocomplete="new-password">
              <button type="button" class="btn btn-outline-secondary"
                      onclick="var i=document.getElementById('smtpPassInput');i.type=i.type==='password'?'text':'password';this.querySelector('i').className=i.type==='password'?'fas fa-eye':'fas fa-eye-slash'">
                <i class="fas fa-eye"></i>
              </button>
            </div>
            <?php if($s('smtp_pass')): ?>
            <div class="form-text text-success"><i class="fas fa-check-circle me-1"></i>كلمة المرور محفوظة</div>
            <?php else: ?>
            <div class="form-text text-danger"><i class="fas fa-times-circle me-1"></i>لم تُحفظ بعد</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- اختبار الإيميل -->
        <div class="mt-4 p-3 rounded-3" style="background:#f0f9ff;border:1px solid #bae6fd">
          <div class="fw-bold mb-2" style="color:#0369a1"><i class="fas fa-paper-plane me-2"></i>اختبار إعدادات البريد</div>
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <input type="email" id="testEmailTo" class="form-control" style="max-width:300px"
                   placeholder="أدخل البريد لاختباره" value="<?= e($s('contact_email')) ?>">
            <button type="button" class="btn btn-info text-white" onclick="testEmail()">
              <i class="fas fa-flask me-1"></i>إرسال إيميل اختباري
            </button>
            <span id="testEmailResult" style="font-size:13px"></span>
          </div>
          <div class="form-text mt-1"><i class="fas fa-info-circle me-1"></i>احفظ الإعدادات أولاً ثم اختبر</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ تخزين الملفات — Google Drive (OAuth) ══ -->
  <?php
  if (is_file('../includes/gdrive_helper.php')) { require_once '../includes/gdrive_helper.php'; }
  $gd_cfg   = function_exists('gd_oauth_config') ? gd_oauth_config() : ['client_id'=>'','client_secret'=>''];
  $gd_redir = function_exists('gd_oauth_redirect_uri') ? gd_oauth_redirect_uri() : '';
  $gd_ready = $gd_cfg['client_id'] !== '' && $gd_cfg['client_secret'] !== '';
  ?>
  <div class="col-12">
    <div class="card">
      <div class="card-header fw-bold"><i class="fab fa-google-drive me-2 text-success"></i>تخزين الملفات — Google Drive (لكل مكتب حسابه)</div>
      <div class="card-body">
        <p class="text-muted" style="font-size:13px">
          سجّل <b>OAuth Client</b> واحداً في Google Cloud. بعدها يقدر كل مكتب يربط حساب Google الخاص فيه من إعداداته،
          وملفاته تُحفظ في درايفه هو — أنت لا تخزّن ولا ملف.
        </p>

        <div class="alert alert-warning py-2" style="font-size:13px">
          <b>Authorized redirect URI</b> — انسخه <u>حرفياً</u> والصقه في Google Cloud
          (OAuth client ← Authorized redirect URIs). لازم يتطابق 100% (بدون / في الآخر):
          <div class="input-group input-group-sm mt-1" style="max-width:560px">
            <input type="text" name="gdrive_redirect_uri" id="gd_redir_inp" class="form-control font-monospace"
                   value="<?= e($gd_redir ?: 'https://mehkam.net/office/gdrive_callback.php') ?>">
            <button class="btn btn-outline-secondary" type="button"
                    onclick="var i=document.getElementById('gd_redir_inp');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value)">نسخ</button>
          </div>
          <div class="form-text" style="font-size:11px">
            لو Google يعطي <code>redirect_uri_mismatch</code>: تأكد أن هذي القيمة بالضبط موجودة في قائمة
            redirect URIs عند Google، ثم انتظر 5 دقائق.
          </div>
        </div>

        <?php if ($gd_ready): ?>
        <div class="alert alert-success py-2" style="font-size:13px"><i class="fas fa-check-circle me-1"></i>مضبوط ويعمل.</div>
        <?php endif; ?>

        <div class="row g-3">
          <div class="col-md-7">
            <label class="form-label fw-semibold">OAuth Client ID</label>
            <input type="text" name="gdrive_oauth_client_id" class="form-control font-monospace"
                   value="<?= e($gd_cfg['client_id']) ?>" placeholder="xxxxx.apps.googleusercontent.com">
          </div>
          <div class="col-md-5">
            <label class="form-label fw-semibold">OAuth Client Secret</label>
            <input type="password" name="gdrive_oauth_client_secret" class="form-control font-monospace"
                   placeholder="<?= $gd_ready ? '•••••• (محفوظ — اتركه فارغاً للإبقاء)' : 'GOCSPX-...' ?>" autocomplete="new-password">
          </div>
        </div>
        <div class="form-text mt-1">
          <i class="fas fa-circle-info me-1"></i>
          Google Cloud → <b>APIs &amp; Services → Credentials → Create Credentials → OAuth client ID</b> →
          نوع <b>Web application</b> → أضِف الـ redirect URI أعلاه. وفعّل <b>Google Drive API</b>.
        </div>
        <div class="mt-3">
          <button type="button" class="btn btn-outline-success btn-sm" onclick="gdCheck()">
            <i class="fas fa-plug me-1"></i>فحص الاتصال بـ Google
          </button>
          <span id="gd_check_result" class="ms-2" style="font-size:13px"></span>
        </div>
        <div class="form-text mt-1" style="font-size:11px">
          الفحص يتحقق أن Client ID/Secret <b>صحيحة</b> عند Google. أما التأكد من الـ redirect URI
          فيظهر عند أول «ربط حساب» من مكتب.
        </div>
      </div>
    </div>
  </div>

  <script>
  function gdCheck() {
    var box = document.getElementById('gd_check_result');
    box.innerHTML = '<span class="text-muted">جاري الفحص…</span>';
    fetch('settings.php?gdrive_check=1')
      .then(r => r.json())
      .then(d => {
        box.innerHTML = (d.ok
          ? '<span class="text-success"><i class="fas fa-check-circle me-1"></i>'
          : '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>') + (d.msg || '') + '</span>';
      })
      .catch(() => box.innerHTML = '<span class="text-danger">تعذّر الاتصال بالخادم</span>');
  }
  </script>

  <!-- ══ تيليغرام ══ -->
  <?php
  $tgTok = $s('telegram_bot_token'); $tgSet = $tgTok !== '';
  ?>
  <div class="col-12">
    <div class="card">
      <div class="card-header fw-bold"><i class="fab fa-telegram me-2" style="color:#229ED9"></i>تنبيهات تيليغرام</div>
      <div class="card-body">
        <p class="text-muted" style="font-size:13px">
          أنشئ بوتاً عبر <a href="https://t.me/BotFather" target="_blank">@BotFather</a> في تيليغرام (أمر <code>/newbot</code>)
          واحصل على التوكن ومعرّف البوت. بعدها يربط كل محامي حسابه من ملفه الشخصي.
        </p>
        <?php if ($tgSet): ?>
        <div class="alert alert-success py-2" style="font-size:13px"><i class="fas fa-check-circle me-1"></i>التوكن محفوظ.</div>
        <?php endif; ?>
        <div class="row g-3">
          <div class="col-md-7">
            <label class="form-label fw-semibold">Bot Token</label>
            <input type="password" name="telegram_bot_token" class="form-control font-monospace"
                   placeholder="<?= $tgSet ? '•••••• (محفوظ — اتركه فارغاً للإبقاء)' : '123456:ABC-DEF...' ?>" autocomplete="new-password">
          </div>
          <div class="col-md-5">
            <label class="form-label fw-semibold">معرّف البوت (username)</label>
            <input type="text" name="telegram_bot_username" class="form-control font-monospace"
                   placeholder="MehkamAlerts_bot" value="<?= e($s('telegram_bot_username')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">ساعات التذكير قبل الجلسة</label>
            <input type="text" name="telegram_lead_hours" class="form-control" placeholder="24,3"
                   value="<?= e($s('telegram_lead_hours','24,3')) ?>">
            <div class="form-text">مفصولة بفاصلة</div>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">ساعة الملخّص اليومي</label>
            <input type="number" min="0" max="23" name="telegram_digest_hour" class="form-control"
                   value="<?= e($s('telegram_digest_hour','7')) ?>">
          </div>
        </div>
        <div class="d-flex gap-2 flex-wrap mt-3">
          <button type="button" class="btn btn-outline-primary btn-sm" onclick="tgAct('setwebhook')"><i class="fas fa-link me-1"></i>تفعيل الويبهوك</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="tgAct('info')">فحص الويبهوك</button>
          <button type="button" class="btn btn-outline-success btn-sm" onclick="tgAct('test')">إرسال رسالة اختبار لي</button>
        </div>
        <div id="tgResult" class="mt-2" style="font-size:12.5px;word-break:break-all"></div>
        <div class="alert alert-light border mt-3" style="font-size:12px">
          <i class="fas fa-clock me-1 text-primary"></i>
          <b>مهم:</b> أضف مهمة مجدولة (Cron Job) في cPanel كل 20 دقيقة:
          <div class="input-group input-group-sm mt-1" style="max-width:640px">
            <input type="text" class="form-control font-monospace" readonly onclick="this.select()"
              value="0,20,40 * * * * php <?= e(dirname(__DIR__)) ?>/cron/notify.php">
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ ZATCA ══ -->
  <div class="col-12">
    <div class="card">
      <div class="card-header fw-bold" style="background:linear-gradient(135deg,#78350f,#b45309);color:#fff;border:none">
        <i class="fas fa-qrcode me-2"></i>إعدادات ZATCA — هيئة الزكاة والضريبة والجمارك
        <span class="badge bg-warning text-dark ms-2" style="font-size:11px">مطلوب للفوترة الإلكترونية</span>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">الرقم الضريبي (VAT)</label>
            <input type="text" name="platform_vat" class="form-control font-monospace" placeholder="3XXXXXXXXXXXXXXXXXXX3" value="<?= e($s('platform_vat')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">رقم السجل التجاري (CR)</label>
            <input type="text" name="platform_cr" class="form-control font-monospace" placeholder="1XXXXXXXXX" value="<?= e($s('platform_cr')) ?>">
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ التكامل الحكومي ══ -->
  <?php
  require_once '../includes/gov_integrations.php';
  $gov = gov_status_all($conn);
  $govField = function ($k, $label, $ph = '', $type = 'text') use ($s) {
      $val = $type === 'password' ? '' : e($s($k));
      $phF = $type === 'password' && $s($k) !== '' ? '•••••• (محفوظ — اتركه فارغاً للإبقاء)' : $ph;
      echo '<div class="col-md-6"><label class="form-label fw-semibold" style="font-size:12.5px">' . $label . '</label>'
         . '<input type="' . $type . '" name="' . $k . '" class="form-control form-control-sm font-monospace" '
         . 'placeholder="' . e($phF) . '" value="' . $val . '" autocomplete="new-password"></div>';
  };
  ?>
  <div class="col-12">
    <div class="card">
      <div class="card-header fw-bold" style="background:linear-gradient(135deg,#0c1b36,#1e3a60);color:#fff;border:none">
        <i class="fas fa-building-columns me-2"></i>التكامل الحكومي — نفاذ · وثيق · بوابة فاتورة · اعتماد
      </div>
      <div class="card-body">
        <div class="alert alert-warning py-2" style="font-size:12.5px">
          <i class="fas fa-triangle-exclamation me-1"></i>
          كل خدمة تتطلب <b>اتفاقية ربط رسمية ومفاتيح API من الجهة نفسها</b>. فعّل الخدمة والصق مفاتيحها هنا.
          بدون مفاتيح صالحة تبقى الأزرار في المنصة ظاهرة لكن تُعيد رسالة «غير مُفعّل».
        </div>

        <div class="row g-2 mb-3">
          <?php foreach ($gov as $k => $g): ?>
          <div class="col-6 col-md-3">
            <div class="p-2 rounded text-center" style="background:#f8fafc;border:1px solid #e2e8f0">
              <div style="font-size:12px;font-weight:700"><?= e($g['label']) ?></div>
              <span class="badge bg-<?= $g['ready'] ? 'success' : ($g['enabled'] ? 'warning text-dark' : 'secondary') ?> mt-1" style="font-size:10px">
                <?= $g['ready'] ? 'جاهز' : ($g['enabled'] ? 'مفعّل — نقص مفاتيح' : 'متوقف') ?>
              </span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- نفاذ -->
        <div class="border rounded p-3 mb-3">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="nafath_enabled" id="nafath_enabled" <?= $ch('nafath_enabled') ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="nafath_enabled"><i class="fas fa-fingerprint me-1 text-primary"></i>نفاذ الوطني الموحّد — التحقق من هوية الأفراد</label>
          </div>
          <div class="row g-2">
            <?php $govField('nafath_base_url', 'Base URL', 'https://naf-api.nic.gov.sa'); ?>
            <?php $govField('nafath_app_id', 'APP-ID', ''); ?>
            <?php $govField('nafath_app_key', 'APP-KEY (سري)', '', 'password'); ?>
          </div>
        </div>

        <!-- وثيق -->
        <div class="border rounded p-3 mb-3">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="wathiq_enabled" id="wathiq_enabled" <?= $ch('wathiq_enabled') ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="wathiq_enabled"><i class="fas fa-store me-1 text-info"></i>وثيق — بيانات السجل التجاري والرقم الموحّد</label>
          </div>
          <div class="row g-2">
            <?php $govField('wathiq_base_url', 'Base URL', 'https://api.wathq.sa'); ?>
            <?php $govField('wathiq_api_key', 'apiKey (سري)', '', 'password'); ?>
          </div>
          <div class="d-flex gap-2 align-items-center mt-2 flex-wrap">
            <input type="text" id="wathiq_test_num" class="form-control form-control-sm font-monospace" style="max-width:200px" placeholder="رقم سجل للاختبار">
            <button type="button" class="btn btn-outline-info btn-sm" onclick="govTest('wathiq', document.getElementById('wathiq_test_num').value)">اختبار الجلب</button>
            <span id="gov_wathiq_res" style="font-size:12.5px"></span>
          </div>
        </div>

        <!-- بوابة فاتورة -->
        <div class="border rounded p-3 mb-3">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="zatca_enabled" id="zatca_enabled" <?= $ch('zatca_enabled') ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="zatca_enabled"><i class="fas fa-file-invoice me-1 text-warning"></i>هيئة الزكاة — بوابة فاتورة (إبلاغ/تصفية المرحلة الثانية)</label>
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label fw-semibold" style="font-size:12.5px">البيئة</label>
              <select name="zatca_env" class="form-select form-select-sm">
                <?php foreach (['sandbox'=>'تجريبية Sandbox','simulation'=>'محاكاة Simulation','production'=>'إنتاج Production'] as $ev=>$el): ?>
                <option value="<?= $ev ?>" <?= $s('zatca_env','sandbox')===$ev?'selected':'' ?>><?= $el ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php $govField('zatca_base_url', 'Base URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core'); ?>
            <?php $govField('zatca_binary_token', 'Binary Security Token (سري)', '', 'password'); ?>
            <?php $govField('zatca_secret', 'Secret (سري)', '', 'password'); ?>
          </div>
          <div class="form-text" style="font-size:11px">
            <i class="fas fa-circle-info me-1"></i>التوليد الفعلي لفاتورة UBL 2.1 وتوقيعها بشهادة CSID يتم لاحقاً — الآن يُحفظ الإعداد فقط.
          </div>
        </div>

        <!-- اعتماد -->
        <div class="border rounded p-3">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="etimad_enabled" id="etimad_enabled" <?= $ch('etimad_enabled') ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="etimad_enabled"><i class="fas fa-landmark me-1 text-success"></i>اعتماد — وزارة المالية (المنافسات والمشتريات)</label>
          </div>
          <div class="row g-2">
            <?php $govField('etimad_base_url', 'Base URL', 'https://api.etimad.sa'); ?>
            <?php $govField('etimad_api_key', 'API Key (سري)', '', 'password'); ?>
          </div>
        </div>

        <!-- الذكاء الاصطناعي (موديول إنشاء القضية من صحيفة الدعوى) -->
        <div class="border rounded p-3 mt-3">
          <div class="fw-bold mb-2"><i class="fas fa-robot me-1 text-primary"></i>الذكاء الاصطناعي — استخراج بيانات القضايا</div>
          <div class="row g-2">
            <?php $govField('ai_api_key', 'مفتاح Anthropic API (سري)', '', 'password'); ?>
            <?php $govField('ai_model', 'النموذج', 'claude-sonnet-5'); ?>
          </div>
          <div class="form-text" style="font-size:11px"><i class="fas fa-circle-info me-1"></i>اتركه فارغاً لتعمل الميزة بالمحلل المحلي فقط. يُحدّ استخدام كل مكتب بـ ٢٠ استدعاء يومياً لحماية التكلفة.</div>
        </div>

      </div>
    </div>
  </div>

</div>

<div class="mt-4 d-flex gap-2">
  <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save me-2"></i>حفظ جميع الإعدادات</button>
</div>
</form>

<script>
function gwToggleSMTP(el) {
  var isOn = el.dataset.on === '1';
  isOn = !isOn;
  el.dataset.on = isOn ? '1' : '0';
  var knob = el.querySelector('div');
  el.style.background = isOn ? '#16a34a' : '#d1d5db';
  knob.style.right = isOn ? '3px' : '';
  knob.style.left  = isOn ? '' : '3px';
  document.getElementById('smtpEnabledLbl').textContent = isOn ? 'مفعّل' : 'معطّل';
  document.getElementById('smtp_enabled_val').value = isOn ? '1' : '0';
}


function testEmail() {
  var to  = document.getElementById('testEmailTo').value.trim();
  var res = document.getElementById('testEmailResult');
  if (!to) { res.innerHTML = '<span class="text-danger">أدخل بريداً إلكترونياً</span>'; return; }
  res.innerHTML = '<span class="text-secondary"><i class="fas fa-spinner fa-spin me-1"></i>جاري الإرسال...</span>';
  var fd = new FormData();
  fd.append('test_email','1');
  fd.append('test_to', to);
  fetch('settings.php', {method:'POST', body:fd})
    .then(r=>r.json())
    .then(d=>{
      if (d.ok) {
        res.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>تم الإرسال بنجاح! تحقق من صندوق الوارد</span>';
      } else {
        res.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>' + (d.error||'فشل الإرسال') + '</span>';
      }
    })
    .catch(()=>{ res.innerHTML = '<span class="text-danger">خطأ في الاتصال</span>'; });
}

function govTest(svc, num){
  var box = document.getElementById('gov_' + svc + '_res');
  if (box) box.innerHTML = '<span class="text-muted">جارٍ…</span>';
  fetch('settings.php?gov_test=' + svc + '&num=' + encodeURIComponent(num || ''))
    .then(r=>r.json())
    .then(d=>{
      if (box) box.innerHTML = (d.ok ? '<span class="text-success"><i class="fas fa-check-circle me-1"></i>' : '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>')
        + (d.msg||'') + '</span>';
    })
    .catch(()=>{ if (box) box.innerHTML = '<span class="text-danger">خطأ في الاتصال</span>'; });
}

function tgAct(act){
  var box = document.getElementById('tgResult');
  box.innerHTML = '<span class="text-muted">جارٍ…</span>';
  fetch('settings.php?tg_action=' + act)
    .then(r=>r.json())
    .then(d=>{
      box.innerHTML = (d.ok ? '<span class="text-success"><i class="fas fa-check-circle me-1"></i>' : '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>')
        + (d.msg||'') + '</span>';
    })
    .catch(()=>{ box.innerHTML = '<span class="text-danger">خطأ في الاتصال</span>'; });
}
</script>

<?php include '../includes/admin_footer.php'; ?>
