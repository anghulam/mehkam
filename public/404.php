<?php
require_once '../config/db.php';
require_once '../includes/content_helper.php';
$_sn_404 = sc($conn, 'site_name', 'OLFS');
$page_title = 'الصفحة غير موجودة — ' . $_sn_404;
$page_desc  = 'الصفحة التي تبحث عنها غير موجودة أو تم نقلها.';
// يمنع الصفحة من أن تُفهرَس أو تُحسب زيارة صحيحة من محركات البحث
http_response_code(404);
include 'includes/header.php';
?>

<style>
.e404-wrap{min-height:100vh;display:flex;align-items:center;background:linear-gradient(160deg,var(--g) 0%,#0a1022 45%,#06080f 100%);position:relative;overflow:hidden;padding:120px 0 60px}
.e404-wrap::before{content:'';position:absolute;top:-120px;right:-100px;width:560px;height:560px;border-radius:50%;background:radial-gradient(circle,rgba(184,134,11,.1) 0%,transparent 65%);pointer-events:none}
.e404-wrap::after{content:'';position:absolute;bottom:-80px;left:-80px;width:420px;height:420px;border-radius:50%;background:radial-gradient(circle,rgba(26,58,110,.28) 0%,transparent 65%);pointer-events:none}
.e404-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(184,134,11,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(184,134,11,.05) 1px,transparent 1px);background-size:56px 56px;pointer-events:none}
.e404-in{position:relative;z-index:2;text-align:center}
.e404-num{font-size:clamp(90px,18vw,190px);font-weight:900;line-height:1;letter-spacing:-4px;background:linear-gradient(135deg,var(--gold3),var(--gold5));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:4px;position:relative;display:inline-flex;align-items:center;gap:.05em}
.e404-gavel{width:.62em;height:.62em;border-radius:22%;background:var(--b2);border:2px solid var(--b1);display:inline-flex;align-items:center;justify-content:center;color:var(--gold4);font-size:.42em;flex-shrink:0;box-shadow:var(--sh)}
.e404-title{font-size:clamp(20px,3.4vw,30px);font-weight:900;color:var(--white);margin-bottom:14px}
.e404-desc{font-size:15.5px;color:var(--t2);line-height:1.9;max-width:480px;margin:0 auto 36px}
.e404-actions{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;margin-bottom:52px}
.e404-links{display:flex;gap:10px 28px;justify-content:center;flex-wrap:wrap;padding-top:32px;border-top:1px solid var(--b1);max-width:620px;margin:0 auto}
.e404-links a{font-size:13.5px;font-weight:600;color:var(--t3);transition:color .2s;display:inline-flex;align-items:center;gap:6px}
.e404-links a:hover{color:var(--gold4)}
.e404-links i{font-size:11px;color:var(--gold3)}
@media(max-width:575.98px){.e404-wrap{padding:100px 0 44px}.e404-actions{flex-direction:column;align-items:stretch}.e404-actions .lx-btn-cta,.e404-actions .lx-btn-out{width:100%;justify-content:center}}
</style>

<section class="e404-wrap">
  <div class="e404-grid"></div>
  <div class="container e404-in">
    <div class="lx-eye" style="justify-content:center">خطأ 404</div>
    <div class="e404-num">4<span class="e404-gavel"><i class="fas fa-gavel"></i></span>4</div>
    <h1 class="e404-title">هذه الصفحة غير موجودة</h1>
    <p class="e404-desc">
      يبدو أن الرابط الذي اتبعته غير صحيح، أو أن الصفحة نُقلت أو حُذفت.
      تأكّد من الرابط، أو ارجع إلى الصفحة الرئيسية للمتابعة من هناك.
    </p>
    <div class="e404-actions">
      <a href="home.php" class="lx-btn-cta"><i class="fas fa-house"></i>الصفحة الرئيسية</a>
      <a href="contact.php" class="lx-btn-out"><i class="fas fa-headset"></i>تواصل مع الدعم</a>
    </div>
    <div class="e404-links">
      <a href="pricing.php"><i class="fas fa-tag"></i>الأسعار</a>
      <a href="features.php"><i class="fas fa-star"></i>المزايا</a>
      <a href="about.php"><i class="fas fa-circle-info"></i>من نحن</a>
      <a href="../login.php"><i class="fas fa-right-to-bracket"></i>تسجيل الدخول</a>
    </div>
  </div>
</section>

<?php include 'includes/footer.php'; ?>
