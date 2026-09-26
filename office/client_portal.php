<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('clients','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'client_portal')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'بوابة العميل';

$_site = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'mehkam.app');
$portal_url = rtrim($_site,'/') . '/client/login.php';
$enabled_count = (int)$conn->query("SELECT COUNT(*) c FROM clients WHERE office_id=$oid AND portal_enabled=1")->fetch_assoc()['c'];

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-door-open"></i> بوابة العميل</div>
<div class="mk-page-sub mb-4">بوابة مستقلة يدخلها عميلك بحسابه الخاص ليشوف قضاياه وفواتيره ويتواصل مع مكتبك</div>

<div class="card mb-3">
  <div class="card-body d-flex align-items-center gap-3">
    <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:#eff6ff;color:#2563eb;font-size:20px"><i class="fas fa-users"></i></div>
    <div>
      <div class="fw-bold" style="font-size:18px"><?= $enabled_count ?></div>
      <div class="text-muted" style="font-size:12px">عميل لديه وصول مفعّل للبوابة</div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="fw-semibold mb-2"><i class="fas fa-circle-info me-1 text-primary"></i>كيف تفعّلها لعميل؟</div>
    <ol style="font-size:13px;color:#475569;line-height:1.9">
      <li>افتح <a href="clients.php">ملفات العملاء</a> واختر العميل المطلوب</li>
      <li>من ملف العميل، اضغط <b>«تفعيل بوابة العميل»</b></li>
      <li>سلّم العميل اسم المستخدم وكلمة المرور التي تظهر لك (مرة واحدة فقط)</li>
    </ol>
    <div class="mt-3 p-3 rounded" style="background:#f8fafc;font-size:13px">
      رابط دخول العملاء: <a href="<?= e($portal_url) ?>" target="_blank" class="font-monospace"><?= e($portal_url) ?></a>
    </div>
  </div>
</div>

<?php include '../includes/office_footer.php'; ?>
