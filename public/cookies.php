<?php
require_once '../config/db.php';
require_once '../includes/content_helper.php';
$page_title = 'سياسة الكوكيز — ' . sc($conn,'site_name','مِحكام');
include 'includes/header.php';

$content  = sc($conn, 'legal_cookies', '');
$upd_date = sc($conn, 'legal_cookies_date', '');
?>

<section class="lx-phero">
  <div class="container lx-phero-in">
    <div class="lx-eye">القانوني</div>
    <h1 class="lx-h">سياسة <span class="gt">ملفات الارتباط</span></h1>
    <p class="lx-p">كيف نستخدم ملفات تعريف الارتباط (Cookies) في منصتنا</p>
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
        <p>تستخدم منصة <strong><?= e(sc($conn,'site_name','مِحكام')) ?></strong> ملفات تعريف الارتباط لتحسين تجربتك.</p>

        <h2>أنواع الكوكيز التي نستخدمها</h2>
        <ul>
          <li><strong>ضرورية:</strong> للحفاظ على جلسة تسجيل الدخول — مدتها انتهاء الجلسة.</li>
          <li><strong>وظيفية:</strong> لتذكر إعداداتك وتفضيلاتك — مدتها سنة.</li>
          <li><strong>تحليلية:</strong> لفهم كيفية الاستخدام وتحسين المنصة — مدتها سنتان.</li>
        </ul>

        <h2>التحكم في الكوكيز</h2>
        <p>يمكنك التحكم في ملفات تعريف الارتباط من إعدادات متصفحك. تعطيل الكوكيز الضرورية قد يؤثر على عمل المنصة.</p>

        <h2>التواصل</h2>
        <p>لأي استفسار: <a href="mailto:<?= e(sc($conn,'contact_email','')) ?>"><?= e(sc($conn,'contact_email','')) ?></a></p>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php include 'includes/footer.php'; ?>
