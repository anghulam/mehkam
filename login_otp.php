<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/content_helper.php';

// تحقق من وجود جلسة انتظار OTP
if (empty($_SESSION['otp_pending'])) {
    header("Location: login.php"); exit;
}

$pending  = $_SESSION['otp_pending'];
$error    = '';
$resent   = false;

// إعادة إرسال OTP
if (isset($_GET['resend'])) {
    require_once 'includes/mailer.php';
    $new_otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['otp_pending']['otp']     = $new_otp;
    $_SESSION['otp_pending']['expires'] = time() + 600;
    sendOtpEmail($conn, $pending['email'], $pending['full_name'], $new_otp);
    $resent = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered = preg_replace('/\D/', '', trim($_POST['otp_code'] ?? ''));
    $pending = $_SESSION['otp_pending'];

    if (time() > ($pending['expires'] ?? 0)) {
        $error = 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.';
    } elseif ($entered !== $pending['otp']) {
        $error = 'الرمز غير صحيح. يرجى المحاولة مجدداً.';
    } else {
        // رمز صحيح — إكمال الدخول
        unset($_SESSION['otp_pending']);
        $_SESSION['user_id']     = $pending['user_id'];
        $_SESSION['username']    = $pending['username'];
        $_SESSION['full_name']   = $pending['full_name'];
        $_SESSION['role']        = $pending['role'];
        $_SESSION['office_id']   = $pending['office_id'];
        $_SESSION['office_name'] = $pending['office_name'] ?? '';
        // صلاحيات + نطاق + تقويم
        $_uid = (int)$pending['user_id'];
        $_uu = $conn->query("SELECT permissions, restricted_scope, calendar_pref, role, office_id FROM users WHERE id=$_uid LIMIT 1");
        if ($_uu && $_ur = $_uu->fetch_assoc()) {
            $_SESSION['restricted_scope'] = !empty($_ur['restricted_scope']) ? 1 : 0;
            $_pp = !empty($_ur['permissions']) ? json_decode($_ur['permissions'], true) : null;
            if (!is_array($_pp) && ($_ur['role'] ?? '') !== 'office_owner' && !empty($_ur['office_id'])) {
                $_pp = roleTemplate($conn, (int)$_ur['office_id'], $_ur['role']);
                $conn->query("UPDATE users SET permissions='" . $conn->real_escape_string(json_encode($_pp, JSON_UNESCAPED_UNICODE)) . "' WHERE id=$_uid");
            }
            $_SESSION['perms'] = is_array($_pp) ? $_pp : [];
            $_SESSION['calendar_pref'] = in_array($_ur['calendar_pref'] ?? '', ['gregorian','hijri','both'], true) ? $_ur['calendar_pref'] : 'gregorian';
        }

        header("Location: " . ($pending['role'] === 'admin' ? 'admin/dashboard.php' : 'office/dashboard.php'));
        exit;
    }
}

$email_masked = '';
if (!empty($pending['email'])) {
    $parts = explode('@', $pending['email']);
    $email_masked = substr($parts[0], 0, 2) . str_repeat('*', max(2, strlen($parts[0]) - 2)) . '@' . $parts[1];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>التحقق الثنائي | <?= e(sc($conn,'site_name','مِحكام')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;600;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<style>
:root{--navy:#0a1628;--navy3:#1a3a6e;--gold:#c9a227}
*{font-family:'Tajawal',sans-serif;box-sizing:border-box}
body{min-height:100vh;background:linear-gradient(135deg,var(--navy) 0%,var(--navy3) 100%);display:flex;align-items:center;justify-content:center;padding:20px}
.otp-card{background:#fff;border-radius:20px;padding:48px 40px;max-width:440px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.3);text-align:center}
.otp-icon{width:72px;height:72px;border-radius:18px;background:linear-gradient(135deg,var(--navy),var(--navy3));display:flex;align-items:center;justify-content:center;margin:0 auto 24px;font-size:28px;color:var(--gold)}
.otp-title{font-size:22px;font-weight:900;color:var(--navy);margin-bottom:8px}
.otp-sub{font-size:13px;color:#6b7280;margin-bottom:28px;line-height:1.7}
.otp-inputs{display:flex;justify-content:center;gap:10px;margin-bottom:24px}
.otp-digit{width:52px;height:60px;border:2px solid #e5e7eb;border-radius:12px;font-size:26px;font-weight:900;text-align:center;color:var(--navy);transition:border-color .2s,box-shadow .2s;-webkit-appearance:none}
.otp-digit:focus{outline:none;border-color:var(--navy3);box-shadow:0 0 0 3px rgba(26,58,110,.12)}
.otp-digit.filled{border-color:var(--navy3);background:#f0f4ff}
.btn-verify{width:100%;padding:14px;border:none;border-radius:12px;background:linear-gradient(135deg,var(--navy),var(--navy3));color:#fff;font-size:15px;font-weight:800;cursor:pointer;transition:opacity .2s}
.btn-verify:hover{opacity:.9}
.btn-verify:disabled{opacity:.6;cursor:not-allowed}
.err-box{background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px;color:#dc2626;font-size:13px;margin-bottom:16px}
.success-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px;color:#16a34a;font-size:13px;margin-bottom:16px}
.resend-row{font-size:13px;color:#6b7280;margin-top:20px}
.resend-row a{color:var(--navy3);font-weight:700;text-decoration:none}
.timer{font-weight:700;color:var(--navy)}
input[type=number]::-webkit-outer-spin-button,input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none}
</style>
</head>
<body>
<div class="otp-card">
  <div class="otp-icon"><i class="fas fa-mobile-screen-button"></i></div>
  <div class="otp-title">التحقق الثنائي</div>
  <div class="otp-sub">
    تم إرسال رمز مكون من 6 أرقام إلى<br>
    <strong><?= e($email_masked) ?></strong>
  </div>

  <?php if ($error): ?>
  <div class="err-box"><i class="fas fa-exclamation-circle me-2"></i><?= e($error) ?></div>
  <?php endif; ?>

  <?php if ($resent): ?>
  <div class="success-box"><i class="fas fa-check-circle me-2"></i>تم إعادة إرسال الرمز بنجاح</div>
  <?php endif; ?>

  <form method="POST" id="otpForm">
    <div class="otp-inputs" id="otpBoxes" dir="ltr" style="direction:ltr">
      <?php for ($i=0;$i<6;$i++): ?>
      <input type="number" class="otp-digit" maxlength="1" min="0" max="9" data-idx="<?= $i ?>" tabindex="<?= $i+1 ?>" <?= $i===0?'autofocus':'' ?>>
      <?php endfor; ?>
    </div>
    <input type="hidden" name="otp_code" id="otpHidden">
    <button type="submit" class="btn-verify" id="verifyBtn" disabled>
      <i class="fas fa-check-circle me-2"></i>تحقق من الرمز
    </button>
  </form>

  <div class="resend-row">
    لم يصلك الرمز؟
    <a href="login_otp.php?resend=1" id="resendLink">إعادة الإرسال</a>
    <span id="timerWrap">بعد <span class="timer" id="timerSec">60</span> ثانية</span>
  </div>

  <div style="margin-top:16px;font-size:12px;color:#9ca3af">
    <a href="login.php" style="color:#9ca3af">← العودة لصفحة الدخول</a>
  </div>
</div>

<script>
const digits = document.querySelectorAll('.otp-digit');
const hidden = document.getElementById('otpHidden');
const verifyBtn = document.getElementById('verifyBtn');

digits.forEach((d, i) => {
  d.addEventListener('input', e => {
    const v = e.target.value.replace(/\D/g,'').slice(-1);
    e.target.value = v;
    e.target.classList.toggle('filled', v !== '');
    if (v && i < 5) digits[i+1].focus();
    updateHidden();
  });
  d.addEventListener('keydown', e => {
    if (e.key === 'Backspace' && !d.value && i > 0) {
      digits[i-1].value = '';
      digits[i-1].classList.remove('filled');
      digits[i-1].focus();
      updateHidden();
    }
    if (e.key === 'ArrowLeft' && i > 0) digits[i-1].focus();
    if (e.key === 'ArrowRight' && i < 5) digits[i+1].focus();
  });
  d.addEventListener('paste', e => {
    e.preventDefault();
    const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
    paste.split('').slice(0,6).forEach((c, j) => {
      if (digits[j]) { digits[j].value = c; digits[j].classList.add('filled'); }
    });
    updateHidden();
    digits[Math.min(paste.length, 5)].focus();
  });
});

function updateHidden() {
  const code = Array.from(digits).map(d => d.value).join('');
  hidden.value = code;
  verifyBtn.disabled = code.length < 6;
}

// Timer for resend
(function() {
  var t = 60;
  var resendLink = document.getElementById('resendLink');
  var timerWrap  = document.getElementById('timerWrap');
  var timerSec   = document.getElementById('timerSec');
  resendLink.style.pointerEvents = 'none';
  resendLink.style.opacity = '.4';
  var iv = setInterval(function() {
    t--;
    timerSec.textContent = t;
    if (t <= 0) {
      clearInterval(iv);
      timerWrap.style.display = 'none';
      resendLink.style.pointerEvents = '';
      resendLink.style.opacity = '';
    }
  }, 1000);
})();

// Auto-submit when all 6 entered
function updateHidden() {
  const code = Array.from(digits).map(d => d.value).join('');
  hidden.value = code;
  verifyBtn.disabled = code.length < 6;
  if (code.length === 6) setTimeout(() => document.getElementById('otpForm').submit(), 300);
}
</script>
</body>
</html>
