<?php
require_once '../config/db.php';
require_once '../includes/content_helper.php';
$page_title = 'سياسة الاسترداد — ' . sc($conn,'site_name','مِحكام');
include 'includes/header.php';

$content  = sc($conn, 'legal_refund', '');
$upd_date = sc($conn, 'legal_refund_date', '');
?>

<section class="lx-phero">
  <div class="container lx-phero-in">
    <div class="lx-eye">القانوني</div>
    <h1 class="lx-h">سياسة <span class="gt">الاسترداد</span></h1>
    <p class="lx-p">سياستنا الواضحة لاسترداد المبالغ المدفوعة</p>
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
        <div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:var(--r);padding:20px 24px;margin-bottom:32px">
          <p style="margin:0;font-size:15px;font-weight:700;color:#4ade80">
            <i class="fas fa-shield-alt me-2"></i>
            نلتزم بضمان رضاك التام. إذا لم تكن راضياً خلال أول 14 يوماً، نسترد لك مبلغك كاملاً.
          </p>
        </div>

        <h2>ضمان الاسترداد خلال 14 يوماً</h2>
        <p>نقدم ضمان استرداد كامل خلال <strong>14 يوماً</strong> من تاريخ الاشتراك الأول دون أي أسئلة.</p>

        <h2>شروط الاسترداد بعد 14 يوماً</h2>
        <ul>
          <li><strong>الاشتراكات السنوية:</strong> يمكن استرداد الأشهر غير المستخدمة في حال وجود عطل جوهري.</li>
        </ul>

        <h2>كيفية طلب الاسترداد</h2>
        <p>أرسل بريداً إلى <a href="mailto:<?= e(sc($conn,'contact_email','')) ?>"><?= e(sc($conn,'contact_email','')) ?></a> مع رقم حسابك وسبب الطلب. سنرد خلال يومي عمل.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php include 'includes/footer.php'; ?>
