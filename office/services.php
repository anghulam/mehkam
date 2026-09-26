<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
$page_title = 'الخدمات الإلكترونية';
include '../includes/office_header.php';

/**
 * روابط الجهات والخدمات الحكومية القانونية في المملكة العربية السعودية
 * منظّمة حسب القطاع. عدّل/أضف بحرّية.
 */
$groups = [
  'العدل والقضاء' => [
    ['ناجز — وزارة العدل', 'الخدمات العدلية: القضايا، الجلسات، التنفيذ، التوثيق، الإفراغ العقاري', 'https://najiz.sa', 'gavel'],
    ['وزارة العدل', 'البوابة الرئيسية والأنظمة واللوائح والتعاميم', 'https://moj.gov.sa', 'building-columns'],
    ['نظام التنفيذ الإلكتروني', 'طلبات التنفيذ، إيقاف الخدمات، الحجز والإفراج', 'https://najiz.sa/applications/landing/execution', 'hammer'],
    ['التوثيق (كتابة العدل)', 'الوكالات، الإقرارات، تحديث الصكوك، محاضر الشركات', 'https://najiz.sa/applications/landing/notary', 'file-signature'],
    ['بوابة التحكيم — المركز السعودي (SCCA)', 'التحكيم التجاري والوساطة', 'https://www.scca.org.sa', 'scale-balanced'],
    ['ديوان المظالم (معين)', 'القضاء الإداري والطعن في القرارات الحكومية', 'https://www.bog.gov.sa', 'landmark'],
    ['بوابة معين الإلكترونية', 'رفع الدعاوى الإدارية ومتابعتها', 'https://bab.bog.gov.sa', 'folder-open'],
    ['النيابة العامة', 'البلاغات الجزائية والشكاوى', 'https://www.pp.gov.sa', 'shield-halved'],
    ['المحكمة العليا', 'المبادئ القضائية وقرارات الهيئة العامة', 'https://sjc.gov.sa', 'building-columns'],
    ['المجلس الأعلى للقضاء', 'التصنيف القضائي والمبادئ', 'https://sjc.gov.sa', 'users-between-lines'],
  ],
  'المحاماة والتراخيص المهنية' => [
    ['الهيئة السعودية للمحامين', 'قيد المحامين، التدريب، بطاقة المحاماة، السجل', 'https://sba.gov.sa', 'id-badge'],
    ['المركز السعودي للاعتماد القانوني', 'اعتماد المكاتب وشركات المحاماة', 'https://sba.gov.sa', 'certificate'],
    ['هيئة المحاماة — الاستعلام عن محامٍ', 'التحقق من ترخيص محامٍ أو مكتب', 'https://sba.gov.sa/ar/e-services', 'user-check'],
    ['الهيئة السعودية للمحاسبين القانونيين (SOCPA)', 'للتقارير المالية والخبرة المحاسبية', 'https://socpa.org.sa', 'calculator'],
  ],
  'الأنظمة والتشريعات' => [
    ['هيئة الخبراء بمجلس الوزراء', 'الأنظمة واللوائح والأوامر الملكية', 'https://laws.boe.gov.sa', 'book'],
    ['المركز الوطني للوثائق والمحفوظات', 'الوثائق الرسمية التاريخية', 'https://ncar.gov.sa', 'box-archive'],
    ['أم القرى — الجريدة الرسمية', 'نصوص الأنظمة والمراسيم فور صدورها', 'https://uqn.gov.sa', 'newspaper'],
    ['منصة استطلاع', 'مشاريع الأنظمة واللوائح المطروحة للرأي', 'https://istitlaa.ncc.gov.sa', 'comments'],
    ['المركز الوطني للتنافسية', 'اللوائح التنظيمية وتحسين بيئة الأعمال', 'https://ncc.gov.sa', 'chart-line'],
    ['هيئة تنظيم المحتوى (نظام الإجراءات الجزائية)', 'الأنظمة الجزائية والإجرائية', 'https://laws.boe.gov.sa', 'scroll'],
  ],
  'التجارة والأعمال والشركات' => [
    ['وزارة التجارة', 'السجل التجاري، الأسماء التجارية، الغش التجاري', 'https://mc.gov.sa', 'store'],
    ['المركز السعودي للأعمال', 'تأسيس وتعديل الشركات والسجلات', 'https://business.sa', 'briefcase'],
    ['قوائم — السجل التجاري', 'الاستعلام عن السجلات ونشر القوائم المالية', 'https://qawaem.mc.gov.sa', 'list-check'],
    ['المركز الوطني للمنافسة (مكافحة الاحتكار)', 'بلاغات الممارسات الاحتكارية والتركّز', 'https://mgrc.gov.sa', 'scale-unbalanced'],
    ['الهيئة العامة للمنافسة', 'طلبات عدم الممانعة على التركّز الاقتصادي', 'https://gac.gov.sa', 'handshake-angle'],
    ['المركز الوطني للتخصيص', 'عقود التخصيص وشراكات القطاع الخاص', 'https://ncp.gov.sa', 'building'],
    ['الهيئة العامة للأوقاف', 'نظارة الأوقاف والاستثمار الوقفي', 'https://awqaf.gov.sa', 'mosque'],
    ['وزارة الاستثمار (MISA)', 'تراخيص الاستثمار الأجنبي', 'https://misa.gov.sa', 'globe'],
    ['هيئة السوق المالية', 'أنظمة الشركات المدرجة والأوراق المالية', 'https://cma.org.sa', 'chart-column'],
  ],
  'الزكاة والضرائب والجمارك' => [
    ['هيئة الزكاة والضريبة والجمارك (ZATCA)', 'الإقرارات، ضريبة القيمة المضافة، الاعتراضات', 'https://zatca.gov.sa', 'file-invoice-dollar'],
    ['بوابة فاتورة — الفوترة الإلكترونية', 'الربط والتكامل للمرحلة الثانية', 'https://fatoora.zatca.gov.sa', 'qrcode'],
    ['الأمانة العامة للجان الضريبية', 'التظلمات والاعتراضات الضريبية والجمركية', 'https://gstc.gov.sa', 'gavel'],
    ['التحقق من شهادة ضريبة القيمة المضافة', 'الاستعلام عن تسجيل منشأة في الضريبة', 'https://zatca.gov.sa/ar/eServices', 'circle-check'],
  ],
  'العمل والموارد البشرية' => [
    ['وزارة الموارد البشرية والتنمية الاجتماعية', 'الأنظمة العمالية والخدمات', 'https://hrsd.gov.sa', 'users'],
    ['منصة قوى', 'عقود العمل، نقل الخدمات، الاستقدام', 'https://qiwa.sa', 'briefcase'],
    ['التسوية الودية للمنازعات العمالية', 'رفع الطلبات ومتابعتها قبل المحكمة العمالية', 'https://qiwa.sa', 'handshake'],
    ['المحاكم العمالية — عبر ناجز', 'الدعاوى العمالية', 'https://najiz.sa', 'scale-balanced'],
    ['التأمينات الاجتماعية (GOSI)', 'الاشتراكات والمنازعات التأمينية', 'https://gosi.gov.sa', 'shield'],
    ['مكتب العمل — التحقق من منشأة', 'نطاقات والالتزام العمالي', 'https://qiwa.sa', 'building-user'],
  ],
  'العقار والأراضي' => [
    ['الهيئة العامة للعقار', 'تنظيم القطاع العقاري ووساطته', 'https://rega.gov.sa', 'house'],
    ['إيجار — الشبكة الإلكترونية للإيجار', 'توثيق عقود الإيجار وتنفيذها', 'https://www.ejar.sa', 'file-contract'],
    ['السجل العقاري (وزارة العدل)', 'قيد الملكية والرهون العقارية', 'https://najiz.sa', 'map-location-dot'],
    ['وزارة الشؤون البلدية والقروية والإسكان', 'رخص البناء والاشتراطات', 'https://momah.gov.sa', 'city'],
    ['صندوق التنمية العقارية / سكني', 'التمويل والدعم السكني', 'https://sakani.sa', 'key'],
    ['نزاعات — لجان الفصل في مخالفات الإيجار', 'عبر منصة إيجار والمحاكم', 'https://www.ejar.sa', 'gavel'],
  ],
  'الأحوال والهوية والتوثيق' => [
    ['أبشر أفراد', 'الهوية الوطنية، الوكالات، التفويض', 'https://absher.sa', 'address-card'],
    ['أبشر أعمال', 'خدمات المنشآت والتفويض الإلكتروني', 'https://absher.sa', 'building-user'],
    ['نفاذ الوطني الموحّد', 'الدخول الموحّد لكل الخدمات الحكومية', 'https://www.iam.gov.sa', 'fingerprint'],
    ['المديرية العامة للأحوال المدنية', 'السجل المدني والوثائق', 'https://www.moi.gov.sa', 'id-card'],
    ['المركز الوطني للمعلومات', 'التحقق من الوثائق والبيانات', 'https://nic.gov.sa', 'database'],
    ['توكلنا خدمات', 'الوثائق الرقمية والتفاويض', 'https://ta.sa', 'mobile-screen'],
  ],
  'الحقوق والرقابة والشفافية' => [
    ['هيئة حقوق الإنسان', 'البلاغات والشكاوى الحقوقية', 'https://hrc.gov.sa', 'hand-holding-heart'],
    ['نزاهة — هيئة الرقابة ومكافحة الفساد', 'بلاغات الفساد المالي والإداري', 'https://nazaha.gov.sa', 'shield-halved'],
    ['الديوان العام للمحاسبة', 'الرقابة المالية على الجهات الحكومية', 'https://gab.gov.sa', 'magnifying-glass-dollar'],
    ['هيئة الرقابة الشرعية / الإفتاء', 'الفتاوى والاستشارات الشرعية', 'https://www.alifta.gov.sa', 'book-quran'],
    ['منصة "بلاغ تجاري"', 'الإبلاغ عن مخالفات تجارية وغش', 'https://mc.gov.sa', 'triangle-exclamation'],
  ],
  'المرور والتأمين والمنازعات المالية' => [
    ['الإدارة العامة للمرور (أبشر)', 'الحوادث، المخالفات، الاعتراضات المرورية', 'https://absher.sa', 'car'],
    ['نجم لخدمات التأمين', 'مطالبات ومنازعات تأمين المركبات', 'https://najm.sa', 'car-burst'],
    ['البنك المركزي السعودي (ساما)', 'أنظمة البنوك والتمويل والتأمين', 'https://sama.gov.sa', 'building-columns'],
    ['لجان المنازعات المصرفية والتمويلية', 'الدعاوى ضد البنوك وشركات التمويل', 'https://sama.gov.sa', 'scale-balanced'],
    ['اللجنة المصرفية / لجنة الأوراق المالية', 'منازعات الاستثمار والأوراق المالية', 'https://cma.org.sa', 'chart-line'],
    ['التأمينات — لجان تسوية المنازعات', 'المنازعات التأمينية', 'https://ccsd.sama.gov.sa', 'file-shield'],
  ],
  'الملكية الفكرية والبيانات' => [
    ['الهيئة السعودية للملكية الفكرية (SAIP)', 'العلامات التجارية، البراءات، حقوق المؤلف', 'https://saip.gov.sa', 'lightbulb'],
    ['التحقق من علامة تجارية', 'البحث في سجل العلامات التجارية', 'https://saip.gov.sa', 'trademark'],
    ['الهيئة السعودية للبيانات والذكاء الاصطناعي (سدايا)', 'نظام حماية البيانات الشخصية (PDPL)', 'https://sdaia.gov.sa', 'database'],
    ['الهيئة الوطنية للأمن السيبراني', 'الأنظمة والضوابط السيبرانية', 'https://nca.gov.sa', 'lock'],
  ],
  'قواعد الأحكام والبحث القانوني' => [
    ['منصة "علم" لنشر الأحكام القضائية', 'مدونة الأحكام القضائية الصادرة عن وزارة العدل', 'https://sjp.moj.gov.sa', 'book-open'],
    ['المدونة القضائية (وزارة العدل)', 'التصنيف الموضوعي للأحكام والمبادئ', 'https://sjp.moj.gov.sa', 'folder-tree'],
    ['بوابة الأنظمة (هيئة الخبراء)', 'محرك بحث الأنظمة واللوائح', 'https://laws.boe.gov.sa', 'magnifying-glass'],
    ['المركز الوطني للوثائق — الجريدة الرسمية', 'أرشيف أم القرى', 'https://uqn.gov.sa', 'newspaper'],
    ['مجلس الشورى', 'مشاريع الأنظمة قيد الدراسة', 'https://shura.gov.sa', 'landmark'],
  ],
];

$palette = ['#0c4a6e','#166534','#7c3aed','#b45309','#be123c','#0891b2','#1e3a5f','#4d7c0f','#a21caf','#9f1239','#334155'];
?>

<div class="alert alert-info d-flex align-items-center gap-2 mb-3">
  <i class="fas fa-scale-balanced fa-lg"></i>
  <div>دليل شامل لروابط الجهات والخدمات الحكومية القانونية في المملكة — مصنّفة حسب القطاع. الروابط تُفتح في نافذة جديدة.</div>
</div>

<div class="d-flex flex-wrap gap-2 mb-4" id="svcNav">
  <?php $gi = 0; foreach ($groups as $gname => $_): ?>
  <a href="#g<?= $gi ?>" class="btn btn-sm btn-outline-secondary"><?= e($gname) ?></a>
  <?php $gi++; endforeach; ?>
</div>

<?php $gi = 0; foreach ($groups as $gname => $items): $color = $palette[$gi % count($palette)]; ?>
<div id="g<?= $gi ?>" class="mb-4" style="scroll-margin-top:80px">
  <h5 class="fw-bold mb-3" style="color:<?= $color ?>">
    <i class="fas fa-angle-left me-1"></i><?= e($gname) ?>
    <span class="badge rounded-pill ms-1" style="background:<?= $color ?>"><?= count($items) ?></span>
  </h5>
  <div class="row g-3">
    <?php foreach ($items as $s): ?>
    <div class="col-md-6 col-lg-4">
      <a href="<?= e($s[2]) ?>" target="_blank" rel="noopener noreferrer"
         class="card h-100 text-decoration-none" style="border-right:4px solid <?= $color ?>">
        <div class="card-body d-flex align-items-start gap-3">
          <div class="rounded d-flex align-items-center justify-content-center text-white flex-shrink-0"
               style="width:42px;height:42px;font-size:16px;background:<?= $color ?>">
            <i class="fas fa-<?= e($s[3]) ?>"></i>
          </div>
          <div style="min-width:0">
            <div class="fw-bold" style="font-size:13.5px;color:#0f172a"><?= e($s[0]) ?></div>
            <div class="text-muted mt-1" style="font-size:11.5px;line-height:1.6"><?= e($s[1]) ?></div>
            <div style="font-size:10.5px;color:<?= $color ?>;margin-top:5px" class="text-truncate">
              <i class="fas fa-external-link-alt me-1"></i><?= e(preg_replace('~^https?://~', '', $s[2])) ?>
            </div>
          </div>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php $gi++; endforeach; ?>

<p class="text-muted text-center mt-4" style="font-size:11.5px">
  الروابط لجهات حكومية سعودية وقد تتغيّر عناوينها. لا تتحمّل المنصة مسؤولية محتوى المواقع الخارجية.
</p>

<?php include '../includes/office_footer.php'; ?>
