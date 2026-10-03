<?php
/**
 * الموديولات الإضافية داخل الباقة المخصصة.
 * تُقرأ من جدول modules مباشرة، فأي موديول جديد يظهر تلقائياً كخيار في كل بُناة الباقة المخصصة
 * (التسجيل، صفحة الأسعار، ترقية المكتب). المفتاح داخل الباقة: mod_<module_key>.
 */

function custom_pkg_modules($conn) {
    $out = [];
    try {
        $r = $conn->query("SELECT module_key,name,description,icon,base_price FROM modules WHERE is_active=1 ORDER BY sort_order,id");
        if ($r) while ($m = $r->fetch_assoc()) $out[] = $m;
    } catch (\Throwable $e) {}
    return $out;
}

/** يحقن الموديولات في مصفوفة الميزات بنفس شكل صفوف feature_prices (وفي قائمة المفاتيح إن وُجدت) */
function custom_pkg_inject_modules($conn, array &$fp, ?array &$keys = null) {
    foreach (custom_pkg_modules($conn) as $m) {
        $k = 'mod_' . $m['module_key'];
        $fp[$k] = [
            'feature_key'   => $k,
            'feature_label' => $m['name'],
            'feature_icon'  => $m['icon'] ?: 'puzzle-piece',
            'price_monthly' => 0,
            'price_yearly'  => (float)$m['base_price'],
            'is_module'     => 1,
        ];
        if ($keys !== null) $keys[] = $k;
    }
}

/** مفتاح => اسم، لعرض أسماء الموديولات في واجهات المراجعة */
function custom_pkg_label_map($conn) {
    $map = [];
    foreach (custom_pkg_modules($conn) as $m) $map['mod_' . $m['module_key']] = $m['name'];
    return $map;
}

/** عند اعتماد طلب باقة مخصصة: يفعّل الموديولات المختارة لهذا المكتب مرتبطة بالباقة الجديدة (فقط المفاتيح الموجودة فعلاً في الكتالوج) */
function custom_pkg_enable_modules($conn, $office_id, array $cf, $admin_id = 0, $package_id = 0) {
    $valid = [];
    foreach (custom_pkg_modules($conn) as $m) $valid['mod_' . $m['module_key']] = $m['module_key'];
    $office_id = (int)$office_id;
    $admin_id  = (int)$admin_id;
    $package_id = (int)$package_id;
    foreach ($cf as $fkey => $enabled) {
        if (!$enabled || !isset($valid[$fkey])) continue;
        $mk = $conn->real_escape_string($valid[$fkey]);
        // مرتبط بالباقة: يتوقف عند تغيير باقة المكتب. إن كان مفعَّلاً أصلاً بشكل مستقل (متجر/أدمن) نتركه مستقلاً.
        $conn->query("INSERT INTO office_modules (office_id,module_key,is_enabled,enabled_by,package_id)
            VALUES ($office_id,'$mk',1," . ($admin_id ?: 'NULL') . "," . ($package_id ?: 'NULL') . ")
            ON DUPLICATE KEY UPDATE
              package_id = IF(is_enabled=1 AND package_id IS NULL, NULL, VALUES(package_id)),
              is_enabled = 1, enabled_by = " . ($admin_id ?: 'NULL'));
    }
}

/* ════════ ميزات الباقة غير المضمّنة كإضافات في متجر الموديولات ════════
 * الميزة (has_*) غير الموجودة في باقة المكتب تظهر في المتجر بسعرها من feature_prices،
 * وتُفعَّل بنفس مسار الطلب/الاعتماد عبر صف في office_modules بمفتاح الميزة نفسه (يقرأه hasFeature). */

/** هل ميزة باقة المكتب مضمّنة في باقته؟ — نفس منطق hasFeature: غياب الصف = مضمّنة */
function pkg_includes_feature($conn, $oid, $key) {
    $oid = (int)$oid; $k = $conn->real_escape_string($key);
    $q = $conn->query("SELECT pf.feature_value FROM offices o
        JOIN package_features pf ON pf.package_id=o.package_id
        WHERE o.id=$oid AND pf.feature_key='$k' LIMIT 1");
    if (!$q) return true;
    $row = $q->fetch_assoc();
    return $row ? ($row['feature_value'] !== '0' && $row['feature_value'] !== '') : true;
}

/** ميزات غير مضمّنة في باقة المكتب، بشكل مطابق لصفوف modules ليعرضها المتجر بنفس البطاقة */
function store_feature_items($conn, $oid) {
    $items = [];
    try {
        $r = $conn->query("SELECT feature_key,feature_label,feature_icon,price_yearly FROM feature_prices
            WHERE feature_key LIKE 'has\\_%' AND is_active=1 ORDER BY sort_order");
        if ($r) while ($f = $r->fetch_assoc()) {
            if (pkg_includes_feature($conn, $oid, $f['feature_key'])) continue;
            $items[] = [
                'module_key'  => $f['feature_key'],
                'name'        => $f['feature_label'],
                'description' => 'ميزة غير مضمّنة في باقتك الحالية — فعّلها كإضافة',
                'icon'        => $f['feature_icon'] ?: 'star',
                'base_price'  => (float)$f['price_yearly'],
                'is_feature'  => 1,
            ];
        }
    } catch (\Throwable $e) {}
    return $items;
}

/** يجلب عنصر متجر بمفتاحه: موديول من الكتالوج، أو ميزة غير مضمّنة في باقة المكتب */
function store_find_item($conn, $oid, $key) {
    $k = $conn->real_escape_string($key);
    $m = $conn->query("SELECT * FROM modules WHERE module_key='$k' AND is_active=1 LIMIT 1")->fetch_assoc();
    if ($m) return $m;
    foreach (store_feature_items($conn, $oid) as $f) if ($f['module_key'] === $key) return $f;
    return null;
}

/** اسم الصفحة التي تفتحها كل ميزة (لزر «فتح») */
function feature_page_map() {
    return [
        'has_finance'=>'finance.php','has_invoices'=>'invoices.php','has_contracts'=>'contracts.php','has_poa'=>'contracts.php',
        'has_correspondence'=>'correspondence.php','has_library'=>'library.php','has_archive'=>'archive.php','has_ai'=>'ai_assistant.php',
        'has_reports'=>'reports.php','has_api'=>'api_keys.php','has_precedents'=>'precedents.php','has_digital_services'=>'digital_services.php',
    ];
}

/** مفتاح => اسم لكل ميزات has_* (لعرض أسماء الطلبات في لوحة الأدمن) */
function store_feature_label_map($conn) {
    $map = [];
    try {
        $r = $conn->query("SELECT feature_key,feature_label FROM feature_prices WHERE feature_key LIKE 'has\\_%'");
        if ($r) while ($f = $r->fetch_assoc()) $map[$f['feature_key']] = $f['feature_label'];
    } catch (\Throwable $e) {}
    return $map;
}

/* ════════ تعريفات الباقات المشتركة (الأدمن + الصفحات العامة) ════════
 * مصدر واحد لميزات الباقة الأساسية (has_*)، والموديولات الإضافية تُقرأ ديناميكياً من جدول modules،
 * فأي موديول يُضاف مستقبلاً يظهر تلقائياً: في تخصيص الباقة بالأدمن، وفي جدول الأسعار العام.
 * يُخزَّن تضمين الموديول في الباقة كصف package_features بمفتاح mod_<module_key> (غياب الصف = غير مضمّن). */

/** ميزات الباقة الأساسية: مفتاح => [الاسم, الأيقونة, الوصف] */
function pkg_core_features() {
    return [
        'has_finance'          => ['الشؤون المالية',      'wallet',              'تتبع الإيرادات والمصروفات'],
        'has_invoices'         => ['الفواتير',             'file-invoice',        'إنشاء وإرسال الفواتير'],
        'has_contracts'        => ['العقود',               'file-signature',      'إدارة العقود القانونية'],
        'has_poa'              => ['الوكالات',             'stamp',               'إدارة وكالات التفويض'],
        'has_correspondence'   => ['الصادر والوارد',       'envelope',            'إدارة المراسلات الرسمية'],
        'has_library'          => ['المكتبة القانونية',    'book-open',           'الأنظمة والنماذج القانونية'],
        'has_archive'          => ['الأرشيف',              'archive',             'رفع وحفظ الملفات'],
        'has_ai'               => ['المساعد الذكي',        'robot',               'مساعد AI قانوني متخصص'],
        'has_reports'          => ['التقارير المتقدمة',    'chart-bar',           'صفحة تقارير شاملة (قضايا، جلسات، مهام، عملاء، مالية) بفلترة من/إلى تاريخ + أداء الفريق'],
        'has_api'              => ['API',                  'code',                'وصول لواجهة برمجة التطبيقات لدمج الأنظمة الخارجية'],
        'has_precedents'       => ['السوابق القضائية',     'scale-balanced',      'قاعدة بيانات السوابق والأحكام القضائية'],
        'has_digital_services' => ['الخدمات الرقمية',      'hand-holding-dollar', 'كتالوج الخدمات الرقمية وطلبات العملاء'],
    ];
}

/** تصنيف الموديولات في جدول الأسعار — موديول غير مصنَّف (جديد مستقبلاً) يقع تلقائياً تحت «موديولات أخرى» */
function pkg_module_categories() {
    return [
        'القضايا والجلسات' => ['case_enforcement','session_prep','session_clash','case_closure_review','case_workflows','court_sms_import','case_checklists','petition_wizard','ai_case_intake','legal_deadlines','conflict_check'],
        'العملاء والتواصل' => ['client_portal','client_surveys','client_doc_expiry','client_periodic_report','client_action_link','client_silence_alert','client_source_attribution','consultation_booking','fee_quote','support_tickets','reputation_management','external_collab'],
        'المالية والأتعاب' => ['time_tracking','recurring_billing','financial_approvals','trust_accounts','fee_shortfall','pro_bono_tracker'],
        'الإدارة والفريق'  => ['hr','multi_branch','meeting_rooms','absence_delegation','workload_balancer','staff_performance_review','lawyer_daily_journal','lawyer_license_expiry','referral_network','expert_witness'],
        'الذكاء والأدوات'  => ['ai_legal','bi_dashboard','global_search','data_export','calendar_sync','esignature','regulatory_alerts','marketing'],
    ];
}

/** الموديولات الفعّالة مجمَّعة بتصنيفها: [تصنيف => [صفوف modules]] */
function pkg_modules_grouped($conn) {
    $catOf = [];
    foreach (pkg_module_categories() as $cat => $keys) foreach ($keys as $k) $catOf[$k] = $cat;
    $out = [];
    foreach (pkg_module_categories() as $cat => $_) $out[$cat] = [];
    foreach (custom_pkg_modules($conn) as $m) $out[$catOf[$m['module_key']] ?? 'موديولات أخرى'][] = $m;
    foreach ($out as $cat => $rows) if (!$rows) unset($out[$cat]);
    return $out;
}

/** 0 أو ≥999 = غير محدود (0 هو القيمة التي يُدخلها الأدمن للدلالة على «بلا حدّ») */
function pkg_is_unlimited($v) {
    $v = (int)$v;
    return $v <= 0 || $v >= 999;
}

/** الحدّ الفعلي للباقة: المصدر الأول package_features (الذي يحفظه الأدمن ويقرؤه النظام فعلياً)، ثم عمود packages القديم */
function pkg_effective_limit(array $pkgRow, array $feats, $key) {
    if (isset($feats[$key]) && $feats[$key] !== '') return (int)$feats[$key];
    return (int)($pkgRow[$key] ?? 0);
}

/**
 * فهرس الباقات العامة لصفحتَي الرئيسية والأسعار: الباقات الظاهرة + مزاياها + الموديولات المضمّنة في كل باقة.
 * الباقات المخصصة لمكتب بعينه («مخصصة — …») لا تُعرض علناً.
 */
function pkg_public_catalog($conn) {
    $packages = [];
    $res = $conn->query("SELECT * FROM packages WHERE is_active=1 AND name NOT LIKE 'مخصصة —%' ORDER BY price_yearly ASC, id ASC");
    if ($res) while ($p = $res->fetch_assoc()) $packages[$p['id']] = $p;

    $feats = [];
    if ($packages) {
        $ids = implode(',', array_map('intval', array_keys($packages)));
        $fr = $conn->query("SELECT package_id,feature_key,feature_value FROM package_features WHERE package_id IN ($ids)");
        if ($fr) while ($f = $fr->fetch_assoc()) $feats[$f['package_id']][$f['feature_key']] = $f['feature_value'];
    }

    $core    = pkg_core_features();
    $grouped = pkg_modules_grouped($conn);
    $modKeys = [];
    foreach ($grouped as $rows) foreach ($rows as $m) $modKeys[] = $m['module_key'];

    $list = []; $i = 0;
    foreach ($packages as $id => $p) {
        $pf = $feats[$id] ?? [];
        $coreOn = []; foreach ($core as $k => $_) if (($pf[$k] ?? '0') === '1') $coreOn[] = $k;
        $modOn  = []; foreach ($modKeys as $k) if (($pf['mod_' . $k] ?? '0') === '1') $modOn[] = $k;
        $list[] = [
            'id'       => (int)$id,
            'name'     => $p['name'],
            'price'    => (float)$p['price_yearly'],
            'featured' => ($i === 1),
            'users'    => pkg_effective_limit($p, $pf, 'max_users'),
            'cases'    => pkg_effective_limit($p, $pf, 'max_cases'),
            'clients'  => (int)($pf['max_clients'] ?? 0),
            'storage'  => (int)($pf['storage_mb'] ?? 0),
            'feats'    => $pf,
            'core_on'  => $coreOn,
            'mod_on'   => $modOn,
        ];
        $i++;
    }
    return ['packages' => $list, 'core' => $core, 'groups' => $grouped, 'module_total' => count($modKeys)];
}

/**
 * يزامن عمودَي packages.max_users / max_cases مع مزايا الباقة (package_features = المصدر الفعلي).
 * كانت صيغة قديمة تحوّل 0 («غير محدود») إلى 1 في packages.max_users فتظهر الباقة «مستخدم واحد».
 * استعلام شرطي: لا يكتب شيئاً إن كانت القيم متطابقة أصلاً.
 */
function pkg_sync_limits($conn) {
    try {
        foreach (['max_users', 'max_cases'] as $lk) {
            $conn->query("UPDATE packages p JOIN package_features pf ON pf.package_id=p.id AND pf.feature_key='$lk'
                SET p.$lk = CAST(pf.feature_value AS UNSIGNED)
                WHERE pf.feature_value REGEXP '^[0-9]+$' AND p.$lk <> CAST(pf.feature_value AS UNSIGNED)");
        }
    } catch (\Throwable $e) {}
}
