<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireAdmin();
$page_title = 'الباقات وتخصيص المزايا';

/* ── حذف باقة ── */
if (isset($_GET['delete'])) {
    $conn->query("DELETE FROM packages WHERE id=".(int)$_GET['delete']);
    header("Location: packages.php?msg=deleted"); exit;
}

/* ── تبديل ظهور الباقة (AJAX) ── */
if (isset($_POST['toggle_pkg'])) {
    $tid = (int)$_POST['toggle_pkg'];
    $conn->query("UPDATE packages SET is_active = IF(is_active=1,0,1) WHERE id=$tid");
    $row = $conn->query("SELECT is_active FROM packages WHERE id=$tid")->fetch_assoc();
    echo json_encode(['ok'=>true,'active'=>(int)$row['is_active']]); exit;
}

/* ── معالجة POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ① حفظ بيانات الباقة الأساسية */
    if ($_POST['form_type'] === 'package') {
        $name   = $conn->real_escape_string($_POST['name']);
        $pm     = (float)$_POST['price_monthly'];
        $py     = (float)$_POST['price_yearly'];
        $active = isset($_POST['is_active']) ? 1 : 0;

        if (!empty($_POST['id'])) {
            $id = (int)$_POST['id'];
            $conn->query("UPDATE packages SET name='$name',price_monthly=$pm,price_yearly=$py,is_active=$active WHERE id=$id");
        } else {
            $conn->query("INSERT INTO packages (name,price_monthly,price_yearly,max_users,max_cases,is_active)
                          VALUES ('$name',$pm,$py,3,50,$active)");
            $id = $conn->insert_id;
            // إدراج مزايا افتراضية للباقة الجديدة
            $defaults = [
                'max_cases'=>'50','max_clients'=>'100','max_users'=>'3','storage_mb'=>'0',
                'has_finance'=>'1','has_contracts'=>'0','has_poa'=>'0','has_library'=>'0',
                'has_correspondence'=>'0','has_archive'=>'0','has_ai'=>'0',
                'has_invoices'=>'0','has_reports'=>'0','has_api'=>'0',
                'has_precedents'=>'0','has_digital_services'=>'0',
            ];
            foreach ($defaults as $k => $v) {
                $conn->query("INSERT IGNORE INTO package_features (package_id,feature_key,feature_value)
                              VALUES ($id,'$k','$v')");
            }
        }
        header("Location: packages.php?msg=saved"); exit;
    }

    /* ② حفظ مزايا الباقة */
    if ($_POST['form_type'] === 'features') {
        $pid = (int)$_POST['pkg_id'];

        // الحقول الرقمية
        $numericKeys = ['max_cases','max_clients','max_users','storage_mb'];
        foreach ($numericKeys as $key) {
            $val = max(0, (int)($_POST[$key] ?? 0));
            $conn->query("INSERT INTO package_features (package_id,feature_key,feature_value)
                          VALUES ($pid,'$key','$val')
                          ON DUPLICATE KEY UPDATE feature_value='$val'");
        }

        // الحقول البوليانية
        $boolKeys = ['has_finance','has_contracts','has_poa','has_library',
                     'has_correspondence','has_archive','has_ai',
                     'has_invoices','has_reports','has_api',
                     'has_precedents','has_digital_services'];
        foreach ($boolKeys as $key) {
            $val = isset($_POST[$key]) ? '1' : '0';
            $conn->query("INSERT INTO package_features (package_id,feature_key,feature_value)
                          VALUES ($pid,'$key','$val')
                          ON DUPLICATE KEY UPDATE feature_value='$val'");
        }

        // تحديث max_users و max_cases في جدول packages أيضاً (للتوافق)
        $mu = max(1, (int)($_POST['max_users'] ?? 3));
        $mc = max(0, (int)($_POST['max_cases'] ?? 50));

        // بناء قائمة الميزات المفعّلة لعمود features في packages (يُقرأ من pricing.php)
        $featLabelMap = [
            'has_finance'        => 'الشؤون المالية',
            'has_invoices'       => 'الفواتير',
            'has_contracts'      => 'العقود',
            'has_poa'            => 'الوكالات',
            'has_correspondence' => 'الصادر والوارد',
            'has_library'        => 'المكتبة القانونية',
            'has_archive'        => 'الأرشيف',
            'has_ai'             => 'المساعد الذكي AI',
            'has_reports'        => 'التقارير المتقدمة',
            'has_api'            => 'API',
            'has_precedents'     => 'السوابق القضائية',
            'has_digital_services' => 'الخدمات الرقمية',
        ];
        $enabledLabels = [];
        foreach ($featLabelMap as $fk => $fl) {
            if (isset($_POST[$fk])) $enabledLabels[] = $fl;
        }
        $featuresStr = $conn->real_escape_string(implode(',', $enabledLabels));
        $conn->query("UPDATE packages SET max_users=$mu, max_cases=$mc, features='$featuresStr' WHERE id=$pid");

        header("Location: packages.php?msg=features_saved&pkg=$pid"); exit;
    }
}

/* ── جلب الباقات مع مزاياها ── */
$packages = $conn->query("SELECT p.*, (SELECT COUNT(*) FROM offices WHERE package_id=p.id) offices_count FROM packages p ORDER BY p.price_monthly ASC");
$pkgs = [];
while ($p = $packages->fetch_assoc()) {
    $pkgs[] = $p;
}

// جلب مزايا كل الباقات دفعة واحدة
$allFeatures = [];
$fRes = $conn->query("SELECT * FROM package_features ORDER BY package_id");
while ($f = $fRes->fetch_assoc()) {
    $allFeatures[$f['package_id']][$f['feature_key']] = $f['feature_value'];
}

$edit = null;
if (isset($_GET['edit'])) {
    $edit = $conn->query("SELECT * FROM packages WHERE id=".(int)$_GET['edit'])->fetch_assoc();
}

$editFeatPkg = null;
if (isset($_GET['features'])) {
    foreach ($pkgs as $p) {
        if ($p['id'] == (int)$_GET['features']) { $editFeatPkg = $p; break; }
    }
}

include '../includes/admin_header.php';

// تعريف جميع المزايا مع تصنيفاتها
$featureGroups = [
    'الحدود الكمية' => [
        'max_cases'   => ['القضايا (الحد الأقصى)',    'gavel',        'number', 'قضية — 0 = غير محدود'],
        'max_clients' => ['العملاء (الحد الأقصى)',    'address-book', 'number', 'عميل — 0 = غير محدود'],
        'max_users'   => ['المستخدمون (الحد الأقصى)', 'users',        'number', 'مستخدم — 0 = غير محدود'],
        'storage_mb'  => ['التخزين (ميغابايت)',        'hdd',          'number', 'MB — 0 = بدون تخزين'],
    ],
    'الوحدات الوظيفية' => [
        'has_finance'       => ['الشؤون المالية',     'wallet',         'bool', 'تتبع الإيرادات والمصروفات'],
        'has_invoices'      => ['الفواتير',            'file-invoice',   'bool', 'إنشاء وإرسال الفواتير'],
        'has_contracts'     => ['العقود',              'file-signature', 'bool', 'إدارة العقود القانونية'],
        'has_poa'           => ['الوكالات',            'stamp',          'bool', 'إدارة وكالات التفويض'],
        'has_correspondence'=> ['الصادر والوارد',      'envelope',       'bool', 'إدارة المراسلات الرسمية'],
        'has_library'       => ['المكتبة القانونية',   'book-open',      'bool', 'الأنظمة والنماذج القانونية'],
        'has_archive'       => ['الأرشيف',             'archive',        'bool', 'رفع وحفظ الملفات'],
        'has_ai'            => ['المساعد الذكي',       'robot',          'bool', 'مساعد AI قانوني متخصص'],
        'has_reports'       => ['التقارير المتقدمة',   'chart-bar',      'bool', 'صفحة تقارير شاملة (قضايا، جلسات، مهام، عملاء، مالية) بفلترة من/إلى تاريخ + أداء الفريق'],
        'has_api'           => ['API',                 'code',           'bool', 'وصول لواجهة برمجة التطبيقات لدمج الأنظمة الخارجية'],
        'has_precedents'    => ['السوابق القضائية',    'scale-balanced', 'bool', 'قاعدة بيانات السوابق والأحكام القضائية'],
        'has_digital_services' => ['الخدمات الرقمية',  'hand-holding-dollar', 'bool', 'كتالوج الخدمات الرقمية وطلبات العملاء'],
    ],
];
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>
  <?= ['saved'=>'تم حفظ الباقة','deleted'=>'تم الحذف','features_saved'=>'تم حفظ المزايا بنجاح'][$_GET['msg']] ?? 'تم الحفظ' ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ══ رأس الصفحة ══ -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h5 class="fw-bold mb-1" style="color:#0c1b36">إدارة الباقات والمزايا</h5>
    <small class="text-muted">خصّص المزايا المتاحة لكل باقة بشكل مستقل</small>
  </div>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#pkgModal">
    <i class="fas fa-plus me-1"></i>إضافة باقة
  </button>
</div>

<!-- ══ لوحة مقارنة الباقات ══ -->
<div class="card mb-4">
  <div class="card-header" style="background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#fff;border-radius:12px 12px 0 0;padding:18px 24px">
    <i class="fas fa-table me-2 text-warning"></i>
    <span class="fw-bold" style="color:#fff">مصفوفة المزايا — مقارنة بين الباقات</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0" style="min-width:700px">
        <thead>
          <tr style="background:#f8fafc">
            <th style="width:200px;padding:14px 20px;font-size:13px;color:#374151;border-bottom:2px solid #e5e7eb">الميزة</th>
            <?php foreach ($pkgs as $p):
              $color = ['#64748b','#0c1b36','#7c3aed'][ (array_search($p, $pkgs)) % 3 ];
            ?>
            <th style="text-align:center;padding:14px 16px;border-bottom:2px solid #e5e7eb">
              <div class="fw-bold" style="color:<?= $color ?>;font-size:14px"><?= e($p['name']) ?></div>
              <small class="text-muted"><?= number_format($p['price_monthly']) ?> ر.س/شهر</small>
              <?php if (!$p['is_active']): ?>
              <div><span style="background:#fee2e2;color:#991b1b;border-radius:50px;padding:1px 8px;font-size:10px;font-weight:700">مخفية</span></div>
              <?php endif; ?>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($featureGroups as $groupName => $features): ?>
          <tr>
            <td colspan="<?= count($pkgs)+1 ?>" style="background:#f1f5f9;padding:8px 20px;font-size:11px;font-weight:800;color:#64748b;letter-spacing:.8px;text-transform:uppercase;border:none">
              <?= $groupName ?>
            </td>
          </tr>
          <?php foreach ($features as $key => [$label, $icon, $type, $hint]): ?>
          <tr style="border-bottom:1px solid #f0f4f8">
            <td style="padding:12px 20px">
              <div class="d-flex align-items-center gap-2">
                <div style="width:30px;height:30px;border-radius:8px;background:#f0f4ff;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <i class="fas fa-<?= $icon ?>" style="font-size:12px;color:#4f46e5"></i>
                </div>
                <div>
                  <div style="font-size:13px;font-weight:600;color:#1e293b"><?= $label ?></div>
                  <div style="font-size:11px;color:#94a3b8"><?= $hint ?></div>
                </div>
              </div>
            </td>
            <?php foreach ($pkgs as $p):
              $val = $allFeatures[$p['id']][$key] ?? '0';
            ?>
            <td style="text-align:center;padding:12px 16px;vertical-align:middle">
              <?php if ($type === 'bool'): ?>
                <?php if ($val === '1'): ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#16a34a;border-radius:50px;padding:4px 12px;font-size:12px;font-weight:700">
                    <i class="fas fa-check" style="font-size:10px"></i> مفعّل
                  </span>
                <?php else: ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;background:#f1f5f9;color:#94a3b8;border-radius:50px;padding:4px 12px;font-size:12px">
                    <i class="fas fa-minus" style="font-size:10px"></i> غير متاح
                  </span>
                <?php endif; ?>
              <?php else: ?>
                <span style="display:inline-block;background:#e0e7ff;color:#3730a3;border-radius:8px;padding:3px 10px;font-size:13px;font-weight:700;min-width:40px">
                  <?= ($val == '0' && $key !== 'storage_mb') ? '∞' : number_format((int)$val) ?>
                  <?= $key === 'storage_mb' ? '<small style="font-size:10px"> MB</small>' : '' ?>
                </span>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ بطاقات الباقات ══ -->
<div class="row g-3 mb-4">
<?php
$cardColors = [
    ['#64748b','#94a3b8','#f8fafc'],
    ['#0c1b36','#1a3a6e','#eff6ff'],
    ['#7c3aed','#a78bfa','#f5f3ff'],
];
foreach ($pkgs as $idx => $p):
    [$c1,$c2,$bg] = $cardColors[$idx % 3];
    $feats = $allFeatures[$p['id']] ?? [];
    $enabledCount = count(array_filter(array_intersect_key($feats, array_flip(
        ['has_finance','has_contracts','has_poa','has_library','has_correspondence','has_archive','has_ai','has_invoices','has_reports','has_api','has_precedents','has_digital_services']
    )), fn($v)=>$v==='1'));
    $totalBool = 12;
?>
<div class="col-lg-4 col-md-6">
  <div class="card h-100" id="card_<?= $p['id'] ?>"
       style="border:2px solid <?= $c1 ?>20;border-top:4px solid <?= $c1 ?>;transition:opacity .3s;<?= $p['is_active'] ? '' : 'opacity:.55' ?>">
    <!-- رأس الباقة -->
    <div class="card-body" style="background:linear-gradient(135deg,<?= $c1 ?>,<?= $c2 ?>);border-radius:0;padding:24px 20px;text-align:center">
      <h4 class="fw-bold text-white mb-1"><?= e($p['name']) ?></h4>
      <div class="text-white" style="font-size:28px;font-weight:900;line-height:1">
        <?= number_format($p['price_monthly']) ?>
        <small style="font-size:14px;font-weight:400">ر.س/شهر</small>
      </div>
      <div style="color:rgba(255,255,255,.6);font-size:12px;margin-top:4px">
        <?= number_format($p['price_yearly']) ?> ر.س سنوياً
      </div>
      <div class="mt-3 d-flex align-items-center justify-content-center gap-2">
        <span class="pkg-status-lbl" id="lbl_<?= $p['id'] ?>"
              style="font-size:12px;font-weight:700;color:<?= $p['is_active'] ? '#86efac' : 'rgba(255,255,255,.45)' ?>">
          <?= $p['is_active'] ? '● ظاهرة للعملاء' : '○ مخفية' ?>
        </span>
        <div class="pkg-toggle" id="sw_<?= $p['id'] ?>"
             data-id="<?= $p['id'] ?>" data-active="<?= (int)$p['is_active'] ?>"
             onclick="togglePkg(<?= $p['id'] ?>)"
             title="<?= $p['is_active'] ? 'إخفاء الباقة' : 'إظهار الباقة' ?>"
             style="width:44px;height:24px;border-radius:50px;cursor:pointer;position:relative;
                    transition:background .25s;flex-shrink:0;
                    background:<?= $p['is_active'] ? 'rgba(134,239,172,.9)' : 'rgba(255,255,255,.2)' ?>">
          <div class="pkg-toggle-knob" id="knob_<?= $p['id'] ?>"
               style="width:18px;height:18px;background:#fff;border-radius:50%;position:absolute;top:3px;
                      transition:left .25s;box-shadow:0 1px 4px rgba(0,0,0,.3);
                      left:<?= $p['is_active'] ? '23px' : '3px' ?>"></div>
        </div>
      </div>
    </div>

    <!-- ملخص الحدود -->
    <div style="background:#f8fafc;padding:14px 20px;border-bottom:1px solid #f0f0f0">
      <div class="row g-2 text-center">
        <?php
        $limits = [
            ['القضايا', $feats['max_cases'] ?? '?', 'gavel', '#4f46e5'],
            ['العملاء', $feats['max_clients'] ?? '?', 'users', '#0891b2'],
            ['المستخدمون', $feats['max_users'] ?? '?', 'user-tie', '#16a34a'],
            ['التخزين', ($feats['storage_mb']??'0').'MB', 'hdd', '#d97706'],
        ];
        foreach ($limits as [$lbl,$val,$ico,$clr]):
            $display = ($val === '0' || $val === 0 || (is_numeric($val) && (int)$val >= 999)) && $lbl !== 'التخزين' ? 'غير محدود' : $val;
        ?>
        <div class="col-3">
          <div style="font-size:11px;color:<?= $clr ?>;font-weight:800"><?= $display ?></div>
          <div style="font-size:10px;color:#94a3b8"><?= $lbl ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- مقياس المزايا المفعّلة -->
    <div style="padding:14px 20px;border-bottom:1px solid #f0f0f0">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span style="font-size:12px;color:#64748b;font-weight:600">الوحدات المفعّلة</span>
        <span style="font-size:13px;font-weight:800;color:<?= $c1 ?>"><?= $enabledCount ?>/<?= $totalBool ?></span>
      </div>
      <div style="height:6px;background:#e2e8f0;border-radius:50px;overflow:hidden">
        <div style="height:100%;width:<?= round($enabledCount/$totalBool*100) ?>%;background:linear-gradient(90deg,<?= $c1 ?>,<?= $c2 ?>);border-radius:50px;transition:width .5s"></div>
      </div>
      <!-- أيقونات المزايا -->
      <div class="d-flex flex-wrap gap-1 mt-2">
        <?php
        $iconMap = [
            'has_finance'=>'wallet','has_invoices'=>'file-invoice','has_contracts'=>'file-signature',
            'has_poa'=>'stamp','has_correspondence'=>'envelope','has_library'=>'book-open',
            'has_archive'=>'archive','has_ai'=>'robot','has_reports'=>'chart-bar','has_api'=>'code',
            'has_precedents'=>'scale-balanced','has_digital_services'=>'hand-holding-dollar',
        ];
        foreach ($iconMap as $fkey => $ficon):
            $on = ($feats[$fkey] ?? '0') === '1';
        ?>
        <span title="<?= e($featureGroups['الوحدات الوظيفية'][$fkey][0] ?? $fkey) ?>"
              style="width:24px;height:24px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;
              background:<?= $on ? "linear-gradient(135deg,{$c1},{$c2})" : '#f1f5f9' ?>;color:<?= $on ? '#fff' : '#cbd5e1' ?>;
              transition:all .2s">
          <i class="fas fa-<?= $ficon ?>"></i>
        </span>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="padding:14px 20px;font-size:12px;color:#64748b">
      <i class="fas fa-building me-1"></i> <?= $p['offices_count'] ?> مكتب مشترك
    </div>

    <!-- أزرار الإجراءات -->
    <div class="card-footer bg-white d-flex gap-2 p-3" style="border-top:1px solid #f0f0f0">
      <a href="packages.php?features=<?= $p['id'] ?>" class="btn btn-sm flex-fill fw-semibold"
         style="background:linear-gradient(135deg,<?= $c1 ?>,<?= $c2 ?>);color:#fff;border:none;border-radius:8px">
        <i class="fas fa-sliders-h me-1"></i>تخصيص المزايا
      </a>
      <a href="packages.php?edit=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="تعديل الباقة">
        <i class="fas fa-edit"></i>
      </a>
      <a href="packages.php?delete=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" title="حذف"
         onclick="return confirm('حذف هذه الباقة؟ سيتم حذف جميع مزاياها.')">
        <i class="fas fa-trash"></i>
      </a>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div><!-- /row -->


<!-- ══════════════════════════════════════════════
     مودال تخصيص المزايا (يظهر عند ?features=ID)
══════════════════════════════════════════════ -->
<?php if ($editFeatPkg):
    $pid  = $editFeatPkg['id'];
    $pFeats = $allFeatures[$pid] ?? [];
    $pidx   = array_search($editFeatPkg, $pkgs);
    [$fc1,$fc2] = $cardColors[$pidx % 3];
?>
<div class="modal fade" id="featModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden">
      <!-- هيدر المودال -->
      <div class="modal-header p-0" style="border:none">
        <div style="background:linear-gradient(135deg,<?= $fc1 ?>,<?= $fc2 ?>);width:100%;padding:22px 28px;display:flex;align-items:center;gap:14px">
          <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-sliders-h" style="color:#fff;font-size:18px"></i>
          </div>
          <div>
            <div style="color:#fff;font-weight:800;font-size:17px">تخصيص مزايا: <?= e($editFeatPkg['name']) ?></div>
            <div style="color:rgba(255,255,255,.6);font-size:12px">فعّل أو أوقف أي ميزة لهذه الباقة</div>
          </div>
          <a href="packages.php" class="ms-auto" style="color:rgba(255,255,255,.7);font-size:20px;text-decoration:none;line-height:1">
            <i class="fas fa-times"></i>
          </a>
        </div>
      </div>

      <form method="POST" action="">
        <input type="hidden" name="form_type" value="features">
        <input type="hidden" name="pkg_id" value="<?= $pid ?>">

        <div class="modal-body p-0">

          <?php foreach ($featureGroups as $groupName => $features): ?>
          <!-- مجموعة: <?= $groupName ?> -->
          <div style="padding:20px 28px 0">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
              <div style="flex:1;height:1px;background:#e2e8f0"></div>
              <span style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;white-space:nowrap;padding:0 8px"><?= $groupName ?></span>
              <div style="flex:1;height:1px;background:#e2e8f0"></div>
            </div>

            <?php if ($groupName === 'الحدود الكمية'): ?>
            <!-- حقول رقمية -->
            <div class="row g-3 mb-4">
              <?php foreach ($features as $key => [$label, $icon, $type, $hint]): ?>
              <div class="col-md-6">
                <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px;transition:border-color .2s"
                     onmouseover="this.style.borderColor='<?= $fc1 ?>50'" onmouseout="this.style.borderColor='#e2e8f0'">
                  <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                    <div style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,<?= $fc1 ?>,<?= $fc2 ?>);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                      <i class="fas fa-<?= $icon ?>" style="color:#fff;font-size:13px"></i>
                    </div>
                    <div>
                      <div style="font-size:13px;font-weight:700;color:#1e293b"><?= $label ?></div>
                      <div style="font-size:11px;color:#94a3b8"><?= $hint ?></div>
                    </div>
                  </div>
                  <div style="display:flex;align-items:center;gap:8px">
                    <input type="number" name="<?= $key ?>" min="0" max="99999"
                           value="<?= (int)($pFeats[$key] ?? 0) ?>"
                           style="flex:1;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 12px;font-size:14px;font-weight:700;color:#0c1b36;font-family:'Tajawal',sans-serif;text-align:center;outline:none"
                           onfocus="this.style.borderColor='<?= $fc1 ?>'" onblur="this.style.borderColor='#e2e8f0'">
                    <span style="font-size:12px;color:#94a3b8;white-space:nowrap">
                      <?= $key === 'storage_mb' ? 'MB' : ($key === 'max_users' ? 'مستخدم' : ($key === 'max_cases' ? 'قضية' : 'عميل')) ?>
                      <br><small style="font-size:10px">(0 = ∞)</small>
                    </span>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>

            <?php else: ?>
            <!-- تبديلات بوليانية -->
            <div class="row g-2 mb-4">
              <?php foreach ($features as $key => [$label, $icon, $type, $hint]):
                $isOn = ($pFeats[$key] ?? '0') === '1';
              ?>
              <div class="col-md-6">
                <label class="feat-toggle <?= $isOn ? 'feat-on' : '' ?>" data-key="<?= $key ?>"
                       style="display:flex;align-items:center;gap:12px;padding:14px 16px;
                              border:2px solid <?= $isOn ? $fc1 : '#e2e8f0' ?>;border-radius:12px;
                              cursor:pointer;background:<?= $isOn ? "linear-gradient(135deg,{$fc1}08,{$fc2}12)" : '#fff' ?>;
                              transition:all .2s;user-select:none">
                  <input type="checkbox" name="<?= $key ?>" id="feat_<?= $key ?>"
                         <?= $isOn ? 'checked' : '' ?> style="display:none">
                  <!-- أيقونة الميزة -->
                  <div class="feat-icon" style="width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:15px;
                       background:<?= $isOn ? "linear-gradient(135deg,{$fc1},{$fc2})" : '#f1f5f9' ?>;
                       color:<?= $isOn ? '#fff' : '#94a3b8' ?>;transition:all .2s">
                    <i class="fas fa-<?= $icon ?>"></i>
                  </div>
                  <!-- النص -->
                  <div style="flex:1;min-width:0">
                    <div class="feat-label" style="font-size:13px;font-weight:700;color:<?= $isOn ? '#0c1b36' : '#64748b' ?>;transition:color .2s"><?= $label ?></div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= $hint ?></div>
                  </div>
                  <!-- مفتاح التبديل -->
                  <div class="toggle-sw" style="width:44px;height:24px;border-radius:50px;flex-shrink:0;
                       background:<?= $isOn ? "linear-gradient(135deg,{$fc1},{$fc2})" : '#e2e8f0' ?>;
                       position:relative;transition:background .25s">
                    <div class="toggle-knob" style="width:18px;height:18px;background:#fff;border-radius:50%;
                         position:absolute;top:3px;transition:left .25s;box-shadow:0 1px 4px rgba(0,0,0,.2);
                         left:<?= $isOn ? '23px' : '3px' ?>"></div>
                  </div>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

          <?php endforeach; ?>
          </div>
        </div><!-- /modal-body -->

        <!-- فوتر المودال -->
        <div class="modal-footer" style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:16px 28px">
          <a href="packages.php" class="btn btn-outline-secondary rounded-3">
            <i class="fas fa-times me-1"></i>إلغاء
          </a>
          <button type="submit" class="btn text-white rounded-3 px-4 fw-bold"
                  style="background:linear-gradient(135deg,<?= $fc1 ?>,<?= $fc2 ?>);border:none">
            <i class="fas fa-save me-2"></i>حفظ المزايا
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.feat-toggle:hover {
  border-color: <?= $fc1 ?> !important;
  box-shadow: 0 2px 12px <?= $fc1 ?>20;
}
</style>
<script>
document.querySelectorAll('.feat-toggle').forEach(function(label) {
  label.addEventListener('click', function(e) {
    if (e.target.tagName === 'INPUT') return;
    e.preventDefault(); // منع المتصفح من تفعيل الـ checkbox مرة ثانية
    var cb  = label.querySelector('input[type=checkbox]');
    var sw  = label.querySelector('.toggle-sw');
    var knob= label.querySelector('.toggle-knob');
    var ico = label.querySelector('.feat-icon');
    var lbl = label.querySelector('.feat-label');

    cb.checked = !cb.checked;
    var on = cb.checked;
    var c1 = '<?= $fc1 ?>', c2 = '<?= $fc2 ?>';

    label.style.borderColor   = on ? c1 : '#e2e8f0';
    label.style.background    = on ? 'linear-gradient(135deg,'+c1+'08,'+c2+'12)' : '#fff';
    sw.style.background       = on ? 'linear-gradient(135deg,'+c1+','+c2+')' : '#e2e8f0';
    knob.style.left           = on ? '23px' : '3px';
    ico.style.background      = on ? 'linear-gradient(135deg,'+c1+','+c2+')' : '#f1f5f9';
    ico.style.color           = on ? '#fff' : '#94a3b8';
    lbl.style.color           = on ? '#0c1b36' : '#64748b';
  });
});
</script>
<?php endif; ?>


<!-- ══ مودال إضافة/تعديل الباقة ══ -->
<div class="modal fade" id="pkgModal" tabindex="-1">
  <div class="modal-dialog modal-md">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden">
      <div class="modal-header" style="background:linear-gradient(135deg,#0c1b36,#1a3a6e);border:none;padding:20px 24px">
        <h5 class="modal-title text-white fw-bold">
          <i class="fas fa-box-open me-2 text-warning"></i>
          <?= $edit ? 'تعديل الباقة' : 'إضافة باقة جديدة' ?>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="form_type" value="package">
        <div class="modal-body p-4">
          <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
          <div class="mb-3">
            <label class="form-label fw-bold">اسم الباقة *</label>
            <input type="text" name="name" class="form-control" required
                   value="<?= e($edit['name'] ?? '') ?>" placeholder="مثال: الاحترافية">
          </div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label fw-bold">السعر الشهري (ر.س)</label>
              <input type="number" name="price_monthly" class="form-control" step="0.01"
                     value="<?= $edit['price_monthly'] ?? '' ?>" placeholder="499">
            </div>
            <div class="col-6">
              <label class="form-label fw-bold">السعر السنوي (ر.س)</label>
              <input type="number" name="price_yearly" class="form-control" step="0.01"
                     value="<?= $edit['price_yearly'] ?? '' ?>" placeholder="4990">
            </div>
          </div>
          <div class="form-check form-switch mt-3">
            <input class="form-check-input" type="checkbox" name="is_active" id="pkgActive"
                   <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="pkgActive">الباقة نشطة وتظهر للعملاء</label>
          </div>
          <?php if (!$edit): ?>
          <div class="alert mt-3 rounded-3" style="background:#eff6ff;border:1px solid #bfdbfe;font-size:12px;color:#1e40af">
            <i class="fas fa-info-circle me-1"></i>
            بعد إضافة الباقة، اضغط <strong>تخصيص المزايا</strong> لضبط الحدود والوحدات المتاحة.
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer border-top" style="padding:16px 24px">
          <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn text-white rounded-3 px-4 fw-bold"
                  style="background:linear-gradient(135deg,#0c1b36,#1a3a6e);border:none">
            <i class="fas fa-save me-2"></i>حفظ الباقة
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($edit): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ new bootstrap.Modal(document.getElementById('pkgModal')).show(); });</script>
<?php endif; ?>

<?php if ($editFeatPkg): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ new bootstrap.Modal(document.getElementById('featModal')).show(); });</script>
<?php endif; ?>

<script>
function togglePkg(id) {
  var sw   = document.getElementById('sw_'   + id);
  var knob = document.getElementById('knob_' + id);
  var lbl  = document.getElementById('lbl_'  + id);
  var card = document.getElementById('card_' + id);
  if (!sw) return;

  var nowActive = sw.dataset.active === '1';
  var newActive = !nowActive;

  // تحديث الواجهة فوراً
  sw.dataset.active          = newActive ? '1' : '0';
  sw.style.background        = newActive ? 'rgba(134,239,172,.9)' : 'rgba(255,255,255,.2)';
  sw.title                   = newActive ? 'إخفاء الباقة' : 'إظهار الباقة';
  knob.style.left            = newActive ? '23px' : '3px';
  lbl.textContent            = newActive ? '● ظاهرة للعملاء' : '○ مخفية';
  lbl.style.color            = newActive ? '#86efac' : 'rgba(255,255,255,.45)';
  card.style.opacity         = newActive ? '1' : '.55';

  // حفظ في الخادم
  var fd = new FormData();
  fd.append('toggle_pkg', id);
  fetch('packages.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .catch(function(){
      // استعادة الحالة السابقة عند الخطأ
      sw.dataset.active  = nowActive ? '1' : '0';
      sw.style.background= nowActive ? 'rgba(134,239,172,.9)' : 'rgba(255,255,255,.2)';
      knob.style.left    = nowActive ? '23px' : '3px';
      lbl.textContent    = nowActive ? '● ظاهرة للعملاء' : '○ مخفية';
      lbl.style.color    = nowActive ? '#86efac' : 'rgba(255,255,255,.45)';
      card.style.opacity = nowActive ? '1' : '.55';
    });
}
</script>

<?php include '../includes/admin_footer.php'; ?>
