<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireAdmin();
$page_title = 'المحتوى التعريفي للموقع';

// ── دوال مساعدة ──
function getContent($conn, $key, $default = '') {
    $k   = $conn->real_escape_string($key);
    $res = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='$k' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) return $row['setting_value'];
    return $default;
}
function saveContent($conn, $key, $value) {
    $k = $conn->real_escape_string($key);
    $v = $conn->real_escape_string($value);
    $conn->query("INSERT INTO site_content (setting_key, setting_value)
                  VALUES ('$k','$v')
                  ON DUPLICATE KEY UPDATE setting_value='$v'");
}

$msg = '';

// ── إنشاء الجدول دائماً ──
$conn->query("CREATE TABLE IF NOT EXISTS site_content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value LONGTEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// ── معالجة الحفظ ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys_to_save = [
        // General
        'site_name','site_desc','hero_pill',
        // Hero
        'hero_title','hero_subtitle','hero_text','btn_register','btn_learn',
        // How it works
        'how1_title','how1_desc','how2_title','how2_desc',
        'how3_title','how3_desc','how4_title','how4_desc',
        // Features
        'feat_title',
        'feat_title_0','feat_desc_0','feat_title_1','feat_desc_1',
        'feat_title_2','feat_desc_2','feat_title_3','feat_desc_3',
        'feat_title_4','feat_desc_4','feat_title_5','feat_desc_5',
        // Testimonials
        'test1_av','test1_name','test1_role','test1_text',
        'test2_av','test2_name','test2_role','test2_text',
        'test3_av','test3_name','test3_role','test3_text',
        // About
        'about_story_1','about_story_2',
        'val1_icon','val1_title','val1_desc',
        'val2_icon','val2_title','val2_desc',
        'val3_icon','val3_title','val3_desc',
        'val4_icon','val4_title','val4_desc',
        // Contact
        'contact_email','contact_phone','contact_address','twitter','linkedin','whatsapp',
        // FAQ
        'faq_q_0','faq_a_0','faq_q_1','faq_a_1',
        'faq_q_2','faq_a_2','faq_q_3','faq_a_3',
        // Legal
        'legal_privacy','legal_terms','legal_refund','legal_cookies',
        'legal_privacy_date','legal_terms_date','legal_refund_date','legal_cookies_date',
    ];
    foreach ($keys_to_save as $key) {
        if (isset($_POST[$key])) saveContent($conn, $key, $_POST[$key]);
    }
    $msg = 'تم حفظ المحتوى بنجاح وسيظهر على الموقع فوراً';
}

// ── جلب كل القيم ──
$c = [];
$res = $conn->query("SELECT setting_key, setting_value FROM site_content");
if ($res) while ($row = $res->fetch_assoc()) $c[$row['setting_key']] = $row['setting_value'];
function cv($c, $key, $default = '') {
    return isset($c[$key]) && $c[$key] !== '' ? $c[$key] : $default;
}

include '../includes/admin_header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i><?= e($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h5 class="fw-bold mb-1" style="color:#0c1b36">إدارة المحتوى التعريفي</h5>
    <small class="text-muted">ما تحفظه يظهر فوراً على الموقع العام</small>
  </div>
  <div class="d-flex gap-2">
    <a href="../public/home.php" target="_blank" class="btn btn-outline-secondary btn-sm">
      <i class="fas fa-external-link-alt me-1"></i>معاينة الموقع
    </a>
    <button type="submit" form="contentForm" class="btn btn-sm text-white fw-bold px-4" id="saveBtn"
            style="background:linear-gradient(135deg,#0c1b36,#1a3a6e);border:none;border-radius:8px">
      <i class="fas fa-save me-1"></i>حفظ المحتوى
    </button>
  </div>
</div>

<form method="POST" id="contentForm">

<!-- ── التبويبات ── -->
<div style="display:flex;gap:4px;padding:6px;background:#f1f5f9;border-radius:14px;margin-bottom:24px;overflow-x:auto;flex-wrap:nowrap">
  <?php
  $tabs = [
    ['general',      'cog',            'الإعدادات',       true],
    ['hero',         'house',          'الرئيسية',        false],
    ['features',     'star',           'المزايا',         false],
    ['testimonials', 'quote-right',    'الشهادات',        false],
    ['about',        'info-circle',    'من نحن',          false],
    ['contact',      'phone',          'تواصل معنا',      false],
    ['faq',          'question-circle','الأسئلة الشائعة', false],
    ['legal',        'balance-scale',  'القانونية',       false],
  ];
  foreach ($tabs as [$tid,$tico,$tlbl,$tact]):
  ?>
  <a href="#<?= $tid ?>"
     class="content-tab <?= $tact?'active':'' ?>"
     style="display:flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:600;white-space:nowrap;text-decoration:none;transition:all .2s;
            <?= $tact ? 'background:#fff;color:#0c1b36;box-shadow:0 1px 6px rgba(0,0,0,.1)' : 'color:#64748b' ?>">
    <i class="fas fa-<?= $tico ?>" style="font-size:12px"></i><?= $tlbl ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="tab-content">

<!-- ═══ GENERAL ═══ -->
<div class="tab-pane fade show active" id="general">
  <div class="card" style="border:none;box-shadow:0 2px 16px rgba(12,27,54,.07)">
    <div class="card-header" style="background:linear-gradient(135deg,#1e293b,#334155);border:none;padding:16px 22px;border-radius:12px 12px 0 0">
      <div class="d-flex align-items-center gap-3">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(148,163,184,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-cog" style="color:#cbd5e1;font-size:14px"></i>
        </div>
        <div>
          <div style="color:#fff;font-weight:700;font-size:14px">الإعدادات العامة للموقع</div>
          <div style="color:rgba(255,255,255,.5);font-size:11px">اسم المنصة ووصفها — تظهر في العنوان وصفحات البحث</div>
        </div>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold">اسم المنصة</label>
          <input type="text" name="site_name" class="form-control"
                 value="<?= e(cv($c,'site_name','مِحكام')) ?>">
          <div class="form-text">يظهر في الشعار، العنوان، وكل صفحات الموقع</div>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">وصف المنصة</label>
          <input type="text" name="site_desc" class="form-control"
                 value="<?= e(cv($c,'site_desc','نظام إدارة مكاتب المحاماة')) ?>">
          <div class="form-text">يظهر في محركات البحث وبطاقات المشاركة</div>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">نص الشارة (Badge) في Hero</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-tag"></i></span>
            <input type="text" name="hero_pill" class="form-control"
                   value="<?= e(cv($c,'hero_pill','الأكثر استخداماً في مكاتب المحاماة السعودية')) ?>">
          </div>
          <div class="form-text">النص الصغير الذي يظهر فوق العنوان الرئيسي في الصفحة الرئيسية</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ HERO ═══ -->
<div class="tab-pane fade" id="hero">
  <!-- Hero content -->
  <div class="card mb-3" style="border:none;box-shadow:0 2px 16px rgba(12,27,54,.07)">
    <div class="card-header" style="background:linear-gradient(135deg,#0c1b36,#1a3a6e);border:none;padding:16px 22px;border-radius:12px 12px 0 0">
      <div class="d-flex align-items-center gap-3">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(232,192,64,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-house" style="color:#e8c040;font-size:14px"></i>
        </div>
        <div>
          <div style="color:#fff;font-weight:700;font-size:14px">قسم Hero</div>
          <div style="color:rgba(255,255,255,.5);font-size:11px">العنوان الرئيسي والنص وأزرار الدعوة</div>
        </div>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold">العنوان الرئيسي</label>
          <input type="text" name="hero_title" class="form-control"
                 value="<?= e(cv($c,'hero_title','أدِر مكتبك القانوني')) ?>">
          <div class="form-text">السطر الأول — الجملة الثانية مع التمييز الذهبي ثابتة في الكود</div>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">العنوان الفرعي</label>
          <input type="text" name="hero_subtitle" class="form-control"
                 value="<?= e(cv($c,'hero_subtitle','منصة متكاملة لإدارة القضايا والعملاء والشؤون المالية')) ?>">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">النص التعريفي</label>
          <textarea name="hero_text" class="form-control" rows="2"><?= e(cv($c,'hero_text','منصة متكاملة لإدارة القضايا والعملاء والشؤون المالية — مصممة خصيصاً للمحامين في المملكة.')) ?></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">نص زر التسجيل</label>
          <input type="text" name="btn_register" class="form-control"
                 value="<?= e(cv($c,'btn_register','ابدأ مجاناً — 14 يوم')) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">نص زر التعرف</label>
          <input type="text" name="btn_learn" class="form-control"
                 value="<?= e(cv($c,'btn_learn','شاهد كيف يعمل')) ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- How it works -->
  <div class="card" style="border:none;box-shadow:0 2px 16px rgba(12,27,54,.07)">
    <div class="card-header" style="background:linear-gradient(135deg,#0c1b36,#1a3a6e);border:none;padding:16px 22px;border-radius:12px 12px 0 0">
      <div class="d-flex align-items-center gap-3">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(232,192,64,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-list-ol" style="color:#e8c040;font-size:14px"></i>
        </div>
        <div>
          <div style="color:#fff;font-weight:700;font-size:14px">قسم «كيف يعمل»</div>
          <div style="color:rgba(255,255,255,.5);font-size:11px">الخطوات الأربع الظاهرة في الصفحة الرئيسية</div>
        </div>
      </div>
    </div>
    <div class="card-body">
      <?php
      $how_defaults = [
        ['سجّل مكتبك مجاناً', 'أدخل بيانات مكتبك وابدأ النسخة التجريبية 14 يوماً بدون بطاقة ائتمان'],
        ['أضف قضاياك وعملاءك', 'استورد بياناتك الحالية أو أضفها يدوياً بواجهة عربية كاملة'],
        ['أدر ونظّم وتتبع', 'استخدم لوحة التحكم الذكية وتلقّ تنبيهات تلقائية للجلسات'],
        ['اشترك واستمر', 'اختر الباقة المناسبة لمكتبك واستمر بكامل الميزات'],
      ];
      foreach ($how_defaults as $i => [$def_t, $def_d]):
        $n = $i + 1;
      ?>
      <div class="card bg-light border mb-2">
        <div class="card-body py-2">
          <div class="row g-2 align-items-center">
            <div class="col-auto">
              <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold" style="width:36px;height:36px;font-size:14px"><?= $n ?></div>
            </div>
            <div class="col">
              <input type="text" name="how<?= $n ?>_title" class="form-control form-control-sm mb-1"
                     placeholder="عنوان الخطوة"
                     value="<?= e(cv($c, "how{$n}_title", $def_t)) ?>">
              <input type="text" name="how<?= $n ?>_desc" class="form-control form-control-sm"
                     placeholder="وصف الخطوة"
                     value="<?= e(cv($c, "how{$n}_desc", $def_d)) ?>">
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══ FEATURES ═══ -->
<div class="tab-pane fade" id="features">
  <div class="card" style="border:none;box-shadow:0 2px 16px rgba(12,27,54,.07)">
    <div class="card-header" style="background:linear-gradient(135deg,#78350f,#b45309);border:none;padding:16px 22px;border-radius:12px 12px 0 0">
      <div class="d-flex align-items-center gap-3">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(245,158,11,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-star" style="color:#fbbf24;font-size:14px"></i>
        </div>
        <div>
          <div style="color:#fff;font-weight:700;font-size:14px">قسم المزايا</div>
          <div style="color:rgba(255,255,255,.5);font-size:11px">عنوان القسم والمزايا الستة</div>
        </div>
      </div>
    </div>
    <div class="card-body">
      <div class="mb-4">
        <label class="form-label fw-semibold">عنوان القسم</label>
        <input type="text" name="feat_title" class="form-control"
               value="<?= e(cv($c,'feat_title','كل ما يحتاجه مكتبك')) ?>">
      </div>
      <?php
      $feat_defaults = [
        ['إدارة القضايا',       'تتبع شامل لجميع قضاياك مع حالة كل قضية وتواريخ الجلسات القادمة', 'gavel'],
        ['ملفات العملاء',       'سجل شامل لكل عميل مع كامل قضاياه ومراسلاته وعقوده',              'address-book'],
        ['الشؤون المالية',      'تتبع الأتعاب والمدفوعات وأصدر تقارير مالية دقيقة بلحظة واحدة',   'wallet'],
        ['العقود والوكالات',    'إنشاء وإدارة العقود مع تنبيهات انتهاء الصلاحية تلقائياً',         'file-signature'],
        ['المهام والمواعيد',    'نظام متكامل لإدارة المهام مع تذكيرات ذكية تلقائية للجلسات',       'tasks'],
        ['المساعد الذكي AI',    'مساعد قانوني بالذكاء الاصطناعي متخصص في الأنظمة السعودية',        'robot'],
      ];
      foreach ($feat_defaults as $i => [$def_title, $def_desc, $icon]):
      ?>
      <div class="card bg-light border mb-2">
        <div class="card-body py-2">
          <div class="row g-2 align-items-center">
            <div class="col-auto">
              <div class="rounded-circle bg-warning bg-opacity-10 text-warning d-flex align-items-center justify-content-center" style="width:36px;height:36px">
                <i class="fas fa-<?= $icon ?>"></i>
              </div>
            </div>
            <div class="col">
              <input type="text" name="feat_title_<?= $i ?>" class="form-control form-control-sm mb-1"
                     placeholder="عنوان الميزة"
                     value="<?= e(cv($c,"feat_title_$i", $def_title)) ?>">
              <input type="text" name="feat_desc_<?= $i ?>" class="form-control form-control-sm"
                     placeholder="وصف الميزة"
                     value="<?= e(cv($c,"feat_desc_$i", $def_desc)) ?>">
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══ TESTIMONIALS ═══ -->
<div class="tab-pane fade" id="testimonials">
  <div class="card" style="border:none;box-shadow:0 2px 16px rgba(12,27,54,.07)">
    <div class="card-header" style="background:linear-gradient(135deg,#134e4a,#0d9488);border:none;padding:16px 22px;border-radius:12px 12px 0 0">
      <div class="d-flex align-items-center gap-3">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(20,184,166,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-quote-right" style="color:#5eead4;font-size:14px"></i>
        </div>
        <div>
          <div style="color:#fff;font-weight:700;font-size:14px">آراء العملاء</div>
          <div style="color:rgba(255,255,255,.5);font-size:11px">3 شهادات تظهر في الصفحة الرئيسية وصفحة من نحن</div>
        </div>
      </div>
    </div>
    <div class="card-body">
      <?php
      $test_defaults = [
        ['أ', 'أحمد الشمري',  'محامي — الرياض',        'غيّرت المنصة طريقة إدارة مكتبي تماماً. لم أعد أقلق بشأن مواعيد الجلسات أو تتبع الأتعاب.'],
        ['ف', 'فهد النجدي',   'مستشار قانوني — جدة',   'التقارير المالية وحدها تستحق الاشتراك! أصبحت أعرف وضع مكتبي المالي بدقة تامة في ثوانٍ.'],
        ['م', 'منى العمري',   'محامية — الدمام',        'المكتبة القانونية المدمجة رائعة. أجد ما أحتاجه من أنظمة ونماذج في لحظات.'],
      ];
      foreach ($test_defaults as $i => [$def_av, $def_name, $def_role, $def_text]):
        $n = $i + 1;
      ?>
      <div class="card border mb-3">
        <div class="card-body">
          <div class="d-flex align-items-center gap-2 mb-3">
            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold" style="width:36px;height:36px;font-size:14px">
              <?= e(cv($c, "test{$n}_av", $def_av)) ?>
            </div>
            <span class="badge bg-primary rounded-pill"><?= $n ?></span>
            <small class="text-muted fw-semibold">شهادة عميل</small>
          </div>
          <div class="row g-2">
            <div class="col-auto" style="width:70px">
              <label class="form-label fw-semibold" style="font-size:11px">الحرف</label>
              <input type="text" name="test<?= $n ?>_av" class="form-control form-control-sm text-center fw-bold"
                     maxlength="2"
                     value="<?= e(cv($c, "test{$n}_av", $def_av)) ?>">
            </div>
            <div class="col">
              <label class="form-label fw-semibold" style="font-size:11px">الاسم</label>
              <input type="text" name="test<?= $n ?>_name" class="form-control form-control-sm"
                     value="<?= e(cv($c, "test{$n}_name", $def_name)) ?>">
            </div>
            <div class="col">
              <label class="form-label fw-semibold" style="font-size:11px">الدور / المدينة</label>
              <input type="text" name="test<?= $n ?>_role" class="form-control form-control-sm"
                     value="<?= e(cv($c, "test{$n}_role", $def_role)) ?>">
            </div>
          </div>
          <div class="mt-2">
            <label class="form-label fw-semibold" style="font-size:11px">نص الشهادة</label>
            <textarea name="test<?= $n ?>_text" class="form-control form-control-sm" rows="2"
                      placeholder="نص الشهادة..."><?= e(cv($c, "test{$n}_text", $def_text)) ?></textarea>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══ ABOUT ═══ -->
<div class="tab-pane fade" id="about">
  <!-- قصتنا -->
  <div class="card mb-3" style="border:none;box-shadow:0 2px 16px rgba(12,27,54,.07)">
    <div class="card-header" style="background:linear-gradient(135deg,#0e7490,#0891b2);border:none;padding:16px 22px;border-radius:12px 12px 0 0">
      <div class="d-flex align-items-center gap-3">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(6,182,212,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-book-open" style="color:#67e8f9;font-size:14px"></i>
        </div>
        <div>
          <div style="color:#fff;font-weight:700;font-size:14px">قصتنا</div>
          <div style="color:rgba(255,255,255,.5);font-size:11px">الفقرتان النصيتان في قسم «لماذا أسّسنا»</div>
        </div>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold">الفقرة الأولى</label>
          <textarea name="about_story_1" class="form-control" rows="3"><?= e(cv($c,'about_story_1','في عام 2022، لاحظنا أن غالبية مكاتب المحاماة لا تزال تعتمد على الأوراق وجداول Excel — ما يُضيّع وقتاً ثميناً ويُعرّض المعلومات للضياع.')) ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">الفقرة الثانية</label>
          <textarea name="about_story_2" class="form-control" rows="3"><?= e(cv($c,'about_story_2','قررنا بناء حل رقمي متكامل، مصمم خصيصاً للبيئة القانونية السعودية، يجمع بين سهولة الاستخدام والميزات الاحترافية.')) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <!-- بطاقات القيم -->
  <div class="card" style="border:none;box-shadow:0 2px 16px rgba(12,27,54,.07)">
    <div class="card-header" style="background:linear-gradient(135deg,#0e7490,#0891b2);border:none;padding:16px 22px;border-radius:12px 12px 0 0">
      <div class="d-flex align-items-center gap-3">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(6,182,212,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-heart" style="color:#67e8f9;font-size:14px"></i>
        </div>
        <div>
          <div style="color:#fff;font-weight:700;font-size:14px">بطاقات القيم</div>
          <div style="color:rgba(255,255,255,.5);font-size:11px">4 بطاقات في صفحة من نحن — أيقونة FontAwesome</div>
        </div>
      </div>
    </div>
    <div class="card-body">
      <?php
      $val_defaults = [
        ['shield-alt', 'الأمان أولاً',      'بياناتك محمية بأعلى معايير التشفير'],
        ['heart',       'نهتم بعملائنا',     'فريق دعم حقيقي يستمع ويساعد دائماً'],
        ['rocket',      'نتطور باستمرار',    'تحديثات شهرية بناءً على احتياجاتك'],
        ['handshake',   'شفافية كاملة',      'لا رسوم خفية ولا مفاجآت أبداً'],
      ];
      foreach ($val_defaults as $i => [$def_icon, $def_title, $def_desc]):
        $n = $i + 1;
      ?>
      <div class="card bg-light border mb-2">
        <div class="card-body py-2">
          <div class="row g-2 align-items-center">
            <div class="col-auto">
              <div class="rounded-circle bg-info bg-opacity-10 text-info d-flex align-items-center justify-content-center" style="width:36px;height:36px">
                <i class="fas fa-<?= e(cv($c,"val{$n}_icon",$def_icon)) ?>"></i>
              </div>
            </div>
            <div class="col-auto" style="width:140px">
              <label style="font-size:10px;color:#64748b;margin-bottom:2px">أيقونة FA</label>
              <input type="text" name="val<?= $n ?>_icon" class="form-control form-control-sm"
                     placeholder="shield-alt"
                     value="<?= e(cv($c,"val{$n}_icon",$def_icon)) ?>">
            </div>
            <div class="col">
              <input type="text" name="val<?= $n ?>_title" class="form-control form-control-sm mb-1"
                     placeholder="العنوان"
                     value="<?= e(cv($c,"val{$n}_title",$def_title)) ?>">
              <input type="text" name="val<?= $n ?>_desc" class="form-control form-control-sm"
                     placeholder="الوصف"
                     value="<?= e(cv($c,"val{$n}_desc",$def_desc)) ?>">
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══ CONTACT ═══ -->
<div class="tab-pane fade" id="contact">
  <div class="card" style="border:none;box-shadow:0 2px 16px rgba(12,27,54,.07)">
    <div class="card-header" style="background:linear-gradient(135deg,#065f46,#059669);border:none;padding:16px 22px;border-radius:12px 12px 0 0">
      <div class="d-flex align-items-center gap-3">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(16,185,129,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-phone" style="color:#6ee7b7;font-size:14px"></i>
        </div>
        <div>
          <div style="color:#fff;font-weight:700;font-size:14px">بيانات التواصل</div>
          <div style="color:rgba(255,255,255,.5);font-size:11px">البريد والهاتف والعنوان وروابط التواصل الاجتماعي</div>
        </div>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold">البريد الإلكتروني</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
            <input type="email" name="contact_email" class="form-control"
                   value="<?= e(cv($c,'contact_email','info@mehkam.net')) ?>">
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">رقم الهاتف</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-phone"></i></span>
            <input type="text" name="contact_phone" class="form-control"
                   value="<?= e(cv($c,'contact_phone','0553302173')) ?>">
          </div>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">العنوان</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
            <input type="text" name="contact_address" class="form-control"
                   value="<?= e(cv($c,'contact_address','الرياض، المملكة العربية السعودية')) ?>">
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold"><i class="fab fa-twitter me-1 text-info"></i>تويتر (X)</label>
          <input type="text" name="twitter" class="form-control"
                 value="<?= e(cv($c,'twitter','#')) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold"><i class="fab fa-linkedin me-1 text-primary"></i>لينكدإن</label>
          <input type="text" name="linkedin" class="form-control"
                 value="<?= e(cv($c,'linkedin','#')) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold"><i class="fab fa-whatsapp me-1 text-success"></i>واتساب</label>
          <input type="text" name="whatsapp" class="form-control"
                 value="<?= e(cv($c,'whatsapp','#')) ?>">
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ FAQ ═══ -->
<div class="tab-pane fade" id="faq">
  <div class="card" style="border:none;box-shadow:0 2px 16px rgba(12,27,54,.07)">
    <div class="card-header" style="background:linear-gradient(135deg,#581c87,#7c3aed);border:none;padding:16px 22px;border-radius:12px 12px 0 0">
      <div class="d-flex align-items-center gap-3">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(167,139,250,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-question-circle" style="color:#c4b5fd;font-size:14px"></i>
        </div>
        <div>
          <div style="color:#fff;font-weight:700;font-size:14px">الأسئلة الشائعة</div>
          <div style="color:rgba(255,255,255,.5);font-size:11px">4 أسئلة وأجوبة تظهر في صفحة FAQ</div>
        </div>
      </div>
    </div>
    <div class="card-body">
      <?php
      $faq_defaults = [
        ['هل يوجد نسخة تجريبية مجانية؟',          'نعم، نوفر نسخة تجريبية مجانية لمدة 14 يوم بدون الحاجة لبطاقة ائتمان.'],
        ['هل بياناتي آمنة؟',                        'نعم، نستخدم أعلى معايير التشفير وحماية البيانات.'],
        ['كيف يمكنني الترقية إلى باقة أعلى؟',      'يمكنك الترقية في أي وقت من خلال لوحة التحكم.'],
        ['هل يدعم النظام اللغة العربية؟',           'نعم، النظام مبني بالكامل باللغة العربية ويدعم الاتجاه من اليمين لليسار.'],
      ];
      foreach ($faq_defaults as $i => [$def_q, $def_a]):
      ?>
      <div class="card border mb-2">
        <div class="card-body py-2">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge bg-primary rounded-pill"><?= $i+1 ?></span>
            <small class="text-muted fw-semibold">سؤال وجواب</small>
          </div>
          <input type="text" name="faq_q_<?= $i ?>" class="form-control form-control-sm mb-2"
                 placeholder="السؤال"
                 value="<?= e(cv($c,"faq_q_$i", $def_q)) ?>">
          <textarea name="faq_a_<?= $i ?>" class="form-control form-control-sm" rows="2"
                    placeholder="الجواب"><?= e(cv($c,"faq_a_$i", $def_a)) ?></textarea>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══ LEGAL ═══ -->
<div class="tab-pane fade" id="legal" style="display:none">
  <?php
  $legal_pages = [
    ['privacy', 'shield-alt',     'سياسة الخصوصية',  '#581c87', 'rgba(167,139,250,.2)', '#c4b5fd',
      "نلتزم بحماية خصوصيتك وأمان بياناتك. نجمع فقط البيانات الضرورية لتقديم الخدمة وتحسينها، ولا نبيعها لأي طرف ثالث.\n\nنستخدم بياناتك لـ: تشغيل المنصة وتقديم الخدمات، إرسال إشعارات الحساب، تحسين تجربة الاستخدام، الامتثال للمتطلبات القانونية.\n\nيحق لك في أي وقت: الاطلاع على بياناتك، تصحيحها، طلب حذفها، أو تصديرها.\n\nنطبق أعلى معايير الأمان بما يشمل تشفير SSL، نسخ احتياطية يومية، ومراقبة أمنية مستمرة."],
    ['terms',   'file-contract',  'شروط الاستخدام',  '#065f46', 'rgba(16,185,129,.2)',  '#6ee7b7',
      "باستخدامك للمنصة فإنك توافق على الالتزام بهذه الشروط والأحكام.\n\nالاستخدام المقبول: إدارة قضايا المحاماة وملفات العملاء، تتبع الجلسات والمواعيد، إدارة الشؤون المالية للمكتب.\n\nالاستخدامات المحظورة: أي استخدام غير قانوني أو مخالف للأنظمة السعودية، محاولة اختراق الأنظمة، مشاركة بيانات الحساب مع غير المصرح لهم.\n\nجميع حقوق الملكية الفكرية للمنصة محفوظة. بياناتك التي تدخلها تبقى ملكاً لك."],
    ['refund',  'money-bill-wave','سياسة الاسترداد', '#1e3a5f', 'rgba(37,99,235,.2)',   '#93c5fd',
      "نلتزم بضمان رضاك التام. إذا لم تكن راضياً عن خدمتنا خلال أول 14 يوماً، نسترد لك مبلغك كاملاً دون أي أسئلة.\n\nشروط الاسترداد بعد 14 يوماً:\n- الاشتراكات الشهرية: لا يُسترد رسوم الشهر الحالي، لكن يُلغى التجديد التلقائي فوراً.\n- الاشتراكات السنوية: يمكن استرداد الأشهر غير المستخدمة في حال وجود عطل جوهري."],
    ['cookies', 'cookie-bite',    'سياسة الكوكيز',   '#78350f', 'rgba(245,158,11,.2)',  '#fbbf24',
      "نستخدم ملفات تعريف الارتباط (Cookies) لتحسين تجربتك وضمان عمل المنصة بشكل صحيح.\n\nأنواع الكوكيز:\n- ضرورية: للحفاظ على جلسة تسجيل الدخول وأمان الموقع (مدتها: انتهاء الجلسة).\n- وظيفية: لتذكر إعداداتك وتفضيلاتك (مدتها: سنة).\n- تحليلية: لفهم كيفية الاستخدام وتحسين المنصة (مدتها: سنتان)."],
  ];
  foreach ($legal_pages as [$key, $icon, $label, $color, $iconBg, $iconColor, $default]):
  ?>
  <div class="card mb-3" style="border:none;box-shadow:0 2px 16px rgba(12,27,54,.07)">
    <div class="card-header" style="background:linear-gradient(135deg,<?= $color ?>,<?= $color ?>dd);border:none;padding:14px 20px;border-radius:12px 12px 0 0">
      <div class="d-flex align-items-center gap-3">
        <div style="width:34px;height:34px;border-radius:8px;background:<?= $iconBg ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fas fa-<?= $icon ?>" style="color:<?= $iconColor ?>;font-size:13px"></i>
        </div>
        <div>
          <div style="color:#fff;font-weight:700;font-size:14px"><?= $label ?></div>
          <div style="color:rgba(255,255,255,.5);font-size:11px">
            <a href="../public/<?= $key ?>.php" target="_blank" style="color:rgba(255,255,255,.6);text-decoration:underline">
              معاينة الصفحة <i class="fas fa-external-link-alt ms-1" style="font-size:9px"></i>
            </a>
          </div>
        </div>
        <div class="me-auto d-flex align-items-center gap-2">
          <label style="color:rgba(255,255,255,.7);font-size:11px;white-space:nowrap">آخر تحديث:</label>
          <input type="date" name="legal_<?= $key ?>_date" class="form-control form-control-sm"
                 style="width:140px;font-size:12px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#fff"
                 value="<?= e(cv($c, "legal_{$key}_date", date('Y-m-d'))) ?>">
        </div>
      </div>
    </div>
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <label class="form-label fw-semibold mb-0">محتوى الصفحة</label>
        <small class="text-muted">يدعم السطور الجديدة (Enter)</small>
      </div>
      <textarea name="legal_<?= $key ?>" class="form-control font-monospace"
                rows="12" style="font-size:12px;line-height:1.6;resize:vertical"
                placeholder="أدخل محتوى <?= $label ?> هنا..."><?= e(cv($c, "legal_$key", $default)) ?></textarea>
    </div>
  </div>
  <?php endforeach; ?>
</div>

</div><!-- /tab-content -->

<div class="mt-4 d-flex gap-2 align-items-center justify-content-between">
  <small class="text-muted">
    <i class="fas fa-clock me-1"></i>آخر تحديث:
    <?php
    $last = $conn->query("SELECT MAX(updated_at) mx FROM site_content");
    $lrow = $last ? $last->fetch_assoc() : null;
    echo $lrow && $lrow['mx'] ? date('Y/m/d H:i', strtotime($lrow['mx'])) : 'لم يتم الحفظ بعد';
    ?>
  </small>
  <button type="submit" class="btn text-white fw-bold px-5" id="saveBtnBottom"
          style="background:linear-gradient(135deg,#0c1b36,#1a3a6e);border:none;border-radius:10px">
    <i class="fas fa-save me-2"></i>حفظ المحتوى
  </button>
</div>

</form>

<script>
function switchTab(targetId) {
  document.querySelectorAll('.tab-pane').forEach(function(p) {
    p.classList.remove('show', 'active');
    p.style.display = 'none';
  });
  var target = document.getElementById(targetId);
  if (target) {
    target.style.display = 'block';
    target.classList.add('show', 'active');
  }
  document.querySelectorAll('.content-tab').forEach(function(t) {
    var isActive = t.getAttribute('href') === '#' + targetId;
    t.style.background = isActive ? '#fff'    : '';
    t.style.color      = isActive ? '#0c1b36' : '#64748b';
    t.style.boxShadow  = isActive ? '0 1px 6px rgba(0,0,0,.1)' : '';
    t.style.fontWeight = isActive ? '700' : '600';
  });
  history.replaceState(null, null, '#' + targetId);
}

document.querySelectorAll('.content-tab').forEach(function(tab) {
  tab.addEventListener('click', function(e) {
    e.preventDefault();
    switchTab(this.getAttribute('href').replace('#', ''));
  });
});

document.addEventListener('DOMContentLoaded', function() {
  var hash = window.location.hash ? window.location.hash.replace('#', '') : 'general';
  var validIds = ['general','hero','features','testimonials','about','contact','faq','legal'];
  switchTab(validIds.includes(hash) ? hash : 'general');
});

document.getElementById('contentForm').addEventListener('submit', function() {
  ['saveBtn','saveBtnBottom'].forEach(function(id) {
    var b = document.getElementById(id);
    if (b) b.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>جاري الحفظ...';
  });
});
</script>

<?php include '../includes/admin_footer.php'; ?>
