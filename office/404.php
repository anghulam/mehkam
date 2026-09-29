<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
http_response_code(404);
$page_title = 'الصفحة غير موجودة';
include '../includes/office_header.php';
?>

<div class="d-flex flex-column align-items-center justify-content-center text-center" style="padding:70px 20px">
  <div style="font-size:80px;font-weight:900;line-height:1;background:linear-gradient(135deg,var(--mk-gold3),var(--mk-gold5));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:6px">
    404
  </div>
  <div style="width:64px;height:64px;border-radius:50%;background:var(--mk-gold-bg);border:1px solid var(--mk-gold-bd);display:flex;align-items:center;justify-content:center;font-size:26px;color:var(--mk-gold2);margin-bottom:20px">
    <i class="fas fa-gavel"></i>
  </div>
  <h4 class="fw-bold mb-2" style="color:var(--mk-t1)">هذه الصفحة غير موجودة</h4>
  <p class="text-muted mb-4" style="max-width:420px">
    يبدو أن الرابط الذي اتبعته غير صحيح، أو أن الصفحة نُقلت أو حُذفت. تأكّد من الرابط أو ارجع إلى لوحة التحكم.
  </p>
  <div class="d-flex gap-2 flex-wrap justify-content-center">
    <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-gauge-high me-1"></i>لوحة التحكم</a>
    <a href="mailto:support@mehkam.app" class="btn btn-outline-secondary"><i class="fas fa-headset me-1"></i>تواصل مع الدعم</a>
  </div>
</div>

<?php include '../includes/office_footer.php'; ?>
