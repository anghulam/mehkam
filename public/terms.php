<?php
require_once '../config/db.php';
require_once '../includes/content_helper.php';
$page_title = 'شروط الاستخدام — ' . sc($conn,'site_name','مِحكام');
include 'includes/header.php';

$content  = sc($conn, 'legal_terms', '');
$upd_date = sc($conn, 'legal_terms_date', '');
?>

<section class="lx-phero">
  <div class="container lx-phero-in">
    <div class="lx-eye">القانوني</div>
    <h1 class="lx-h">شروط <span class="gt">الاستخدام</span></h1>
    <p class="lx-p">الأحكام التي تحكم استخدامك لمنصة <?= e(sc($conn,'site_name','مِحكام')) ?></p>
  </div>
</section>

<section class="lx-sec">
  <div class="container">
    <div class="lx-legal">
      <?php if ($upd_date): ?>
      <div class="lx-legal-date">
        <i class="fas fa-calendar me-2"></i>آخر تحديث <?= date('d F Y', strtotime($upd_date)) ?>
      </div>
      <?php endif; ?>

      <?php if ($content): ?>
        <div style="white-space:pre-wrap;line-height:2;color:rgba(255,255,255,.75);font-size:15px"><?= nl2br(e($content)) ?></div>
      <?php else: ?>
        <p>مرحباً بك في <strong><?= e(sc($conn,'site_name','مِحكام')) ?></strong>. باستخدامك للمنصة فإنك توافق على الالتزام بهذه الشروط.</p>

        <h2>الاستخدام المقبول</h2>
        <ul>
          <li>إدارة قضايا المحاماة وملفات العملاء.</li>
          <li>تتبع الجلسات والمواعيد القانونية.</li>
          <li>إدارة الشؤون المالية لمكتب المحاماة.</li>
        </ul>

        <h2>الاستخدامات المحظورة</h2>
        <ul>
          <li>أي استخدام غير قانوني أو مخالف للأنظمة السعودية.</li>
          <li>محاولة اختراق أو إيقاف أنظمة المنصة.</li>
          <li>مشاركة بيانات اعتماد حسابك مع أطراف غير مصرح بها.</li>
        </ul>

        <h2>الملكية الفكرية</h2>
        <p>جميع حقوق الملكية الفكرية للمنصة محفوظة. بياناتك التي تدخلها تبقى ملكاً لك.</p>

        <div style="margin-top:32px;padding:20px 24px;background:var(--b2);border:1px solid var(--b1);border-radius:var(--r);font-size:13px">
          <strong style="color:var(--gold3)">ملاحظة:</strong> تخضع هذه الشروط لأنظمة المملكة العربية السعودية.
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php include 'includes/footer.php'; ?>
