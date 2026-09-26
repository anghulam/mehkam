<?php
if (!ini_get('date.timezone')) date_default_timezone_set('Asia/Riyadh');
if (date_default_timezone_get() === 'UTC') date_default_timezone_set('Asia/Riyadh');
if (session_status() === PHP_SESSION_NONE) session_start();

/* ── Auth helpers ─────────────────────────────────────────── */
function isLoggedIn()  { return isset($_SESSION['user_id']); }
function isAdmin()     { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }
function isOffice()    { return isset($_SESSION['role']) && in_array($_SESSION['role'], ['office_owner','lawyer','secretary','trainee']); }
function requireAdmin()  { if (!isLoggedIn() || !isAdmin())  { header("Location: ../index.php"); exit; } }
function requireOffice() {
    if (!isLoggedIn() || !isOffice()) { header("Location: ../index.php"); exit; }

    // انتهاء التجربة تلقائياً
    global $conn;
    if ($conn && isset($_SESSION['office_id'])) {
        $oid = (int)$_SESSION['office_id'];
        $r = $conn->query("SELECT status, subscription_end FROM offices WHERE id=$oid LIMIT 1");
        if ($r && $row = $r->fetch_assoc()) {
            if ($row['status'] === 'trial' && !empty($row['subscription_end'])
                && strtotime($row['subscription_end']) < strtotime('today')) {
                $conn->query("UPDATE offices SET status='suspended' WHERE id=$oid");
                $conn->query("UPDATE users SET is_active=0 WHERE office_id=$oid");
                session_destroy();
                header("Location: ../index.php?trial_expired=1"); exit;
            }
        }
        _mkOneTimeCaseScopeBackfill($conn, $oid);
    }
}

/**
 * ترحيل لمرة واحدة لكل مكتب: بما أن نطاق البيانات المقيَّد صار إلزامياً تلقائياً لكل
 * موظف غير المالك، نمنح كل موظف حالي ما عنده أي إسناد قضايا وصولاً لكل قضايا مكتبه
 * الحالية — حتى لا تنفرغ قائمة قضاياه فجأة بعد هذا التفعيل التلقائي. القضايا الجديدة
 * بعد هذا الترحيل تحتاج إسناداً صريحاً من صفحة إدارة المستخدمين كالمعتاد.
 */
function _mkOneTimeCaseScopeBackfill($conn, $oid) {
    static $checked = [];
    if (isset($checked[$oid])) return;
    $checked[$oid] = true;
    try {
        $conn->query("ALTER TABLE office_settings ADD COLUMN case_scope_migrated TINYINT(1) NOT NULL DEFAULT 0");
    } catch (\Throwable $e) {}
    try {
        $row = $conn->query("SELECT case_scope_migrated FROM office_settings WHERE office_id=$oid LIMIT 1")->fetch_assoc();
        if ($row && !empty($row['case_scope_migrated'])) return;
        $conn->query("INSERT IGNORE INTO case_assignments (case_id, user_id)
            SELECT c.id, u.id FROM cases c
            JOIN users u ON u.office_id = c.office_id
            WHERE c.office_id = $oid
              AND u.role NOT IN ('office_owner','admin')
              AND NOT EXISTS (SELECT 1 FROM case_assignments ca WHERE ca.user_id = u.id)");
        $conn->query("INSERT INTO office_settings (office_id, case_scope_migrated) VALUES ($oid, 1)
            ON DUPLICATE KEY UPDATE case_scope_migrated = 1");
    } catch (\Throwable $e) {}
}
function requireLogin()  { if (!isLoggedIn())                { header("Location: ../index.php"); exit; } }

/* ── Output escaping ──────────────────────────────────────── */
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/* ── Package feature checker ──────────────────────────────── */
/**
 * Check if an office has a specific feature enabled in their package.
 * Falls back to TRUE if package_features table doesn't exist yet (graceful degradation).
 */
function hasFeature($conn, $office_id, $feature) {
    static $_feat_cache = [];
    $office_id = (int)$office_id;
    $key = $office_id . ':' . $feature;
    if (isset($_feat_cache[$key])) return $_feat_cache[$key];

    $f = $conn->real_escape_string($feature);
    $q = $conn->query("
        SELECT pf.feature_value
        FROM offices o
        JOIN packages pkg  ON o.package_id    = pkg.id
        JOIN package_features pf ON pkg.id    = pf.package_id
        WHERE o.id = $office_id AND pf.feature_key = '$f'
        LIMIT 1
    ");
    if (!$q) { $_feat_cache[$key] = true; return true; } // table missing → allow all
    $row = $q->fetch_assoc();
    $result = $row ? ($row['feature_value'] !== '0' && $row['feature_value'] !== '') : true;
    // ميزة غير مضمّنة في الباقة لكن اشتراها المكتب كإضافة من متجر الموديولات
    if (!$result && strpos($feature, 'has_') === 0) {
        try {
            $oq = $conn->query("SELECT 1 FROM office_modules WHERE office_id=$office_id AND module_key='$f' AND is_enabled=1 LIMIT 1");
            if ($oq && $oq->num_rows) $result = true;
        } catch (\Throwable $e) {}
    }
    $_feat_cache[$key] = $result;
    return $result;
}

/**
 * Get a numeric limit for a feature (0 = unlimited).
 */
function getFeatureLimit($conn, $office_id, $feature) {
    static $_lim_cache = [];
    $office_id = (int)$office_id;
    $key = $office_id . ':' . $feature;
    if (isset($_lim_cache[$key])) return $_lim_cache[$key];

    $f = $conn->real_escape_string($feature);
    $q = $conn->query("
        SELECT pf.feature_value
        FROM offices o
        JOIN packages pkg ON o.package_id   = pkg.id
        JOIN package_features pf ON pkg.id  = pf.package_id
        WHERE o.id = $office_id AND pf.feature_key = '$f'
        LIMIT 1
    ");
    if (!$q) { $_lim_cache[$key] = 0; return 0; }
    $row = $q->fetch_assoc();
    $result = $row ? (int)$row['feature_value'] : 0;
    $_lim_cache[$key] = $result;
    return $result;
}

/* ── موديولات إضافية (Add-ons) لكل مكتب — مستقلة عن الباقة ── */
/**
 * موديولات إضافية يُفعِّلها الأدمن يدوياً لمكتب معيّن (بغضّ النظر عن باقته)،
 * مثال: «إدارة التسويق». على عكس hasFeature (تفشل بأمان = مفعّلة افتراضياً
 * توافقاً مع الميزات القديمة)، الموديولات هنا تفشل بأمان = **معطّلة افتراضياً**
 * لأنها إضافات مدفوعة/اختيارية جديدة، لا ميزات أساسية للمنصة.
 */
function hasModule($conn, $office_id, $module_key) {
    static $_mod_cache = [];
    $office_id = (int)$office_id;
    $key = $office_id . ':' . $module_key;
    if (isset($_mod_cache[$key])) return $_mod_cache[$key];

    $m = $conn->real_escape_string($module_key);
    // موديول فُعِّل عبر باقة مخصصة (package_id غير فارغ) يتوقف عند تغيير باقة المكتب
    try {
        $q = $conn->query("SELECT om.is_enabled FROM office_modules om JOIN offices o ON o.id=om.office_id
            WHERE om.office_id=$office_id AND om.module_key='$m' AND (om.package_id IS NULL OR om.package_id=o.package_id) LIMIT 1");
    } catch (\Throwable $e) { $q = false; }
    if (!$q) { // عمود package_id غير موجود بعد → الاستعلام القديم
        try {
            $q = $conn->query("SELECT is_enabled FROM office_modules WHERE office_id=$office_id AND module_key='$m' LIMIT 1");
        } catch (\Throwable $e) { $q = false; }
    }
    if (!$q) { $_mod_cache[$key] = false; return false; } // الجدول غير موجود بعد → معطّل بأمان
    $row = $q->fetch_assoc();
    $result = $row ? (int)$row['is_enabled'] === 1 : false;
    $_mod_cache[$key] = $result;
    return $result;
}

/**
 * Check if office can add more records of a given type based on package limits.
 * Returns true if allowed.
 */
function canAddMore($conn, $office_id, $type) {
    $office_id = (int)$office_id;
    $featureMap = [
        'cases'   => ['max_cases',   "SELECT COUNT(*) c FROM cases   WHERE office_id=$office_id"],
        'clients' => ['max_clients', "SELECT COUNT(*) c FROM clients WHERE office_id=$office_id"],
        'users'   => ['max_users',   "SELECT COUNT(*) c FROM users   WHERE office_id=$office_id AND is_active=1"],
    ];
    if (!isset($featureMap[$type])) return true;
    [$featKey, $sql] = $featureMap[$type];
    $limit = getFeatureLimit($conn, $office_id, $featKey);
    if ($limit === 0) return true; // unlimited
    $current = (int)$conn->query($sql)->fetch_assoc()['c'];
    return $current < $limit;
}

/**
 * Get storage used by an office in MB.
 */
function getStorageUsedMB($conn, $office_id) {
    $office_id = (int)$office_id;
    $q = $conn->query("SELECT IFNULL(SUM(file_size),0) s FROM file_attachments WHERE office_id=$office_id");
    if (!$q) return 0;
    return round($q->fetch_assoc()['s'] / (1024 * 1024), 2);
}

/* ── Session / office helpers ─────────────────────────────── */
function currentOfficeId() {
    return (int)($_SESSION['office_id'] ?? 0);
}
function currentUserId() {
    return (int)($_SESSION['user_id'] ?? 0);
}
function currentRole() {
    return $_SESSION['role'] ?? '';
}

/* ── Status badges ────────────────────────────────────────── */
function statusBadge($status) {
    $map = [
        'active'      => ['bg-success bg-opacity-10 text-success', 'نشط'],
        'trial'       => ['bg-warning bg-opacity-10 text-warning', 'تجريبي'],
        'expired'     => ['bg-danger bg-opacity-10 text-danger',   'منتهي'],
        'suspended'   => ['bg-secondary bg-opacity-10 text-secondary','موقوف'],
        'closed'      => ['bg-secondary bg-opacity-10 text-secondary','مغلقة'],
        'won'         => ['bg-success bg-opacity-10 text-success',  'مكسوبة'],
        'lost'        => ['bg-danger bg-opacity-10 text-danger',    'خاسرة'],
        'settled'     => ['bg-info bg-opacity-10 text-info',        'متسوية'],
        'pending'     => ['bg-warning bg-opacity-10 text-warning',  'معلقة'],
        'in_progress' => ['bg-primary bg-opacity-10 text-primary',  'جارية'],
        'completed'   => ['bg-success bg-opacity-10 text-success',  'مكتملة'],
        'cancelled'   => ['bg-danger bg-opacity-10 text-danger',    'ملغاة'],
        'scheduled'   => ['bg-info bg-opacity-10 text-info',        'مجدولة'],
        'held'        => ['bg-success bg-opacity-10 text-success',  'عُقدت'],
        'postponed'   => ['bg-warning bg-opacity-10 text-warning',  'مؤجلة'],
        'draft'       => ['bg-secondary bg-opacity-10 text-secondary','مسودة'],
        'incoming'    => ['bg-primary bg-opacity-10 text-primary',  'وارد'],
        'outgoing'    => ['bg-success bg-opacity-10 text-success',  'صادر'],
        'income'      => ['bg-success bg-opacity-10 text-success',  'إيراد'],
        'expense'     => ['bg-danger bg-opacity-10 text-danger',    'مصروف'],
        'new'         => ['bg-danger bg-opacity-10 text-danger',    'جديد'],
        'read'        => ['bg-secondary bg-opacity-10 text-secondary','مقروء'],
        'replied'     => ['bg-success bg-opacity-10 text-success',  'رُدّ عليه'],
        'archived'    => ['bg-dark bg-opacity-10 text-dark',        'مؤرشف'],
        'sent'        => ['bg-info bg-opacity-10 text-info',        'مُرسلة'],
        'paid'        => ['bg-success bg-opacity-10 text-success',  'مدفوعة'],
        'overdue'     => ['bg-danger bg-opacity-10 text-danger',    'متأخرة'],
        'revoked'     => ['bg-danger bg-opacity-10 text-danger',    'مُلغاة'],
    ];
    $s = $map[$status] ?? ['bg-secondary bg-opacity-10 text-secondary', $status];
    return "<span class='badge {$s[0]}'>{$s[1]}</span>";
}

function priorityBadge($p) {
    $map = [
        'low'    => ['bg-secondary bg-opacity-10 text-secondary', 'منخفضة'],
        'medium' => ['bg-primary bg-opacity-10 text-primary',     'متوسطة'],
        'high'   => ['bg-warning bg-opacity-10 text-warning',     'عالية'],
        'urgent' => ['bg-danger bg-opacity-10 text-danger',       'عاجلة'],
    ];
    $s = $map[$p] ?? ['bg-secondary bg-opacity-10 text-secondary', $p];
    return "<span class='badge {$s[0]}'>{$s[1]}</span>";
}

/* ── File type icon helper ────────────────────────────────── */
function fileTypeIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $map = [
        'pdf'  => ['pdf',   'fa-file-pdf'],
        'doc'  => ['word',  'fa-file-word'],
        'docx' => ['word',  'fa-file-word'],
        'xls'  => ['excel', 'fa-file-excel'],
        'xlsx' => ['excel', 'fa-file-excel'],
        'jpg'  => ['img',   'fa-file-image'],
        'jpeg' => ['img',   'fa-file-image'],
        'png'  => ['img',   'fa-file-image'],
        'gif'  => ['img',   'fa-file-image'],
        'zip'  => ['other', 'fa-file-zipper'],
        'rar'  => ['other', 'fa-file-zipper'],
    ];
    return $map[$ext] ?? ['other', 'fa-file'];
}

/* ── Format file size ─────────────────────────────────────── */
function formatSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2)       . ' KB';
    return $bytes . ' B';
}

/**
 * يوحّد مدخل ملفات $_FILES['x'] إلى مصفوفة من عناصر ملف مفرد،
 * سواء أُرسل حقل واحد (name="x") أو عدة ملفات (name="x[]").
 * يتجاهل أي عنصر بلا اسم ملف (خانة فارغة لم تُملأ).
 * $labelsInput (اختياري): مصفوفة $_POST['x_label'] الموازية — اسم مخصّص
 * يكتبه المستخدم لكل ملف؛ إن وُجد يستبدل اسم الملف الأصلي (مع الحفاظ
 * على امتداده الحقيقي) قبل إرجاعه، فتلتقطه storage_upload() تلقائياً.
 */
function normalizeFilesArray($filesInput, $labelsInput = null) {
    $out = [];
    if (!is_array($filesInput) || !isset($filesInput['name'])) return $out;
    if (is_array($filesInput['name'])) {
        foreach ($filesInput['name'] as $i => $name) {
            if ($name === '' || $name === null) continue;
            $finalName = $filesInput['name'][$i];
            if (is_array($labelsInput) && trim((string)($labelsInput[$i] ?? '')) !== '') {
                $finalName = applyCustomFileLabel($finalName, $labelsInput[$i]);
            }
            $out[] = [
                'name'     => $finalName,
                'type'     => $filesInput['type'][$i] ?? '',
                'tmp_name' => $filesInput['tmp_name'][$i] ?? '',
                'error'    => $filesInput['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $filesInput['size'][$i] ?? 0,
            ];
        }
    } elseif ($filesInput['name'] !== '') {
        $item = $filesInput;
        if (is_array($labelsInput) && trim((string)($labelsInput[0] ?? '')) !== '') {
            $item['name'] = applyCustomFileLabel($filesInput['name'], $labelsInput[0]);
        }
        $out[] = $item;
    }
    return $out;
}

/** يدمج اسماً مخصّصاً كتبه المستخدم مع الامتداد الحقيقي للملف المرفوع */
function applyCustomFileLabel($originalName, $customLabel) {
    $customLabel = trim((string)$customLabel);
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    if ($ext !== '') {
        // احذف الامتداد إن كرّره المستخدم بنفسه داخل الاسم المخصّص
        $customLabel = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '', $customLabel);
        return $customLabel . '.' . $ext;
    }
    return $customLabel;
}

/* ── Relative time ────────────────────────────────────────── */
function timeAgo($datetime) {
    $now  = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);
    if ($diff->y > 0) return "منذ {$diff->y} سنة";
    if ($diff->m > 0) return "منذ {$diff->m} شهر";
    if ($diff->d > 0) return "منذ {$diff->d} يوم";
    if ($diff->h > 0) return "منذ {$diff->h} ساعة";
    if ($diff->i > 0) return "منذ {$diff->i} دقيقة";
    return "الآن";
}

/* ── الصلاحيات ────────────────────────────────────────────── */
$GLOBALS['_perm_sections'] = ['cases','clients','sessions','tasks','contracts','poa',
    'correspondence','finance','invoices','library','archive','precedents','services','users','reports','marketing'];
$GLOBALS['_perm_actions']  = ['view','add','edit','delete','approve','export'];

/**
 * اقتراح صلاحيات مبدئي لدور — يُستخدم فقط كـ "زر اقتراح" أو بذرة أولى،
 * وليس افتراضاً صامتاً وقت التشغيل. القرار النهائي لمالك المكتب.
 */
function suggestedPerms($role) {
    $S  = $GLOBALS['_perm_sections'];
    $all = $GLOBALS['_perm_actions'];
    $ve  = ['view','add','edit'];
    if ($role === 'office_owner' || $role === 'admin') return array_fill_keys($S, $all);
    if ($role === 'lawyer') return [
        'cases'=>$ve,'clients'=>$ve,'sessions'=>$ve,'tasks'=>$ve,'contracts'=>$ve,'poa'=>$ve,
        'correspondence'=>$ve,'library'=>$ve,'precedents'=>$ve,'archive'=>['view','add'],'services'=>[],
        'finance'=>['view'],'invoices'=>$ve,'reports'=>['view'],'users'=>[],
    ];
    if ($role === 'secretary') return [
        'cases'=>['view'],'clients'=>$ve,'sessions'=>$ve,'tasks'=>$ve,'correspondence'=>$ve,
        'contracts'=>['view'],'poa'=>['view'],'archive'=>['view','add'],'library'=>['view'],'services'=>[],
        'precedents'=>['view'],'finance'=>[],'invoices'=>[],'reports'=>[],'users'=>[],
    ];
    if ($role === 'trainee') return [
        'cases'=>['view'],'clients'=>['view'],'sessions'=>['view'],'tasks'=>['view','edit'],
        'contracts'=>['view'],'poa'=>['view'],'correspondence'=>['view'],
        'library'=>['view'],'precedents'=>['view'],'archive'=>['view'],'services'=>[],
        'finance'=>[],'invoices'=>[],'reports'=>[],'users'=>[],
    ];
    return array_fill_keys($S, []);
}
// توافق مع الاسم القديم
function defaultPerms($role) { return suggestedPerms($role); }

/** قالب صلاحيات الدور الذي حدّده مالك المكتب (من جدول office_role_perms) */
function roleTemplate($conn, $office_id, $role) {
    static $cache = [];
    $k = $office_id . ':' . $role;
    if (isset($cache[$k])) return $cache[$k];
    $out = null;
    try {
        $r = $conn->query("SELECT permissions FROM office_role_perms
            WHERE office_id=" . (int)$office_id . " AND role='" . $conn->real_escape_string($role) . "' LIMIT 1");
        if ($r && ($row = $r->fetch_assoc()) && $row['permissions'] !== null) {
            $d = json_decode($row['permissions'], true);
            if (is_array($d)) $out = $d;
        }
    } catch (\Throwable $e) {}
    // لم يضبط المالك القالب بعد → اقتراح مبدئي (يستطيع تعديله)
    if ($out === null) $out = suggestedPerms($role);
    return $cache[$k] = $out;
}

/** صلاحيات المستخدم الحالي — من users.permissions فقط (يحدّدها المالك) */
function myPerms() {
    static $p = null;
    if ($p !== null) return $p;
    $role = $_SESSION['role'] ?? '';
    if ($role === 'office_owner' || $role === 'admin') return $p = suggestedPerms($role);

    // تُقرأ الصلاحيات من قاعدة البيانات مباشرة (لا يُعتمَد على الجلسة المخزَّنة وقت
    // الدخول فقط) حتى يظهر أثر أي تعديل يجريه مالك المكتب على صلاحيات الموظف فوراً
    // في طلبه التالي، بلا حاجة لتسجيل خروج ودخول من جديد. static $p أعلاه يمنع تكرار
    // الاستعلام أكثر من مرة في نفس الطلب حتى لو استُدعيت can() عشرات المرات.
    global $conn;
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($conn && $uid) {
        try {
            $r = $conn->query("SELECT permissions FROM users WHERE id=$uid LIMIT 1");
            if ($r && $row = $r->fetch_assoc()) {
                $decoded = !empty($row['permissions']) ? json_decode($row['permissions'], true) : null;
                if (is_array($decoded)) {
                    $_SESSION['perms'] = $decoded; // إبقاء نسخة الجلسة متزامنة كاحتياط
                    return $p = $decoded;
                }
            }
        } catch (\Throwable $e) {}
    }
    // احتياط فقط: لو تعذّر الاتصال بقاعدة البيانات، استخدم آخر نسخة محفوظة بالجلسة
    return $p = (isset($_SESSION['perms']) && is_array($_SESSION['perms'])) ? $_SESSION['perms'] : [];
}

/** هل يملك المستخدم صلاحية؟  can('cases','edit') */
function can($section, $action = 'view') {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'office_owner' || $role === 'admin') return true;
    $mp = myPerms();
    return in_array($action, $mp[$section] ?? [], true);
}

/** يوقف الطلب إن لم تتوفر الصلاحية (للاستخدام في معالجات POST/GET) */
function requirePerm($section, $action, $redirect = null) {
    if (can($section, $action)) return;
    $redirect = $redirect ?: (basename($_SERVER['PHP_SELF']) . '?msg=denied');
    header('Location: ' . $redirect);
    exit;
}

/* ── نطاق البيانات (تقييد المستخدم بقضايا محددة) ──────────── */
function isRestricted() {
    static $r = null;
    if ($r !== null) return $r;
    // نطاق بيانات مقيَّد بالقضايا المُسندة إليه هو الافتراضي الإلزامي لأي دور غير
    // مالك المكتب/المشرف — لا يعتمد على خانة اختيارية يفعّلها المالك يدوياً.
    $role = $_SESSION['role'] ?? '';
    return $r = !($role === 'office_owner' || $role === 'admin');
}

/** جزء SQL يقيّد القضايا المرئية:  "$where .= caseScope('c');" */
function caseScope($alias = 'c') {
    if (!isRestricted()) return '';
    $uid = (int)($_SESSION['user_id'] ?? 0);
    return " AND {$alias}.id IN (SELECT case_id FROM case_assignments WHERE user_id=$uid)";
}

/**
 * جزء SQL يقيّد سجلات مالية (فواتير/معاملات) بالقضايا المُسندة للمستخدم المقيَّد فقط —
 * تُستخدم لمنع موظّف مُقيَّد النطاق من رؤية أرقام المكتب كاملة، فيرى فقط ما يخص قضاياه:
 * "$where .= finScope('inv');" على جدول له عمود case_id (invoices, transactions).
 */
function finScope($alias = '') {
    if (!isRestricted()) return '';
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $col = $alias ? "{$alias}.case_id" : 'case_id';
    return " AND $col IN (SELECT case_id FROM case_assignments WHERE user_id=$uid)";
}

function canSeeCase($conn, $case_id) {
    if (!isRestricted()) return true;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $cid = (int)$case_id;
    try {
        $r = $conn->query("SELECT 1 FROM case_assignments WHERE case_id=$cid AND user_id=$uid LIMIT 1");
        return $r && $r->num_rows > 0;
    } catch (\Throwable $e) { return true; }
}

/* ── سجل الإجراءات (Audit Log) ────────────────────────────── */
/**
 * تسجيل إجراء في activity_log.
 * $action: create|update|delete|status|approve|login|export ...
 * $entity: case|client|contract|poa|invoice|task|session|correspondence|user|payment ...
 */
function logAction($conn, $action, $entity, $entity_id = null, $summary = '') {
    if (!($conn instanceof mysqli)) return;
    $oid  = (int)($_SESSION['office_id'] ?? 0);
    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $uname= $_SESSION['full_name'] ?? '';
    if (!$oid) return;
    $a  = $conn->real_escape_string(substr($action, 0, 30));
    $e  = $conn->real_escape_string(substr($entity, 0, 30));
    $eid= $entity_id === null ? 'NULL' : (int)$entity_id;
    $su = $conn->real_escape_string(mb_substr((string)$summary, 0, 400));
    $un = $conn->real_escape_string(mb_substr((string)$uname, 0, 200));
    $ip = $conn->real_escape_string(substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45));
    try {
        $conn->query("INSERT INTO activity_log (office_id,user_id,user_name,action,entity,entity_id,summary,ip)
            VALUES ($oid, " . ($uid ?: 'NULL') . ", '$un', '$a', '$e', $eid, '$su', '$ip')");
    } catch (\Throwable $ex) {
        // الجدول غير موجود بعد — تجاهل بصمت
    }
}

/* ── Safe DB query with prepared statement shorthand ─────── */
function dbVal($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_row();
    return $row ? $row[0] : 0;
}

/* ══════════════════════════════════════════════════════════
   التقويم الهجري + تفضيل المستخدم
   ══════════════════════════════════════════════════════════ */

/** تفضيل التقويم للمستخدم الحالي: gregorian | hijri | both (الافتراضي: hijri-primary = both) */
function calPref() {
    static $pref = null;
    if ($pref !== null) return $pref;
    if (!empty($_SESSION['calendar_pref']) && in_array($_SESSION['calendar_pref'], ['gregorian','hijri','both'], true)) {
        return $pref = $_SESSION['calendar_pref'];
    }
    global $conn;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($conn instanceof mysqli && $uid) {
        try {
            $r = $conn->query("SELECT calendar_pref FROM users WHERE id=$uid LIMIT 1");
            if ($r && ($row = $r->fetch_assoc()) && in_array($row['calendar_pref'] ?? '', ['gregorian','hijri','both'], true)) {
                $_SESSION['calendar_pref'] = $row['calendar_pref'];
                return $pref = $row['calendar_pref'];
            }
        } catch (\Throwable $e) {}
    }
    return $pref = 'both'; // الافتراضي: الهجري أساسي مع الميلادي بجانبه
}

$GLOBALS['_hijri_months']  = ['','محرم','صفر','ربيع الأول','ربيع الآخر','جمادى الأولى','جمادى الآخرة','رجب','شعبان','رمضان','شوال','ذو القعدة','ذو الحجة'];
$GLOBALS['_ar_weekdays']   = ['الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت']; // date('w'): 0=أحد

/** اسم اليوم بالعربية من طابع زمني/تاريخ */
function dayName($date) {
    $ts = is_numeric($date) ? (int)$date : strtotime((string)$date);
    if (!$ts) return '';
    return $GLOBALS['_ar_weekdays'][(int)date('w', $ts)] ?? '';
}

/** [سنة, شهر, يوم] هجري من طابع زمني — أم القرى عبر intl إن توفّر، وإلا حساب جدولي */
function hijriParts($ts) {
    if (!is_numeric($ts)) $ts = strtotime((string)$ts);
    if (!$ts) return null;

    if (class_exists('IntlDateFormatter')) {
        try {
            $f = new IntlDateFormatter('en_US@calendar=islamic-umalqura',
                IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Asia/Riyadh',
                IntlDateFormatter::TRADITIONAL, 'yyyy-MM-dd');
            $s = $f->format((int)$ts);
            if ($s && preg_match('/^(\d+)-(\d+)-(\d+)/', $s, $m)) {
                return [(int)$m[1], (int)$m[2], (int)$m[3]];
            }
        } catch (\Throwable $e) {}
    }

    // حساب جدولي (بدون امتدادات) — خوارزمية كويتية، دقة ±1 يوم غالباً
    $y = (int)date('Y', $ts); $mo = (int)date('n', $ts); $d = (int)date('j', $ts);
    $jd = intdiv(1461 * ($y + 4800 + intdiv($mo - 14, 12)), 4)
        + intdiv(367 * ($mo - 2 - 12 * intdiv($mo - 14, 12)), 12)
        - intdiv(3 * intdiv($y + 4900 + intdiv($mo - 14, 12), 100), 4)
        + $d - 32075;
    $l = $jd - 1948440 + 10632;
    $n = intdiv($l - 1, 10631);
    $l = $l - 10631 * $n + 354;
    $j = intdiv(10985 - $l, 5316) * intdiv(50 * $l, 17719) + intdiv($l, 5670) * intdiv(43 * $l, 15238);
    $l = $l - intdiv(30 - $j, 15) * intdiv(17719 * $j, 50) - intdiv($j, 16) * intdiv(15238 * $j, 43) + 29;
    $hm = intdiv(24 * $l, 709);
    $hd = $l - intdiv(709 * $hm, 24);
    $hy = 30 * $n + $j - 30;
    return [$hy, $hm, $hd];
}

/** تاريخ هجري منسّق: "13 ربيع الأول 1448 هـ"، ومع $weekday: "السبت 13 ربيع الأول 1448 هـ" */
function hijriDate($date, $withDayNum = true, $weekday = false) {
    $p = hijriParts($date);
    if (!$p) return '';
    $mName = $GLOBALS['_hijri_months'][$p[1]] ?? $p[1];
    $s = ($withDayNum ? $p[2] . ' ' : '') . $mName . ' ' . $p[0] . ' هـ';
    return $weekday ? (dayName($date) . ' ' . $s) : $s;
}

/**
 * دالة العرض الموحّدة للتواريخ — تحترم تفضيل المستخدم.
 * الافتراضي: الهجري أساسي (مع اسم اليوم) والميلادي بين قوسين.
 * $withTime: يضيف الوقت.  $withDay: يضيف اسم اليوم (افتراضي true).
 */
function dDate($date, $withTime = false, $withDay = true) {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') return '—';
    $ts = is_numeric($date) ? (int)$date : strtotime((string)$date);
    if (!$ts) return e((string)$date);

    $pref  = calPref();
    $time  = $withTime ? ' — ' . date('H:i', $ts) : '';
    $wd    = $withDay ? dayName($ts) . ' ' : '';
    $g     = $wd . date('d/m/Y', $ts) . $time;
    $h     = hijriDate($ts, true, $withDay) . $time;

    if ($pref === 'gregorian') return $g;
    if ($pref === 'hijri')     return $h;
    // both — الهجري أولاً، الميلادي مختصراً بين قوسين
    return $h . ' <span class="text-muted" style="font-size:.85em">(' . date('d/m/Y', $ts) . ')</span>';
}

/**
 * العنوان الوطني السعودي — يبني سطر العنوان من أعمدة na_* (في جدول clients أو office_settings).
 * $r: صف قاعدة البيانات.  يُعيد [] إن لم تتوفّر بيانات كافية، وإلا مصفوفة أسطر جاهزة للعرض.
 */
function na_lines(array $r): array {
    $g = fn($k) => trim((string)($r[$k] ?? ''));
    $bld = $g('na_building'); $str = $g('na_street'); $dst = $g('na_district');
    $cty = $g('na_city'); $pst = $g('na_postal'); $add = $g('na_additional'); $shr = $g('na_short');
    if (!$bld && !$str && !$dst && !$cty && !$shr) return [];
    $lines = [];
    $l1 = trim(($bld ? $bld . ' ' : '') . $str);
    if ($l1) $lines[] = $l1;
    $l2 = implode('، ', array_filter([$dst, $cty]));
    if ($pst) $l2 = trim($l2 . ' ' . $pst);
    if ($l2) $lines[] = $l2;
    $extra = array_filter([$add ? 'رقم إضافي: ' . $add : '', $shr ? 'العنوان المختصر: ' . $shr : '']);
    if ($extra) $lines[] = implode(' — ', $extra);
    return $lines;
}

/** نفس الشيء كسطر واحد مفصول بفواصل. */
function na_inline(array $r): string { return implode('، ', na_lines($r)); }

/**
 * شعار المنصّة الذي يرفعه الأدمن من «إعدادات المنصة».
 * يُعاد كمسار مطلق من جذر الموقع (مثل /assets/img/site-logo-123.png) أو '' إن لم يُرفع.
 */
if (!function_exists('site_logo')) {
    function site_logo($conn): string {
        static $v = null;
        if ($v === null) {
            $v = '';
            try {
                $r = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='site_logo' LIMIT 1");
                if ($r && ($row = $r->fetch_assoc())) $v = trim((string)($row['setting_value'] ?? ''));
            } catch (\Throwable $e) { $v = ''; }
        }
        return $v;
    }
}
