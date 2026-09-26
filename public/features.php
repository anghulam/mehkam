<?php
require_once '../config/db.php';
require_once '../includes/content_helper.php';
$_sn_f=sc($conn,'site_name','OLFS');
$page_title='المزايا — '.$_sn_f;
include 'includes/header.php';
?>
<section class="lx-phero">
  <div class="container lx-phero-in text-center">
    <div class="lx-eye" style="justify-content:center">المزايا</div>
    <h1 class="lx-h">كل ما تحتاجه لإدارة <span class="gt">مكتب محاماة ناجح</span></h1>
    <p class="lx-p mx-auto">منصة متكاملة مصممة خصيصاً لاحتياجات المحامين في المملكة العربية السعودية</p>
  </div>
</section>
<section class="lx-sec-mid">
  <div class="container">
    <div class="lx-feat-grid reveal">
      <?php foreach([
        ['gavel','إدارة القضايا','تتبع شامل لجميع قضاياك مع حالة كل قضية والمحكمة المختصة وتواريخ الجلسات'],
        ['calendar-alt','جدولة الجلسات','تسجيل وتتبع الجلسات مع تنبيهات مسبقة وتذكيرات تلقائية'],
        ['address-book','ملفات العملاء','سجل شامل لكل عميل يضم بياناته وقضاياه وعقوده ووكالاته'],
        ['file-signature','العقود والوكالات','إنشاء وإدارة عقود الأتعاب ووثائق التوكيل مع تتبع حالتها'],
        ['wallet','الشؤون المالية','تتبع دقيق للإيرادات والمصروفات مع تقارير مالية شاملة'],
        ['tasks','المهام والمواعيد','نظام متكامل لإدارة المهام وتوزيعها على الفريق'],
        ['envelope','الصادر والوارد','أرشفة وتتبع جميع المراسلات مع ربطها بالقضايا والعملاء'],
        ['book-open','المكتبة القانونية','قاعدة بيانات ضخمة من الأنظمة والنماذج القانونية المحدّثة'],
        ['archive','الأرشيف الإلكتروني','حفظ وتنظيم المستندات في أرشيف رقمي منظم وسهل البحث'],
        ['users','إدارة المستخدمين','تحكم كامل في صلاحيات فريق عملك مع ضبط دقيق لكل مستخدم'],
        ['robot','المساعد الذكي AI','مساعد قانوني بالذكاء الاصطناعي متخصص في الأنظمة السعودية'],
        ['bell','نظام التنبيهات','تنبيهات ذكية تلقائية للجلسات والمهام وانتهاء صلاحية العقود'],
        ['file-invoice-dollar','الفوترة الإلكترونية (زاتكا)','فواتير متوافقة مع هيئة الزكاة والضريبة والجمارك برمز QR وتسلسل معتمد وإصدار تلقائي'],
        ['folder-open','ملف الأعمال للعميل','قضايا العميل وجلساته وعقوده وفواتيره وسجل التواصل معه في شاشة واحدة'],
        ['user-shield','صلاحيات ومستويات اعتماد','كل موظف يرى ما يخصه فقط، والمدير يشرف على الكل ويعتمد الإجراءات الحساسة'],
        ['hand-holding-dollar','الخدمات الرقمية','تسجيل الخدمات الرقمية المنجزة للعملاء وإصدار فواتيرها مباشرة'],
        ['scale-balanced','السوابق القضائية','مكتبة سوابق للرجوع إليها وربطها بقضاياك'],
        ['chart-line','التقارير المتقدمة','تقارير وتحليلات لأداء المكتب والإيرادات والقضايا'],
      ] as [$ic,$ti,$de]): ?>
      <div class="lx-feat-cell">
        <div class="lx-feat-icon"><i class="fas fa-<?=$ic?>"></i></div>
        <h3 class="lx-feat-title"><?=$ti?></h3>
        <p class="lx-feat-desc"><?=$de?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php
$_feat_mods = [];
try {
    $_fmr = $conn->query("SELECT name,description,icon,base_price FROM modules WHERE is_active=1 ORDER BY sort_order,id");
    if ($_fmr) while ($_fm = $_fmr->fetch_assoc()) $_feat_mods[] = $_fm;
} catch (\Throwable $e) {}
?>
<?php if ($_feat_mods): ?>
<section class="lx-sec-mid">
  <div class="container">
    <div class="text-center mb-5 reveal">
      <div class="lx-eye" style="justify-content:center">موديولات إضافية</div>
      <h2 class="lx-h">فعّل فقط <span class="gt">ما تحتاجه مكتبك</span></h2>
      <p class="lx-p mx-auto">أضف الموديولات التي تناسب عملك — عند بناء باقتك المخصصة أو لاحقاً من متجر الموديولات داخل حسابك</p>
    </div>
    <?php $_featured_mods = array_slice($_feat_mods, 0, 9); $_rest_mods = array_slice($_feat_mods, 9); ?>
    <div class="lx-feat-grid reveal">
      <?php foreach ($_featured_mods as $_fm): ?>
      <div class="lx-feat-cell">
        <div class="lx-feat-icon"><i class="fas fa-<?= e($_fm['icon'] ?: 'puzzle-piece') ?>"></i></div>
        <h3 class="lx-feat-title"><?= e($_fm['name']) ?></h3>
        <p class="lx-feat-desc"><?= e($_fm['description']) ?></p>
        <?php if ((float)$_fm['base_price'] > 0): ?>
        <div style="font-size:12px;font-weight:700;color:var(--gold3);margin-top:8px">من <?= number_format((float)$_fm['base_price'],0) ?> ر.س / شهر</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($_rest_mods): ?>
    <div class="text-center mt-4 reveal" style="font-size:13px;color:var(--t3)">و<?= count($_rest_mods) ?> موديولاً إضافياً آخر:</div>
    <div class="reveal d-flex flex-wrap justify-content-center gap-2 mt-3" style="max-width:900px;margin:0 auto">
      <?php foreach ($_rest_mods as $_fm): ?>
      <span style="display:inline-flex;align-items:center;gap:6px;background:var(--b2);border:1px solid var(--b1);border-radius:100px;padding:7px 14px;font-size:12.5px;color:var(--t2)">
        <i class="fas fa-<?= e($_fm['icon'] ?: 'puzzle-piece') ?>" style="color:var(--gold3);font-size:11px"></i><?= e($_fm['name']) ?>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="text-center mt-4 reveal">
      <a href="pricing.php#custom-pkg-section" class="lx-btn-cta"><i class="fas fa-puzzle-piece"></i>ابنِ باقتك المخصصة</a>
    </div>
  </div>
</section>
<?php endif; ?>
<section class="lx-sec">
  <div class="container">
    <div class="text-center mb-5 reveal">
      <div class="lx-eye" style="justify-content:center">المقارنة</div>
      <h2 class="lx-h">مع <?= e(sc($conn,'site_name', $default = '')); ?> <span class="gt">الفرق واضح</span></h2>
    </div>
    <div class="row g-4">
      <div class="col-md-6 reveal">
        <div style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:var(--r2);padding:28px;height:100%">
          <h5 style="font-size:17px;font-weight:800;color:#f87171;margin-bottom:18px"><i class="fas fa-times-circle me-2"></i>بدون <?= e(sc($conn,'site_name', $default = '')); ?></h5>
          <ul style="list-style:none;padding:0;margin:0">
            <?php foreach(['جداول Excel معقدة وغير منظمة','نسيان مواعيد الجلسات المهمة','صعوبة تتبع الأتعاب والمدفوعات','ضياع الوثائق والعقود المهمة','تأخر في الرد على العملاء','صعوبة تنسيق العمل مع الفريق'] as $it): ?>
            <li style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(239,68,68,.1);font-size:14px;color:var(--t2)"><i class="fas fa-times" style="color:#f87171;flex-shrink:0"></i><?=$it?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
      <div class="col-md-6 reveal">
        <div style="background:var(--b2);border:1px solid var(--b1);border-radius:var(--r2);padding:28px;height:100%">
          <h5 style="font-size:17px;font-weight:800;color:var(--gold3);margin-bottom:18px"><i class="fas fa-check-circle me-2"></i>مع <?= e(sc($conn,'site_name', $default = '')); ?></h5>
          <ul style="list-style:none;padding:0;margin:0">
            <?php foreach(['لوحة تحكم ذكية شاملة وسهلة','تنبيهات تلقائية للجلسات والمهام','تقارير مالية دقيقة فورية','أرشيف رقمي منظم وآمن','متابعة فورية لطلبات العملاء','تنسيق سلس وفعّال مع الفريق'] as $it): ?>
            <li style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--b2);font-size:14px;color:var(--t2)"><i class="fas fa-check" style="color:var(--gold3);flex-shrink:0"></i><?=$it?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>
</section>
<section class="lx-cta">
  <div class="container text-center position-relative" style="z-index:1">
    <div class="reveal">
      <h2 class="lx-h">مستعد للتجربة؟</h2>
      <p class="lx-p mx-auto mb-4">ابدأ نسختك التجريبية المجانية اليوم</p>
      <a href="pricing.php" class="lx-btn-cta"><i class="fas fa-rocket"></i>ابدأ مجاناً — 14 يوم</a>
    </div>
  </div>
</section>
<?php include 'includes/footer.php'; ?>
