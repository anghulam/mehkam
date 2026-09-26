<?php
// منع الكاش
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../config/db.php';
require_once '../includes/content_helper.php';

/* ══════════════════════════════════════════════════════
   معالج AJAX — يُنفَّذ أولاً قبل أي HTML
   ====================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_send'])) {

    header('Content-Type: application/json; charset=utf-8');

    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $phone   = trim($_POST['phone']   ?? '');
    $subject = trim($_POST['subject'] ?? 'استفسار عام');
    $message = trim($_POST['message'] ?? '');

    if (!$name || !$email || !$message) {
        echo json_encode(['ok' => false, 'msg' => 'يرجى ملء جميع الحقول المطلوبة']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'msg' => 'البريد الإلكتروني غير صحيح']);
        exit;
    }

    // إنشاء الجدول إن لم يكن موجوداً
    $conn->query("CREATE TABLE IF NOT EXISTS contact_messages (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(150) NOT NULL,
        email       VARCHAR(200) NOT NULL,
        phone       VARCHAR(50)  DEFAULT '',
        subject     VARCHAR(200) DEFAULT '',
        message     TEXT         NOT NULL,
        is_read     TINYINT(1)   DEFAULT 0,
        status      ENUM('new','read','replied','archived') DEFAULT 'new',
        admin_notes TEXT,
        ip_address  VARCHAR(45),
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $n  = $conn->real_escape_string($name);
    $em = $conn->real_escape_string($email);
    $ph = $conn->real_escape_string($phone);
    $sb = $conn->real_escape_string($subject);
    $m  = $conn->real_escape_string($message);
    $ip = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');

    $ok = $conn->query(
        "INSERT INTO contact_messages (name,email,phone,subject,message,ip_address)
         VALUES ('$n','$em','$ph','$sb','$m','$ip')"
    );

    echo json_encode(
        $ok
        ? ['ok' => true,  'msg' => 'تم إرسال رسالتك بنجاح! سنرد عليك خلال 24 ساعة.']
        : ['ok' => false, 'msg' => 'فشل الحفظ: ' . $conn->error]
    );
    exit;
}

/* ══════════════════════════════════════════════════════
   صفحة HTML العادية
   ====================================================== */
$_sn_c     = sc($conn, 'site_name', 'مِحكام');
$page_title = 'تواصل معنا — ' . $_sn_c;
include 'includes/header.php';
?>
<section class="lx-phero">
  <div class="container lx-phero-in">
    <div class="lx-eye">تواصل معنا</div>
    <h1 class="lx-h">نحن هنا <span class="gt">لمساعدتك</span></h1>
    <p class="lx-p">فريقنا جاهز للإجابة على أسئلتك وتقديم العرض المناسب</p>
  </div>
</section>

<section class="lx-sec">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-7 reveal">
        <div class="lx-cform">
          <h4 class="fw-bold mb-4" style="color:var(--white)">
            <i class="fas fa-paper-plane me-2" style="color:var(--gold3)"></i>أرسل لنا رسالة
          </h4>
          <div id="cx-msg" style="display:none;border-radius:10px;padding:13px 16px;margin-bottom:14px;align-items:center;gap:10px;font-size:14px"></div>
          <form id="cx-form">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="lx-flabel"><span class="lx-freq">*</span>الاسم الكامل</label>
                <input type="text" name="name" class="lx-field" required placeholder="اسمك الكامل">
              </div>
              <div class="col-md-6">
                <label class="lx-flabel"><span class="lx-freq">*</span>البريد الإلكتروني</label>
                <input type="email" name="email" class="lx-field" required placeholder="email@example.com">
              </div>
              <div class="col-md-6">
                <label class="lx-flabel">رقم الجوال</label>
                <input type="tel" name="phone" class="lx-field" placeholder="05XXXXXXXX">
              </div>
              <div class="col-md-6">
                <label class="lx-flabel">موضوع الرسالة</label>
                <select name="subject" class="lx-field">
                  <option>استفسار عام</option>
                  <option>طلب عرض سعر</option>
                  <option>دعم تقني</option>
                  <option>شكوى</option>
                  <option>اقتراح</option>
                </select>
              </div>
              <div class="col-12">
                <label class="lx-flabel"><span class="lx-freq">*</span>رسالتك</label>
                <textarea name="message" class="lx-field" rows="5" required placeholder="اكتب رسالتك هنا..."></textarea>
              </div>
              <div class="col-12">
                <button type="submit" class="lx-submit" id="cx-btn">
                  <i class="fas fa-paper-plane"></i> إرسال الرسالة
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>

      <div class="col-lg-5 reveal">
        <div class="lx-cinfo">
          <h4 class="fw-bold mb-4 position-relative" style="z-index:1;color:var(--white)">معلومات التواصل</h4>
          <?php
          $fe = sc($conn,'contact_email','info@mehkam.net');
          $fp = sc($conn,'contact_phone','0553302173');
          $fa = sc($conn,'contact_address','الرياض، المملكة العربية السعودية');
          $tw = sc($conn,'twitter','#');
          $li = sc($conn,'linkedin','#');
          $wa = sc($conn,'whatsapp','#');
          foreach([
            ['map-marker-alt','العنوان',   e($fa)],
            ['phone',         'الهاتف',    '<a href="tel:'.preg_replace('/\D/','',$fp).'" style="color:var(--gold4)">'.e($fp).'</a>'],
            ['envelope',      'البريد',    '<a href="mailto:'.e($fe).'" style="color:var(--gold4)">'.e($fe).'</a>'],
            ['clock',         'ساعات العمل','الأحد – الخميس: 9 ص – 6 م'],
            ['headset',       'الدعم الفني','متاح 24/7 عبر البريد الإلكتروني'],
          ] as [$ic,$lb,$vl]): ?>
          <div class="lx-ci-item">
            <div class="lx-ci-icon"><i class="fas fa-<?= $ic ?>"></i></div>
            <div><div class="lx-ci-lbl"><?= $lb ?></div><div class="lx-ci-val"><?= $vl ?></div></div>
          </div>
          <?php endforeach; ?>
          <hr style="border-color:var(--b1);margin:22px 0;position:relative;z-index:1">
          <h6 class="fw-bold mb-3 position-relative" style="z-index:1;color:var(--white)">تابعنا على</h6>
          <div class="lx-footer-social position-relative" style="z-index:1">
            <a href="<?= e($tw) ?>"><i class="fab fa-twitter"></i></a>
            <a href="<?= e($li) ?>"><i class="fab fa-linkedin-in"></i></a>
            <a href="<?= e($wa) ?>"><i class="fab fa-whatsapp"></i></a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
document.getElementById('cx-form').addEventListener('submit', function(e) {
  e.preventDefault();

  var btn = document.getElementById('cx-btn');
  var msg = document.getElementById('cx-msg');
  var fd  = new FormData(this);
  fd.append('contact_send', '1'); // مفتاح التعرف على طلب AJAX

  msg.style.display = 'none';
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإرسال...';

  // الإرسال لنفس الصفحة (contact.php) مع contact_send=1
  fetch(window.location.href.split('?')[0], { method: 'POST', body: fd })
    .then(function(r) { return r.text(); })
    .then(function(txt) {
      var d;
      try { d = JSON.parse(txt); }
      catch(ex) {
        showMsg(false, 'خطأ في الخادم، يرجى المحاولة لاحقاً');
        return;
      }
      if (d.ok) {
        showMsg(true, d.msg);
        document.getElementById('cx-form').reset();
      } else {
        showMsg(false, d.msg);
      }
    })
    .catch(function() { showMsg(false, 'تعذّر الاتصال بالخادم'); })
    .finally(function() {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-paper-plane"></i> إرسال الرسالة';
    });

  function showMsg(ok, text) {
    msg.style.cssText = 'display:flex;align-items:center;gap:10px;border-radius:10px;padding:13px 16px;margin-bottom:14px;font-size:14px;'
      + (ok ? 'background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.4);color:#4ade80'
            : 'background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#fca5a5');
    msg.innerHTML = '<i class="fas fa-' + (ok ? 'check-circle' : 'exclamation-circle') + '"></i><span>' + text + '</span>';
  }
});
</script>

<?php include 'includes/footer.php'; ?>
