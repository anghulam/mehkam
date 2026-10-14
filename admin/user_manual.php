<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireAdmin();
require_once '../includes/manual_builder.php';

// عرض/تحميل الدليل — يُولَّد تلقائياً أول مرة وعند كل إصدار جديد ثم يُخزَّن مؤقتاً
if (isset($_GET['view']) || isset($_GET['download'])) {
    try { manual_send($conn, isset($_GET['download']), isset($_GET['regen'])); }
    catch (\Throwable $e) { header('Location: user_manual.php?err=1'); exit; }
}

$page_title = 'دليل المستخدم';
$vi = mehkam_version_info();
$logoInfo = manual_logo_info($conn);
include '../includes/admin_header.php';
?>
<div class="mk-page-hdr">
  <div>
    <div class="mk-page-title"><i class="fas fa-book-open"></i> دليل المستخدم</div>
    <div class="mk-page-sub">كتاب إلكتروني (PDF) شامل لكل وحدات النظام وموديولاته — يتجدّد تلقائياً مع كل إصدار</div>
  </div>
</div>

<?php if (isset($_GET['err'])): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>تعذّر إنشاء الدليل حالياً — حاول مجدداً، وإن تكرر أبلغ الدعم الفني.</div>
<?php endif; ?>

<div class="card" style="max-width:720px">
  <div class="card-body p-4 text-center">
    <h4 class="fw-bold mb-1">دليل المستخدم — كتاب إلكتروني</h4>
    <div class="text-muted mb-3">الإصدار <b class="font-monospace"><?= e($vi['version']) ?></b><?= $vi['date'] !== '' ? ' · ' . e($vi['date']) : '' ?></div>
    <p class="text-muted" style="font-size:13px">يشرح البدء السريع، والأدوار والصلاحيات، وكل الوحدات الأساسية، وجميع الموديولات الإضافية (بما فيها ما يُضاف مستقبلاً)، والباقات، وأسئلة شائعة، وسجل التحديثات.</p>
    <div class="d-flex gap-2 justify-content-center flex-wrap">
      <a href="user_manual.php?view=1" target="_blank" class="btn btn-primary"><i class="fas fa-eye me-1"></i>عرض الدليل</a>
      <a href="user_manual.php?download=1" class="btn btn-outline-primary"><i class="fas fa-file-pdf me-1"></i>تحميل PDF</a>
      <a href="user_manual.php?view=1&regen=1" target="_blank" class="btn btn-outline-secondary"><i class="fas fa-rotate me-1"></i>إعادة توليد الآن</a>
    </div>
    <div class="mt-3 text-start" style="font-size:12.5px">
      <?php $shown = $logoInfo['source'] === 'uploaded' ? ($logoInfo['file'] ?? '') : '/assets/img/logo-x.png'; if ($shown): ?>
      <div class="text-center mb-2"><img src="<?= e('..' . $shown) ?>" alt="" style="max-height:70px;max-width:220px;background:#f1f3f7;border-radius:8px;padding:6px"><div class="text-muted font-monospace" style="font-size:11px;margin-top:4px"><?= e($shown) ?></div></div>
      <?php endif; ?>
      <?php if ($logoInfo['kind'] !== 'none'): ?>
      <div class="alert alert-success py-2 mb-2"><i class="fas fa-check-circle me-1"></i>يُستخدم في الكتاب <?= $logoInfo['source'] === 'uploaded' ? '<b>الشعار الذي رفعته</b> في إعدادات النظام' : 'الشعار الافتراضي (لم يُرفع شعار بعد)' ?>. <a href="settings.php">تغيير الشعار</a></div>
      <?php else: ?>
      <div class="alert alert-warning py-2 mb-2"><i class="fas fa-triangle-exclamation me-1"></i>تعذّر استخدام الشعار في الكتاب<?= $logoInfo['reason'] ? ': ' . e($logoInfo['reason']) : '' ?>. سيظهر اسم المنصة بدلاً منه. <a href="settings.php">إعدادات النظام</a></div>
      <?php endif; ?>
    </div>
    <div class="text-muted mt-3" style="font-size:11.5px">أول فتح بعد كل تحديث قد يستغرق بضع ثوانٍ لإعداد النسخة الأحدث.</div>
  </div>
</div>
<?php include '../includes/admin_footer.php'; ?>
