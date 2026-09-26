<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('calendar_sync','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'calendar_sync')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'مزامنة التقويم';
$uid = (int)($_SESSION['user_id'] ?? 0);

$conn->query("CREATE TABLE IF NOT EXISTS calendar_feeds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    office_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── توليد الرمز إن لم يوجد ── */
$feed = $conn->query("SELECT * FROM calendar_feeds WHERE user_id=$uid LIMIT 1")->fetch_assoc();
if (!$feed) {
    $token = bin2hex(random_bytes(24));
    $conn->query("INSERT INTO calendar_feeds (user_id,office_id,token) VALUES ($uid,$oid,'$token')");
    $feed = ['token' => $token];
}
/* ── إعادة توليد الرمز ── */
if (isset($_GET['regenerate'])) {
    $token = bin2hex(random_bytes(24));
    $conn->query("UPDATE calendar_feeds SET token='$token' WHERE user_id=$uid");
    header("Location: calendar_sync.php?msg=saved"); exit;
}

$_site = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'mehkam.app');
$feed_url = rtrim($_site,'/') . '/public/calendar_feed.php?token=' . $feed['token'];

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-calendar-days"></i> مزامنة التقويم</div>
<div class="mk-page-sub mb-4">اشترك برابط تقويمك الشخصي لتظهر جلساتك ومهامك مباشرة في Google Calendar أو Outlook</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم توليد رابط جديد — استخدمه بالاشتراك من جديد
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-2"><i class="fas fa-link me-1 text-primary"></i>رابط تقويمك الشخصي</div>
    <div class="input-group mb-2">
      <input type="text" class="form-control font-monospace" value="<?= e($feed_url) ?>" id="feedUrl" readonly>
      <button class="btn btn-outline-primary" onclick="navigator.clipboard.writeText(document.getElementById('feedUrl').value);this.innerHTML='<i class=\'fas fa-check\'></i> تم النسخ';"><i class="fas fa-copy me-1"></i>نسخ</button>
    </div>
    <a href="calendar_sync.php?regenerate=1" class="btn btn-sm btn-outline-danger" onclick="return confirm('توليد رابط جديد؟ الرابط القديم يتوقف عن العمل فوراً.')"><i class="fas fa-rotate me-1"></i>توليد رابط جديد</a>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2"><i class="fas fa-circle-info me-1 text-primary"></i>طريقة الاشتراك</div>
    <div class="row g-3" style="font-size:13px">
      <div class="col-md-6">
        <div class="fw-bold mb-1"><i class="fab fa-google me-1"></i>Google Calendar</div>
        <ol style="color:#475569;line-height:1.8;padding-right:18px">
          <li>افتح Google Calendar من المتصفح</li>
          <li>من القائمة الجانبية: "أضِف تقويماً آخر" ← "عبر الرابط (URL)"</li>
          <li>الصق الرابط أعلاه واضغط "إضافة تقويم"</li>
        </ol>
      </div>
      <div class="col-md-6">
        <div class="fw-bold mb-1"><i class="fas fa-envelope-open-text me-1"></i>Outlook</div>
        <ol style="color:#475569;line-height:1.8;padding-right:18px">
          <li>من التقويم: "إضافة تقويم" ← "الاشتراك من الويب"</li>
          <li>الصق الرابط أعلاه وأعطِه اسماً</li>
          <li>اضغط "استيراد"</li>
        </ol>
      </div>
    </div>
    <div class="alert alert-info mt-3 mb-0" style="font-size:12px"><i class="fas fa-circle-info me-1"></i>يعرض تقويمك الشخصي فقط: جلساتك القادمة ومهامك — يُحدَّث تلقائياً من تطبيق التقويم كل عدة ساعات.</div>
  </div>
</div>

<?php include '../includes/office_footer.php'; ?>
