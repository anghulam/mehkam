<?php
require_once '../config/db.php';
require_once '../includes/content_helper.php';
$page_title = 'سياسة الخصوصية — ' . sc($conn,'site_name','مِحكام');
include 'includes/header.php';

$content = sc($conn, 'legal_privacy', '');
$upd_date = sc($conn, 'legal_privacy_date', '');
?>

<section class="lx-phero">
  <div class="container lx-phero-in">
    <div class="lx-eye">القانوني</div>
    <h1 class="lx-h">سياسة <span class="gt">الخصوصية</span></h1>
    <p class="lx-p">كيف نجمع بياناتك ونستخدمها ونحميها</p>
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
        <!-- المحتوى الافتراضي -->
        <p>تلتزم <strong><?= e(sc($conn,'site_name','مِحكام')) ?></strong> بحماية خصوصيتك وأمان بياناتك.</p>

        <h2>البيانات التي نجمعها</h2>
        <ul>
          <li><strong>بيانات الحساب:</strong> الاسم، البريد الإلكتروني، رقم الجوال، بيانات الاشتراك.</li>
          <li><strong>بيانات المكتب:</strong> اسم مكتب المحاماة، رقم الترخيص، عدد المستخدمين.</li>
          <li><strong>بيانات الاستخدام:</strong> كيفية تفاعلك مع المنصة والصفحات التي تزورها.</li>
        </ul>

        <h2>كيف نستخدم بياناتك</h2>
        <ul>
          <li>تقديم خدمات المنصة وتشغيلها.</li>
          <li>إرسال إشعارات متعلقة بحسابك واشتراكك.</li>
          <li>تحسين المنصة بناءً على أنماط الاستخدام.</li>
          <li>الامتثال للمتطلبات القانونية والتنظيمية.</li>
        </ul>

        <h2>حقوقك</h2>
        <ul>
          <li><strong>الاطلاع:</strong> طلب نسخة من بياناتك الشخصية.</li>
          <li><strong>التصحيح:</strong> تصحيح أي بيانات غير دقيقة.</li>
          <li><strong>الحذف:</strong> طلب حذف بياناتك.</li>
          <li><strong>التصدير:</strong> تصدير بياناتك بصيغة قابلة للقراءة.</li>
        </ul>

        <h2>التواصل</h2>
        <p>البريد الإلكتروني: <a href="mailto:<?= e(sc($conn,'contact_email','')) ?>"><?= e(sc($conn,'contact_email','')) ?></a></p>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php include 'includes/footer.php'; ?>
