<?php
http_response_code(404);
$page_title = 'الصفحة غير موجودة';
require_once __DIR__ . '/../includes/client_portal_header.php';
?>

<div class="d-flex flex-column align-items-center justify-content-center text-center" style="padding:70px 20px">
  <div style="font-size:80px;font-weight:900;line-height:1;background:linear-gradient(135deg,#dba82a,#f5d97a);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:6px">
    404
  </div>
  <div style="width:64px;height:64px;border-radius:50%;background:rgba(184,134,11,.1);border:1px solid rgba(184,134,11,.28);display:flex;align-items:center;justify-content:center;font-size:26px;color:#c9971a;margin-bottom:20px">
    <i class="fas fa-link-slash"></i>
  </div>
  <h4 class="fw-bold mb-2" style="color:#0c1b36">هذه الصفحة غير موجودة</h4>
  <p class="text-muted mb-4" style="max-width:420px">
    يبدو أن الرابط الذي اتبعته غير صحيح، أو أن الصفحة نُقلت أو حُذفت. تأكّد من الرابط أو ارجع إلى نظرتك العامة.
  </p>
  <div class="d-flex gap-2 flex-wrap justify-content-center">
    <a href="dashboard.php" class="btn" style="background:linear-gradient(135deg,#0c1b36,#0f2040);color:#fff"><i class="fas fa-gauge-high me-1"></i>نظرة عامة</a>
    <a href="messages.php" class="btn btn-outline-secondary"><i class="fas fa-comments me-1"></i>تواصل مع المكتب</a>
  </div>
</div>

<?php include __DIR__ . '/../includes/client_portal_footer.php'; ?>
