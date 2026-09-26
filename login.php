<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';       // يجب قبل content_helper (كلاهما يعرّف e())
require_once 'includes/content_helper.php';

if (isset($_SESSION['user_id'])) {
    header("Location: " . ($_SESSION['role']==='admin' ? 'admin/dashboard.php' : 'office/dashboard.php'));
    exit;
}

$error = '';
// رسالة انتهاء التجربة
if (!empty($_GET['trial_expired'])) {
    $error = 'انتهت فترة التجربة المجانية. يرجى التواصل مع الإدارة لتفعيل اشتراكك.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // جلب المستخدم بغض النظر عن is_active لنعطي رسالة أوضح
    $stmt = $conn->prepare("SELECT * FROM users WHERE username=? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    // دالة إتمام الدخول مع دعم 2FA
    $completeLogin = function(array $u, ?array $office = null) use ($conn) {
        $two_fa = sc($conn,'two_factor_enabled','0') === '1';
        if ($two_fa && !empty($u['email'])) {
            require_once 'includes/mailer.php';
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['otp_pending'] = [
                'user_id'     => $u['id'],
                'username'    => $u['username'],
                'full_name'   => $u['full_name'],
                'role'        => $u['role'],
                'office_id'   => $u['office_id'] ?? null,
                'office_name' => $office['name'] ?? '',
                'email'       => $u['email'],
                'otp'         => $otp,
                'expires'     => time() + 600,
            ];
            sendOtpEmail($conn, $u['email'], $u['full_name'], $otp);
            header("Location: login_otp.php"); exit;
        }
        $_SESSION['user_id']     = $u['id'];
        $_SESSION['username']    = $u['username'];
        $_SESSION['full_name']   = $u['full_name'];
        $_SESSION['role']        = $u['role'];
        $_SESSION['office_id']   = $u['office_id'] ?? null;
        $_SESSION['office_name'] = $office['name'] ?? '';
        $_SESSION['calendar_pref'] = in_array($u['calendar_pref'] ?? '', ['gregorian','hijri','both'], true) ? $u['calendar_pref'] : 'gregorian';
        $_SESSION['restricted_scope'] = !empty($u['restricted_scope']) ? 1 : 0;
        $perms = !empty($u['permissions']) ? json_decode($u['permissions'], true) : null;
        if (!is_array($perms) && ($u['role'] ?? '') !== 'office_owner' && !empty($u['office_id'])) {
            // بذرة أولى من قالب الدور الذي حدّده المالك (أو الاقتراح المبدئي) — ثم تصبح قابلة للتعديل
            $perms = roleTemplate($conn, (int)$u['office_id'], $u['role']);
            $pj = $conn->real_escape_string(json_encode($perms, JSON_UNESCAPED_UNICODE));
            $conn->query("UPDATE users SET permissions='$pj' WHERE id=" . (int)$u['id']);
        }
        $_SESSION['perms'] = is_array($perms) ? $perms : [];
        header("Location: " . ($u['role']==='admin' ? 'admin/dashboard.php' : 'office/dashboard.php'));
        exit;
    };

    if (!$user || !password_verify($password, $user['password'])) {
        $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
    } elseif ($user['role'] === 'admin') {
        // أدمن: يجب أن يكون مفعلاً
        if (!$user['is_active']) {
            $error = 'الحساب غير مفعّل';
        } else {
            $completeLogin($user);
        }
    } else {
        // مستخدم مكتب: تحقق من حالة المكتب
        $office = null;
        if ($user['office_id']) {
            $r = $conn->query("SELECT * FROM offices WHERE id=".(int)$user['office_id']." LIMIT 1");
            $office = $r ? $r->fetch_assoc() : null;
        }

        if (!$office) {
            $error = 'لم يُعثر على بيانات المكتب، يرجى التواصل مع الإدارة';
        } elseif ($office['status'] === 'suspended') {
            $error = 'حساب مكتبك موقوف. يرجى التواصل مع الإدارة لتفعيل الاشتراك.';
        } elseif ($office['status'] === 'expired') {
            $error = 'انتهى اشتراك مكتبك. يرجى التواصل مع الإدارة لتجديد الاشتراك.';
        } elseif ($office['status'] === 'trial') {
            // تحقق من انتهاء التجربة
            if (!empty($office['subscription_end']) && strtotime($office['subscription_end']) < strtotime('today')) {
                // أوقف المكتب
                $conn->query("UPDATE offices SET status='suspended' WHERE id=".(int)$office['id']);
                $conn->query("UPDATE users SET is_active=0 WHERE office_id=".(int)$office['id']);
                $error = 'انتهت فترة التجربة المجانية. يرجى التواصل مع الإدارة لتفعيل اشتراكك.';
            } elseif (!$user['is_active']) {
                $error = 'حسابك غير مفعّل. يرجى التواصل مع الإدارة.';
            } else {
                $completeLogin($user, $office);
            }
        } elseif ($office['status'] === 'active') {
            if (!$user['is_active']) {
                $conn->query("UPDATE users SET is_active=1 WHERE id=".(int)$user['id']);
            }
            $completeLogin($user, $office);
        } else {
            $error = 'حالة الحساب غير معروفة. يرجى التواصل مع الإدارة.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تسجيل الدخول | <?= e(sc($conn,'site_name','OLFS')); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
<style>
:root{--navy:#0a1628;--navy2:#0f2040;--navy3:#1a3a6e;--gold:#c9a227;--gold2:#e8c040}
*{font-family:'Tajawal',sans-serif;box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden}

body{
  display:flex;
  background:var(--navy);
}

/* ─── LEFT PANEL ─── */
.l-panel{
  flex:1;
  position:relative;
  overflow:hidden;
  display:flex;
  flex-direction:column;
  justify-content:center;
  align-items:center;
  padding:60px 48px;
}
.l-panel::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(160deg,var(--navy) 0%,var(--navy2) 45%,var(--navy3) 100%);
  z-index:0;
}
.l-grid{
  position:absolute;inset:0;z-index:0;
  background-image:
    linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),
    linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);
  background-size:48px 48px;
}
.l-orb1{
  position:absolute;top:-180px;left:-180px;
  width:500px;height:500px;border-radius:50%;
  background:radial-gradient(circle,rgba(201,162,39,.15) 0%,transparent 65%);
  z-index:0;
}
.l-orb2{
  position:absolute;bottom:-120px;right:-100px;
  width:380px;height:380px;border-radius:50%;
  background:radial-gradient(circle,rgba(59,130,246,.1) 0%,transparent 65%);
  z-index:0;
}
.l-content{position:relative;z-index:1;max-width:420px;width:100%;text-align:center}
.l-logo-icon{
  width:76px;height:76px;border-radius:20px;margin:0 auto 22px;
  background:linear-gradient(135deg,var(--gold),var(--gold2));
  display:flex;align-items:center;justify-content:center;
  font-size:34px;color:var(--navy);
  box-shadow:0 8px 32px rgba(201,162,39,.4);
}
.l-brand{font-size:44px;font-weight:900;color:#fff;letter-spacing:-1.5px;margin-bottom:6px}
.l-tagline{font-size:15px;color:rgba(255,255,255,.45);margin-bottom:52px}
.feat-list{list-style:none;text-align:right}
.feat-list li{
  display:flex;align-items:center;gap:14px;
  padding:12px 0;border-bottom:1px solid rgba(255,255,255,.07);
  color:rgba(255,255,255,.8);font-size:14px;font-weight:500;
}
.feat-list li:last-child{border:none}
.f-icon{
  width:38px;height:38px;border-radius:10px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:15px;
}
.f-gold  {background:rgba(201,162,39,.18);color:var(--gold)}
.f-blue  {background:rgba(59,130,246,.18); color:#60a5fa}
.f-green {background:rgba(34,197,94,.15);  color:#4ade80}
.f-purple{background:rgba(139,92,246,.18); color:#c4b5fd}

/* ─── RIGHT PANEL ─── */
.r-panel{
  width:460px;flex-shrink:0;
  background:#fff;
  display:flex;flex-direction:column;justify-content:center;
  padding:52px 48px;
  box-shadow:-24px 0 64px rgba(0,0,0,.28);
  overflow-y:auto;
}
.r-mini-logo{
  display:flex;align-items:center;gap:10px;margin-bottom:40px;
}
.r-mini-icon{
  width:38px;height:38px;border-radius:9px;
  background:linear-gradient(135deg,var(--gold),var(--gold2));
  display:flex;align-items:center;justify-content:center;
  color:var(--navy);font-size:15px;
}
.r-mini-name{font-size:17px;font-weight:800;color:var(--navy)}
.r-heading{font-size:27px;font-weight:900;color:var(--navy);margin-bottom:5px}
.r-sub{font-size:13px;color:#9ca3af;margin-bottom:32px}

/* form */
.f-group{margin-bottom:18px}
.f-label{display:block;font-size:12px;font-weight:800;color:#374151;margin-bottom:7px;letter-spacing:.3px;text-transform:uppercase}
.f-wrap{position:relative}
.f-icon-l{position:absolute;top:50%;transform:translateY(-50%);right:14px;color:#bbb;font-size:14px;pointer-events:none}
.f-icon-r{position:absolute;top:50%;transform:translateY(-50%);left:12px;background:none;border:none;color:#bbb;font-size:13px;cursor:pointer;padding:2px}
.f-input{
  width:100%;padding:13px 42px 13px 40px;
  border:1.5px solid #e5e7eb;border-radius:10px;
  font-size:14px;font-family:'Tajawal',sans-serif;color:#111;
  background:#fff;transition:border-color .2s,box-shadow .2s;
}
.f-input:focus{outline:none;border-color:var(--navy3);box-shadow:0 0 0 3px rgba(26,58,110,.1)}
.f-input.err{border-color:#ef4444;background:#fff8f8}
.err-box{
  margin-bottom:20px;padding:13px 16px;
  background:#fef2f2;border:1px solid #fecaca;border-radius:10px;
  color:#dc2626;font-size:13px;font-weight:600;
  display:flex;align-items:center;gap:9px;
}
.btn-submit{
  width:100%;padding:14px;margin-top:6px;border:none;border-radius:11px;
  background:linear-gradient(135deg,var(--navy),var(--navy3));
  color:#fff;font-size:15px;font-weight:800;font-family:'Tajawal',sans-serif;
  cursor:pointer;transition:all .2s;
  display:flex;align-items:center;justify-content:center;gap:10px;
}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(10,22,40,.4);opacity:.93}
.btn-submit:active{transform:translateY(0)}
.divider-line{
  display:flex;align-items:center;gap:12px;
  margin:22px 0;color:#e5e7eb;font-size:11px;color:#ccc;
}
.divider-line::before,.divider-line::after{content:'';flex:1;height:1px;background:#f0f0f0}
.register-link{
  text-align:center;font-size:13px;color:#6b7280;
}
.register-link a{
  color:var(--navy3);font-weight:700;text-decoration:none;
}
.register-link a:hover{color:var(--gold);text-decoration:underline}
.r-footer{
  margin-top:28px;padding-top:20px;border-top:1px solid #f3f4f6;
  display:flex;justify-content:center;gap:20px;
}
.r-badge{display:flex;align-items:center;gap:5px;font-size:11px;color:#9ca3af}
.r-badge i{color:#22c55e}

@media(max-width:860px){
  .l-panel{display:none}
  .r-panel{width:100%;padding:40px 28px;box-shadow:none}
}
@media(max-width:480px){.r-panel{padding:28px 18px}}
</style>
</head>
<body>

<!-- LEFT -->
<div class="l-panel">
  <div class="l-grid"></div>
  <div class="l-orb1"></div>
  <div class="l-orb2"></div>
  <div class="l-content">
        <?php $__slogo = site_logo($conn); ?>
        <img src="<?= $__slogo ? e($__slogo) : './assets/img/logo-x.png' ?>" style="width:80px;">
    <div class="l-brand"><?= e(sc($conn,'site_name','OLFS')); ?></div>
    <div class="l-tagline">نظام إدارة مكاتب المحاماة الأذكى</div>
    <ul class="feat-list">
      <li><div class="f-icon f-gold"><i class="fas fa-gavel"></i></div>إدارة القضايا والجلسات بسهولة تامة</li>
      <li><div class="f-icon f-blue"><i class="fas fa-address-book"></i></div>ملفات شاملة لجميع العملاء والعقود</li>
      <li><div class="f-icon f-green"><i class="fas fa-wallet"></i></div>تتبع الأتعاب والشؤون المالية دقيقاً</li>
      <li><div class="f-icon f-purple"><i class="fas fa-robot"></i></div>مساعد ذكاء اصطناعي قانوني متخصص</li>
    </ul>
  </div>
</div>

<!-- RIGHT -->
<div class="r-panel">
  <div class="r-mini-logo">
    <img src="<?= ($__s2 = site_logo($conn)) ? e($__s2) : './assets/img/logo-x.png' ?>" style="width:40px;">
    <span class="r-mini-name"><?= e(sc($conn,'site_name','OLFS')); ?></span>
  </div>

  <div class="r-heading">مرحباً بعودتك</div>
  <div class="r-sub">سجّل دخولك للوصول إلى لوحة التحكم</div>

  <?php if($error): ?>
  <div class="err-box"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off" novalidate>
    <div class="f-group">
      <label class="f-label">اسم المستخدم</label>
      <div class="f-wrap">
        <i class="fas fa-user f-icon-l"></i>
        <input type="text" name="username" class="f-input <?= $error?'err':'' ?>"
               placeholder="أدخل اسم المستخدم"
               value="<?= htmlspecialchars($_POST['username']??'') ?>"
               autocomplete="off" autocorrect="off" autocapitalize="off"
               spellcheck="false" required autofocus>
      </div>
    </div>
    <div class="f-group">
      <label class="f-label">كلمة المرور</label>
      <div class="f-wrap">
        <i class="fas fa-lock f-icon-l"></i>
        <input type="password" name="password" id="pwField"
               class="f-input <?= $error?'err':'' ?>"
               placeholder="••••••••"
               autocomplete="new-password" required>
        <button type="button" class="f-icon-r" onclick="togglePw()">
          <i class="fas fa-eye" id="pwEye"></i>
        </button>
      </div>
    </div>
    <button type="submit" class="btn-submit" id="sbBtn">
      <i class="fas fa-sign-in-alt"></i>
      <span id="sbTxt">تسجيل الدخول</span>
    </button>
  </form>

  <div class="divider-line">أو</div>

  <div class="register-link">
    ليس لديك حساب؟
    <a href="public/pricing.php">سجّل مكتبك الآن</a>
  </div>

  <div class="r-footer">
    <span class="r-badge"><i class="fas fa-shield-alt"></i>SSL مشفر</span>
    <span class="r-badge"><i class="fas fa-lock"></i>بيانات آمنة</span>
    <span class="r-badge"><i class="fas fa-certificate"></i>معتمد</span>
  </div>
</div>

<script>
function togglePw(){
  var f=document.getElementById('pwField'),e=document.getElementById('pwEye');
  f.type=f.type==='password'?'text':'password';
  e.className=f.type==='password'?'fas fa-eye':'fas fa-eye-slash';
}
document.querySelector('form').addEventListener('submit',function(){
  var b=document.getElementById('sbBtn');
  document.getElementById('sbTxt').textContent='جاري التحقق...';
  b.disabled=true;b.style.opacity='.7';
});
</script>
</body>
</html>
