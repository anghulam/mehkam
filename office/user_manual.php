<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
require_once '../includes/manual_builder.php';

// عرض/تحميل الدليل — يُولَّد تلقائياً أول مرة وعند كل إصدار جديد ثم يُخزَّن مؤقتاً
if (isset($_GET['view']) || isset($_GET['download'])) {
    try { manual_send($conn, isset($_GET['download'])); }
    catch (\Throwable $e) { header('Location: user_manual.php?err=1'); exit; }
}

$page_title = 'دليل المستخدم';
$vi = mehkam_version_info();
include '../includes/office_header.php';
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
    <?php $lg = function_exists('site_logo') ? site_logo($conn) : ''; if ($lg): ?>
    <img src="<?= e($lg) ?>" alt="" style="max-height:70px;max-width:220px;margin-bottom:14px">
    <?php endif; ?>
    <h4 class="fw-bold mb-1">دليل المستخدم — كتاب إلكتروني</h4>
    <div class="text-muted mb-3">الإصدار <b class="font-monospace"><?= e($vi['version']) ?></b><?= $vi['date'] !== '' ? ' · ' . e($vi['date']) : '' ?></div>
    <p class="text-muted" style="font-size:13px">يشرح البدء السريع، والأدوار والصلاحيات، وكل الوحدات الأساسية، وجميع الموديولات الإضافية (بما فيها ما يُضاف مستقبلاً)، والباقات، وأسئلة شائعة، وسجل التحديثات.</p>
    <div class="d-flex gap-2 justify-content-center flex-wrap">
      <a href="user_manual.php?view=1" target="_blank" class="btn btn-primary"><i class="fas fa-eye me-1"></i>عرض الدليل</a>
      <a href="user_manual.php?download=1" class="btn btn-outline-primary"><i class="fas fa-file-pdf me-1"></i>تحميل PDF</a>
    </div>
    <div class="text-muted mt-3" style="font-size:11.5px">أول فتح بعد كل تحديث قد يستغرق بضع ثوانٍ لإعداد النسخة الأحدث.</div>
  </div>
</div>
<?php include '../includes/office_footer.php'; ?>
