<?php
/**
 * الموديولات الإضافية داخل الباقة المخصصة.
 * تُقرأ من جدول modules مباشرة، فأي موديول جديد يظهر تلقائياً كخيار في كل بُناة الباقة المخصصة
 * (التسجيل، صفحة الأسعار، ترقية المكتب). المفتاح داخل الباقة: mod_<module_key>.
 */

function custom_pkg_modules($conn) {
    $out = [];
    try {
        $r = $conn->query("SELECT module_key,name,icon,base_price FROM modules WHERE is_active=1 ORDER BY sort_order,id");
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
