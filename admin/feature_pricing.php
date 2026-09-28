<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/content_helper.php';
requireAdmin();
$page_title = 'تسعير الميزات — الباقة المخصصة';

/* ── إنشاء جدول التسعير مع الأعمدة الجديدة ── */
$conn->query("CREATE TABLE IF NOT EXISTS feature_prices (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    feature_key   VARCHAR(100) NOT NULL UNIQUE,
    feature_label VARCHAR(200) NOT NULL,
    feature_icon  VARCHAR(50)  DEFAULT 'star',
    price_monthly DECIMAL(10,2) DEFAULT 0,
    price_yearly  DECIMAL(10,2) DEFAULT 0,
    discount_pct  DECIMAL(5,2)  DEFAULT 0,
    is_active     TINYINT       DEFAULT 1,
    sort_order    INT DEFAULT 0,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* إضافة الأعمدة الجديدة إن لم تكن موجودة */
try { $conn->query("ALTER TABLE feature_prices ADD COLUMN discount_pct DECIMAL(5,2) DEFAULT 0"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE feature_prices ADD COLUMN is_active TINYINT DEFAULT 1"); } catch (\Throwable $e) {}

/* ── بيانات افتراضية ── */
$defaults = [
    ['has_finance',        'الشؤون المالية',         'coins',                  1],
    ['has_invoices',       'الفواتير',                'file-invoice',           2],
    ['has_contracts',      'العقود',                  'file-signature',         3],
    ['has_poa',            'الوكالات',                'stamp',                  4],
    ['has_correspondence', 'الصادر والوارد',           'envelope',               5],
    ['has_library',        'المكتبة القانونية',        'book-open',              6],
    ['has_archive',        'الأرشيف',                 'archive',                7],
    ['has_ai',             'المساعد الذكي AI',        'robot',                  8],
    ['has_reports',        'التقارير المتقدمة',        'chart-bar',              9],
    ['has_api',            'API',                     'code',                  10],
    ['has_precedents',     'السوابق القضائية',         'scale-balanced',        11],
    ['has_digital_services','الخدمات الرقمية',         'hand-holding-dollar',   12],
    ['per_user',           'إضافة مستخدم (سنوياً)',   'user-plus',             21],
    ['per_100_cases',      'كل 100 قضية إضافية',      'gavel',                 22],
    ['per_512mb',          'كل 512 MB تخزين',         'hdd',                   23],
];
foreach ($defaults as $d) {
    $k  = $conn->real_escape_string($d[0]);
    $l  = $conn->real_escape_string($d[1]);
    $ic = $conn->real_escape_string($d[2]);
    $so = (int)$d[3];
    $conn->query("INSERT IGNORE INTO feature_prices (feature_key,feature_label,feature_icon,price_monthly,price_yearly,discount_pct,is_active,sort_order)
        VALUES ('$k','$l','$ic',0,0,0,1,$so)");
}

/* ── حفظ الإعدادات ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* تبديل الباقة المخصصة (AJAX) */
    if (isset($_POST['toggle_custom'])) {
        $val = ($_POST['toggle_custom'] === '1') ? '1' : '0';
        $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('custom_package_enabled','$val')
            ON DUPLICATE KEY UPDATE setting_value='$val'");
        echo json_encode(['ok'=>true,'enabled'=>$val]); exit;
    }

    /* تبديل تفعيل ميزة واحدة (AJAX) */
    if (isset($_POST['toggle_feature'])) {
        $fkey   = $conn->real_escape_string($_POST['toggle_feature']);
        $newval = ($_POST['active_val'] === '1') ? 1 : 0;
        $conn->query("UPDATE feature_prices SET is_active=$newval WHERE feature_key='$fkey'");
        echo json_encode(['ok'=>true,'active'=>$newval]); exit;
    }

    /* حفظ التسعير الكامل — سعر سنوي مباشر لكل ميزة، بلا احتساب من شهري */
    foreach ($_POST['py'] ?? [] as $key => $py) {
        $k  = $conn->real_escape_string($key);
        $py = max(0, (float)$py);
        $conn->query("UPDATE feature_prices SET price_yearly=$py WHERE feature_key='$k'");
    }

    /* القيم الأساسية — السعر الأساسي سنوي مباشرةً */
    $base_users = max(1, (int)($_POST['base_users_val'] ?? 3));
    $base_cases = max(1, (int)($_POST['base_cases_val'] ?? 50));
    $base_price = max(0, (float)($_POST['base_price'] ?? 0));
    $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('custom_base_users','$base_users')
        ON DUPLICATE KEY UPDATE setting_value='$base_users'");
    $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('custom_base_cases','$base_cases')
        ON DUPLICATE KEY UPDATE setting_value='$base_cases'");
    $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('custom_base_price','$base_price')
        ON DUPLICATE KEY UPDATE setting_value='$base_price'");

    header("Location: feature_pricing.php?msg=saved"); exit;
}

/* ── تحميل البيانات ── */
$custom_enabled = sc($conn,'custom_package_enabled','1');
$prices_res = $conn->query("SELECT * FROM feature_prices ORDER BY sort_order");
$prices = [];
while ($r = $prices_res->fetch_assoc()) $prices[$r['feature_key']] = $r;

$base_users_val = (int)(sc($conn,'custom_base_users','3'));
$base_cases_val = (int)(sc($conn,'custom_base_cases','50'));
$base_price     = (float)(sc($conn,'custom_base_price','0'));
$base_disc      = (float)(sc($conn,'custom_base_disc','0'));

$feature_keys  = ['has_finance','has_invoices','has_contracts','has_poa','has_correspondence','has_library','has_archive','has_ai','has_reports','has_api','has_precedents','has_digital_services'];
$capacity_keys = ['per_user','per_100_cases','per_512mb'];

include '../includes/admin_header.php';
?>

<style>
/* ── Feature Pricing Page ── */
.fp-feat-row {
  display: grid;
  grid-template-columns: 1fr 170px 90px;
  align-items: center;
  gap: 10px;
  padding: 12px 18px;
  border-bottom: 1px solid #f1f5f9;
  transition: background .15s;
}
.fp-feat-row:last-child { border-bottom: none; }
.fp-feat-row:hover { background: #f8fafc; }

.fp-feat-info { display: flex; align-items: center; gap: 10px; }
.fp-feat-icon {
  width: 36px; height: 36px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; flex-shrink: 0;
}

.fp-toggle {
  width: 44px; height: 24px; border-radius: 50px;
  cursor: pointer; position: relative;
  transition: background .25s; flex-shrink: 0;
}
.fp-toggle-knob {
  width: 18px; height: 18px; background: #fff; border-radius: 50%;
  position: absolute; top: 3px; transition: left .25s;
  box-shadow: 0 1px 3px rgba(0,0,0,.25);
}

.fp-yearly-badge {
  display: inline-block; font-size: 11px; font-weight: 600;
  padding: 3px 8px; border-radius: 20px;
  background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0;
  white-space: nowrap;
}
.fp-yearly-badge.no-disc {
  background: #f8fafc; color: #64748b; border-color: #e2e8f0;
}

.fp-sect-hdr {
  display: grid;
  grid-template-columns: 1fr 170px 90px;
  gap: 10px;
  padding: 8px 18px;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  font-size: 11px; font-weight: 700; color: #64748b;
  text-transform: uppercase; letter-spacing: .5px;
}

.fp-row-disabled { opacity: .45; }
.fp-row-disabled .fp-feat-icon { filter: grayscale(1); }
</style>

<!-- رأس الصفحة -->
<div class="mk-page-hdr mb-4">
  <div>
    <div class="mk-page-title"><i class="fas fa-sliders-h"></i> تسعير الميزات</div>
    <div class="mk-page-sub">حدد سعر وخصم كل ميزة — يمكن تفعيل أو تعطيل كل ميزة بشكل مستقل</div>
  </div>
  <a href="package_requests.php" class="btn btn-outline-primary btn-sm">
    <i class="fas fa-list me-1"></i>طلبات الباقات
  </a>
</div>

<!-- مفتاح الباقة المخصصة -->
<div class="card mb-4" id="custom-toggle-card" style="border:2px solid <?= $custom_enabled==='1' ? '#a78bfa' : '#e5e7eb' ?>;transition:border-color .3s">
  <div class="card-body d-flex align-items-center gap-4 flex-wrap" style="padding:16px 20px !important">
    <div style="width:48px;height:48px;border-radius:13px;background:<?= $custom_enabled==='1' ? 'linear-gradient(135deg,#7c3aed,#a855f7)' : '#f1f5f9' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .3s" id="toggle-icon-wrap">
      <i class="fas fa-puzzle-piece" style="font-size:20px;color:<?= $custom_enabled==='1' ? '#fff' : '#94a3b8' ?>" id="toggle-icon"></i>
    </div>
    <div style="flex:1;min-width:200px">
      <div class="fw-bold" style="font-size:15px;color:#1e293b">الباقة المخصصة</div>
      <div style="font-size:12px;color:#64748b" id="toggle-desc">
        <?= $custom_enabled==='1'
            ? 'مفعّلة — تظهر للمكاتب في صفحة الأسعار وعند التسجيل'
            : 'معطّلة — مخفية تماماً من الصفحات العامة' ?>
      </div>
    </div>
    <div class="d-flex align-items-center gap-3">
      <span class="badge" style="background:<?= $custom_enabled==='1' ? '#ede9fe' : '#f1f5f9' ?>;color:<?= $custom_enabled==='1' ? '#5b21b6' : '#64748b' ?>;font-size:12px;padding:5px 12px" id="toggle-lbl">
        <?= $custom_enabled==='1' ? 'مفعّلة' : 'معطّلة' ?>
      </span>
      <div id="custom-sw"
           onclick="toggleCustomPkg()"
           style="width:52px;height:28px;border-radius:50px;cursor:pointer;position:relative;transition:background .3s;background:<?= $custom_enabled==='1' ? '#7c3aed' : '#cbd5e1' ?>">
        <div id="custom-sw-knob"
             style="width:20px;height:20px;background:#fff;border-radius:50%;position:absolute;top:4px;transition:left .3s;box-shadow:0 1px 4px rgba(0,0,0,.25);left:<?= $custom_enabled==='1' ? '28px' : '4px' ?>"></div>
      </div>
    </div>
  </div>
</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show py-2 mb-4">
  <i class="fas fa-check-circle me-2"></i>تم حفظ التسعير بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" id="mainForm">
<div class="row g-4">

  <!-- ══ العمود الرئيسي ══ -->
  <div class="col-lg-8">

    <!-- الأساس -->
    <div class="card mb-4">
      <div class="card-header" style="background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#e8c040">
        <i class="fas fa-box me-2"></i>الأساس المشمول بالباقة المخصصة
      </div>
      <div class="card-body" style="background:#fafbff;padding:20px 22px !important">

        <!-- ── صف التسعير ── -->
        <div class="row g-3 align-items-stretch mb-3">

          <!-- السعر الأساسي السنوي -->
          <div class="col-sm-6">
            <div style="border:1.5px solid #dbeafe;border-radius:12px;background:#fff;padding:14px 16px;height:100%">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                <span style="width:32px;height:32px;border-radius:9px;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <i class="fas fa-coins" style="color:#2563eb;font-size:14px"></i>
                </span>
                <div>
                  <div style="font-size:12px;font-weight:700;color:#1e3a8a">السعر الأساسي السنوي</div>
                  <div style="font-size:11px;color:#94a3b8">قبل إضافة الميزات</div>
                </div>
              </div>
              <div class="input-group">
                <input type="number" name="base_price" id="base_price_inp"
                       class="form-control form-control-lg"
                       value="<?= $base_price ?>" min="0" step="0.01"
                       oninput="updatePreview()"
                       style="font-size:20px;font-weight:800;color:#1e293b;text-align:center">
                <span class="input-group-text fw-bold" style="background:#eff6ff;color:#2563eb;border-color:#dbeafe">ر.س / سنة</span>
              </div>
              <div style="font-size:10.5px;color:#f59e0b;margin-top:8px">
                <i class="fas fa-triangle-exclamation me-1"></i>هذه القيمة أصبحت سعراً سنوياً مباشراً (كانت شهرية سابقاً) — تأكّد أنها محدَّثة.
              </div>
            </div>
          </div>

          <!-- الحدود -->
          <div class="col-sm-3">
            <div style="border:1.5px solid #ede9fe;border-radius:12px;background:#fff;padding:14px 16px;height:100%">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                <span style="width:30px;height:30px;border-radius:8px;background:#ede9fe;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <i class="fas fa-users" style="color:#7c3aed;font-size:12px"></i>
                </span>
                <div style="font-size:12px;font-weight:700;color:#4c1d95">مستخدمون أساسيون</div>
              </div>
              <div class="input-group input-group-sm">
                <input type="number" name="base_users_val" class="form-control text-center fw-bold"
                       value="<?= $base_users_val ?>" min="1">
                <span class="input-group-text" style="background:#ede9fe;color:#7c3aed;font-size:11px;border-color:#ddd6fe">مستخدم</span>
              </div>
              <div style="font-size:10px;color:#94a3b8;margin-top:6px">مشمولون بالسعر الأساسي</div>
            </div>
          </div>
          <div class="col-sm-3">
            <div style="border:1.5px solid #fef3c7;border-radius:12px;background:#fff;padding:14px 16px;height:100%">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                <span style="width:30px;height:30px;border-radius:8px;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <i class="fas fa-gavel" style="color:#d97706;font-size:12px"></i>
                </span>
                <div style="font-size:12px;font-weight:700;color:#78350f">قضايا أساسية</div>
              </div>
              <div class="input-group input-group-sm">
                <input type="number" name="base_cases_val" class="form-control text-center fw-bold"
                       value="<?= $base_cases_val ?>" min="1">
                <span class="input-group-text" style="background:#fef3c7;color:#d97706;font-size:11px;border-color:#fde68a">قضية</span>
              </div>
              <div style="font-size:10px;color:#94a3b8;margin-top:6px">مشمولة بالسعر الأساسي</div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- الميزات الوظيفية -->
    <div class="card mb-4">
      <div class="card-header" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff">
        <i class="fas fa-puzzle-piece me-2"></i>الميزات الوظيفية
        <span class="badge ms-auto" style="background:rgba(255,255,255,.2);color:#fff;font-size:11px">
          <?= count(array_filter($feature_keys, fn($k) => ($prices[$k]['is_active'] ?? 1) == 1)) ?> من <?= count($feature_keys) ?> مفعّلة
        </span>
      </div>
      <div style="overflow:hidden">
        <div class="fp-sect-hdr">
          <span>الميزة</span>
          <span>السعر السنوي (ر.س)</span>
          <span>التفعيل</span>
        </div>
        <?php foreach ($feature_keys as $key):
          $p      = $prices[$key] ?? ['feature_label'=>$key,'feature_icon'=>'star','price_yearly'=>0,'is_active'=>1];
          $active = (int)($p['is_active'] ?? 1);
          $py     = (float)$p['price_yearly'];
          $icbg   = $active ? '#eff6ff' : '#f1f5f9';
          $iccl   = $active ? '#2563eb' : '#94a3b8';
        ?>
        <div class="fp-feat-row <?= !$active ? 'fp-row-disabled' : '' ?>" id="row_<?= $key ?>">
          <div class="fp-feat-info">
            <div class="fp-feat-icon" style="background:<?= $icbg ?>">
              <i class="fas fa-<?= e($p['feature_icon']) ?>" style="color:<?= $iccl ?>"></i>
            </div>
            <span class="fw-semibold" style="font-size:13px;color:#1e293b"><?= e($p['feature_label']) ?></span>
          </div>
          <div>
            <div class="input-group input-group-sm">
              <input type="number" name="py[<?= $key ?>]"
                     class="form-control py-input" data-key="<?= $key ?>"
                     value="<?= $py ?>" min="0" step="0.01" oninput="updatePreview()">
              <span class="input-group-text" style="font-size:10px">ر.س/سنة</span>
            </div>
          </div>
          <div class="text-center">
            <div class="fp-toggle" id="tog_<?= $key ?>"
                 style="background:<?= $active ? '#2563eb' : '#cbd5e1' ?>;margin:0 auto"
                 data-active="<?= $active ?>"
                 onclick="toggleFeature('<?= $key ?>', this)">
              <div class="fp-toggle-knob" style="left:<?= $active ? '22px' : '3px' ?>"></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- الطاقة الإضافية -->
    <div class="card mb-4">
      <div class="card-header" style="background:linear-gradient(135deg,#92400e,#d97706);color:#fff">
        <i class="fas fa-layer-group me-2"></i>تسعير الطاقة الإضافية
      </div>
      <div style="overflow:hidden">
        <div class="fp-sect-hdr">
          <span>العنصر</span>
          <span>السعر السنوي (ر.س)</span>
          <span>التفعيل</span>
        </div>
        <?php foreach ($capacity_keys as $key):
          $p      = $prices[$key] ?? ['feature_label'=>$key,'feature_icon'=>'plus','price_yearly'=>0,'is_active'=>1];
          $active = (int)($p['is_active'] ?? 1);
          $py     = (float)$p['price_yearly'];
          $icbg   = $active ? '#fefce8' : '#f1f5f9';
          $iccl   = $active ? '#d97706' : '#94a3b8';
        ?>
        <div class="fp-feat-row <?= !$active ? 'fp-row-disabled' : '' ?>" id="row_<?= $key ?>">
          <div class="fp-feat-info">
            <div class="fp-feat-icon" style="background:<?= $icbg ?>">
              <i class="fas fa-<?= e($p['feature_icon']) ?>" style="color:<?= $iccl ?>"></i>
            </div>
            <span class="fw-semibold" style="font-size:13px;color:#1e293b"><?= e($p['feature_label']) ?></span>
          </div>
          <div>
            <div class="input-group input-group-sm">
              <input type="number" name="py[<?= $key ?>]"
                     class="form-control py-input" data-key="<?= $key ?>"
                     value="<?= $py ?>" min="0" step="0.01" oninput="updatePreview()">
              <span class="input-group-text" style="font-size:10px">ر.س/سنة</span>
            </div>
          </div>
          <div class="text-center">
            <div class="fp-toggle" id="tog_<?= $key ?>"
                 style="background:<?= $active ? '#d97706' : '#cbd5e1' ?>;margin:0 auto"
                 data-active="<?= $active ?>"
                 onclick="toggleFeature('<?= $key ?>', this)">
              <div class="fp-toggle-knob" style="left:<?= $active ? '22px' : '3px' ?>"></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary px-5">
        <i class="fas fa-save me-2"></i>حفظ جميع الأسعار
      </button>
      <a href="feature_pricing.php" class="btn btn-outline-secondary">
        <i class="fas fa-undo me-1"></i>إلغاء
      </a>
    </div>

  </div><!-- /col-lg-8 -->

  <!-- ══ المعاينة ══ -->
  <div class="col-lg-4">
    <div class="card" style="position:sticky;top:80px">
      <div class="card-header" style="background:linear-gradient(135deg,#065f46,#059669);color:#fff">
        <i class="fas fa-calculator me-2"></i>معاينة تجريبية
      </div>
      <div class="card-body">
        <p style="font-size:11px;color:#64748b" class="mb-3">
          اختر ميزات لترى كيف يحسب المكتب سعر باقته
        </p>

        <div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">الميزات الوظيفية</div>
        <?php foreach ($feature_keys as $key):
          $p = $prices[$key] ?? [];
          $active = (int)($p['is_active'] ?? 1);
        ?>
        <div class="form-check mb-1" id="prev_wrap_<?= $key ?>" <?= !$active ? 'style="opacity:.4;pointer-events:none"' : '' ?>>
          <input class="form-check-input preview-check" type="checkbox"
                 id="prev_<?= $key ?>"
                 data-key="<?= $key ?>"
                 data-py="<?= $p['price_yearly'] ?? 0 ?>"
                 onchange="updatePreview()">
          <label class="form-check-label" for="prev_<?= $key ?>" style="font-size:12px">
            <?= e($p['feature_label'] ?? $key) ?>
            <?php if (!$active): ?>
              <span class="badge bg-secondary" style="font-size:9px">معطّلة</span>
            <?php elseif ((float)($p['price_yearly'] ?? 0) > 0): ?>
              <span style="color:#7c3aed;font-size:10px;font-weight:600">+<?= number_format((float)$p['price_yearly'],0) ?> ر.س</span>
            <?php else: ?>
              <span style="color:#64748b;font-size:10px">مجاناً</span>
            <?php endif; ?>
          </label>
        </div>
        <?php endforeach; ?>

        <hr class="my-2">
        <div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">الطاقة الإضافية</div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label style="font-size:11px;color:#64748b">مستخدمون إضافيون</label>
            <input type="number" id="prev_extra_users" value="0" min="0" class="form-control form-control-sm" onchange="updatePreview()">
          </div>
          <div class="col-6">
            <label style="font-size:11px;color:#64748b">قضايا إضافية (÷100)</label>
            <input type="number" id="prev_extra_cases" value="0" min="0" class="form-control form-control-sm" onchange="updatePreview()">
          </div>
        </div>

        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:14px">
          <div class="d-flex justify-content-between mb-1">
            <span style="font-size:12px;color:#64748b">السعر الأساسي</span>
            <span style="font-size:12px;font-weight:600" id="prev_base"><?= number_format($base_price) ?> ر.س</span>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span style="font-size:12px;color:#64748b">الميزات</span>
            <span style="font-size:12px;font-weight:600" id="prev_feats">0 ر.س</span>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span style="font-size:12px;color:#64748b">الطاقة الإضافية</span>
            <span style="font-size:12px;font-weight:600" id="prev_cap">0 ر.س</span>
          </div>
          <hr style="margin:8px 0">
          <div class="d-flex justify-content-between align-items-center">
            <span style="font-weight:700;font-size:13px">الإجمالي / سنة</span>
            <span style="font-size:20px;font-weight:900;color:#059669" id="prev_total">0 ر.س</span>
          </div>
        </div>

        <div class="mt-3 p-2 rounded" style="background:#fef3c7;border:1px solid #fde68a;font-size:11px;color:#92400e">
          <i class="fas fa-lightbulb me-1"></i>
          السعر المعروض للمكاتب يعكس ما أدخلته هنا فور الحفظ
        </div>
      </div>
    </div>
  </div>

</div><!-- /row -->
</form>

<script>
/* ── مفتاح الباقة المخصصة ── */
var _customEnabled = <?= $custom_enabled==='1' ? 'true' : 'false' ?>;
function toggleCustomPkg() {
  _customEnabled = !_customEnabled;
  var val = _customEnabled ? '1' : '0';
  var sw   = document.getElementById('custom-sw');
  var knob = document.getElementById('custom-sw-knob');
  var card = document.getElementById('custom-toggle-card');
  var iw   = document.getElementById('toggle-icon-wrap');
  var ico  = document.getElementById('toggle-icon');
  var lbl  = document.getElementById('toggle-lbl');
  var desc = document.getElementById('toggle-desc');
  sw.style.background = _customEnabled ? '#7c3aed' : '#cbd5e1';
  knob.style.left     = _customEnabled ? '28px' : '4px';
  card.style.borderColor = _customEnabled ? '#a78bfa' : '#e5e7eb';
  iw.style.background = _customEnabled ? 'linear-gradient(135deg,#7c3aed,#a855f7)' : '#f1f5f9';
  ico.style.color     = _customEnabled ? '#fff' : '#94a3b8';
  lbl.textContent     = _customEnabled ? 'مفعّلة' : 'معطّلة';
  lbl.style.background = _customEnabled ? '#ede9fe' : '#f1f5f9';
  lbl.style.color      = _customEnabled ? '#5b21b6' : '#64748b';
  desc.textContent    = _customEnabled
    ? 'مفعّلة — تظهر للمكاتب في صفحة الأسعار وعند التسجيل'
    : 'معطّلة — مخفية تماماً من الصفحات العامة';
  var fd = new FormData();
  fd.append('toggle_custom', val);
  fetch('feature_pricing.php', {method:'POST', body:fd}).catch(function(){});
}

/* ── تفعيل / تعطيل ميزة ── */
function toggleFeature(key, togEl) {
  var row       = document.getElementById('row_' + key);
  var knob      = togEl.querySelector('.fp-toggle-knob');
  var wasActive = togEl.dataset.active === '1';
  var newActive = wasActive ? 0 : 1;
  togEl.dataset.active = newActive;
  /* تحديث الواجهة */
  var isCapacity = ['per_user','per_100_cases','per_512mb'].indexOf(key) >= 0;
  var onColor = isCapacity ? '#d97706' : '#2563eb';
  togEl.style.background = newActive ? onColor : '#cbd5e1';
  knob.style.left        = newActive ? '22px' : '3px';
  row.classList.toggle('fp-row-disabled', !newActive);
  /* تعطيل / تفعيل الحقول — disabled لا يُرسل مع الفورم لذا نستخدم readonly */
  row.querySelectorAll('input[type="number"]').forEach(function(inp) {
    if (newActive) {
      inp.disabled = false;
      inp.style.opacity = '';
    } else {
      inp.disabled = false; /* نبقيها مفعّلة لكن الصف معتم بالـ CSS */
    }
  });
  /* تحديث المعاينة */
  var prevWrap = document.getElementById('prev_wrap_' + key);
  if (prevWrap) {
    prevWrap.style.opacity = newActive ? '1' : '.4';
    prevWrap.style.pointerEvents = newActive ? '' : 'none';
  }
  /* حفظ في الخادم */
  var fd = new FormData();
  fd.append('toggle_feature', key);
  fd.append('active_val', newActive ? '1' : '0');
  fetch('feature_pricing.php', {method:'POST', body:fd}).catch(function(){});
  updatePreview();
}

/* ── معاينة حية (سعر سنوي مباشر، بلا احتساب من شهري) ── */
var _basePrice = <?= $base_price ?>;
function updatePreview() {
  var bp = parseFloat(document.getElementById('base_price_inp')?.value) || _basePrice;
  var feats = 0;
  document.querySelectorAll('.preview-check:checked').forEach(function(cb) {
    var key = cb.dataset.key;
    var pyInp = document.querySelector('input[name="py[' + key + ']"]');
    feats += pyInp ? (parseFloat(pyInp.value) || 0) : (parseFloat(cb.dataset.py) || 0);
  });
  var eu = parseInt(document.getElementById('prev_extra_users').value) || 0;
  var ec = parseInt(document.getElementById('prev_extra_cases').value) || 0;
  var puInp = document.querySelector('input[name="py[per_user]"]');
  var pcInp = document.querySelector('input[name="py[per_100_cases]"]');
  var pu  = puInp ? (parseFloat(puInp.value)||0) : 0;
  var pc  = pcInp ? (parseFloat(pcInp.value)||0) : 0;
  var cap = (eu * pu) + (ec * pc);
  var total = bp + feats + cap;
  document.getElementById('prev_base').textContent  = bp.toLocaleString('ar') + ' ر.س';
  document.getElementById('prev_feats').textContent = feats.toLocaleString('ar') + ' ر.س';
  document.getElementById('prev_cap').textContent   = cap.toLocaleString('ar') + ' ر.س';
  document.getElementById('prev_total').textContent = total.toLocaleString('ar') + ' ر.س';
}

updatePreview();
</script>

<?php include '../includes/admin_footer.php'; ?>
