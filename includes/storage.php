<?php
/**
 * includes/storage.php
 * ══════════════════════════════════════════════════
 *  واجهة تخزين موحدة — server (محلي) و gdrive (لكل مكتب) + تشفير اختياري
 *
 *  الإعداد لكل مكتب في: office_settings
 *    storage_driver   = 'server' | 'gdrive'
 *    gdrive_folder_id  = مجلد Drive الخاص بالمكتب (مشارَك مع Service Account)
 *    encrypt_files     = 0 | 1
 *    storage_salt      = ملح اشتقاق المفتاح (يُولَّد تلقائياً)
 *
 *  الاستخدام (لم يتغيّر):
 *    require_once '../includes/storage.php';
 *    $r = storage_upload($_FILES['file'], 'cases', $oid, $office_name);
 *    if ($r['success']) { $r['path']; $r['name']; $r['size']; $r['driver']; }
 *
 *  للعرض الآمن (يمرّ عبر office/file.php مع تحقق الملكية وفك التشفير):
 *    echo storage_file_link($conn, 'contract', $row, 'view');   // أو 'download'
 * ══════════════════════════════════════════════════
 */

require_once dirname(__DIR__) . '/config/storage.php';
require_once __DIR__ . '/crypto_helper.php';

// ════════════════════════════════════════════
//  إعدادات التخزين الفعلية للمكتب
// ════════════════════════════════════════════
function storage_office_ctx(int $office_id): array {
    static $cache = [];
    if (isset($cache[$office_id])) return $cache[$office_id];

    $ctx = [
        'driver'         => 'server',
        'encrypt'        => false,
        'salt'           => '',
        'gdrive_connected' => false,
    ];

    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli && $office_id > 0) {
        try {
            $r = $conn->query("SELECT storage_driver, encrypt_files, storage_salt, gdrive_refresh_token
                               FROM office_settings WHERE office_id=" . (int)$office_id . " LIMIT 1");
            if ($r && ($row = $r->fetch_assoc())) {
                if (in_array($row['storage_driver'] ?? '', ['server', 'gdrive'], true)) {
                    $ctx['driver'] = $row['storage_driver'];
                }
                $ctx['encrypt']          = !empty($row['encrypt_files']);
                $ctx['salt']             = trim($row['storage_salt'] ?? '');
                $ctx['gdrive_connected'] = !empty($row['gdrive_refresh_token']);
            }
        } catch (\Throwable $e) {
            // الأعمدة غير موجودة بعد — نكمل بالإعداد الافتراضي (server، بدون تشفير)
        }
    }

    // اختار المكتب gdrive لكنه غير مربوط بحساب → استخدم السيرفر حتى لا تفشل الرفوعات
    if ($ctx['driver'] === 'gdrive' && !$ctx['gdrive_connected']) {
        $ctx['driver'] = 'server';
    }

    return $cache[$office_id] = $ctx;
}

/** مفتاح تشفير المكتب (خام) أو '' إن كان التشفير معطّلاً أو المفتاح الرئيسي غير ثابت */
function storage_office_key(int $office_id): string {
    $ctx = storage_office_ctx($office_id);
    if (!$ctx['encrypt']) return '';
    if (function_exists('mehkam_key_is_persistent') && !mehkam_key_is_persistent()) {
        error_log('mehkam: encryption requested but master key is not persistent — storing file unencrypted');
        return '';
    }
    return mehkam_office_key($office_id, $ctx['salt']);
}

// ════════════════════════════════════════════
//  رفع ملف
// ════════════════════════════════════════════
function storage_upload(
    array  $file,
    string $section,
    int    $office_id,
    string $office_name = ''
): array {
    try {
        return _storage_upload_inner($file, $section, $office_id, $office_name);
    } catch (\Throwable $e) {
        error_log('mehkam storage_upload fatal (office ' . $office_id . ', section ' . $section . '): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return [
            'success' => false, 'path' => '', 'name' => '', 'size' => '',
            'url' => '', 'driver' => 'local',
            'error' => 'تعذّر رفع الملف بسبب خطأ داخلي. تحقق من إعدادات الاستضافة أو راسل الدعم الفني (تفاصيل الخطأ في error_log).',
        ];
    }
}

function _storage_upload_inner(
    array  $file,
    string $section,
    int    $office_id,
    string $office_name = ''
): array {
    $ctx = storage_office_ctx($office_id);

    $empty = [
        'success' => false, 'path' => '', 'name' => '', 'size' => '',
        'url' => '', 'driver' => $ctx['driver'] === 'gdrive' ? 'gdrive' : 'local', 'error' => '',
    ];

    if (empty($file['name']) || ($file['error'] ?? 4) === UPLOAD_ERR_NO_FILE) {
        return $empty;
    }

    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => 'الملف أكبر من الحد المسموح به في السيرفر',
            UPLOAD_ERR_FORM_SIZE  => 'الملف أكبر من الحد المسموح به في النموذج',
            UPLOAD_ERR_PARTIAL    => 'اكتمل الرفع جزئياً فقط، حاول مجدداً',
            UPLOAD_ERR_NO_TMP_DIR => 'مجلد tmp غير موجود على السيرفر',
            UPLOAD_ERR_CANT_WRITE => 'تعذرت الكتابة على القرص',
        ];
        return array_merge($empty, ['error' => $msgs[$file['error']] ?? 'خطأ في الرفع رقم ' . $file['error']]);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, STORAGE_ALLOWED_EXT, true)) {
        return array_merge($empty, ['error' => 'نوع الملف غير مسموح به. الأنواع المقبولة: ' . implode('، ', STORAGE_ALLOWED_EXT)]);
    }

    $enc_key = storage_office_key($office_id);

    if ($ctx['driver'] === 'gdrive') {
        $g = _storage_gdrive($file, $section, $office_id, $office_name, $ext, $enc_key);
        // فشل Drive → لا نضيّع الملف، نحفظه على السيرفر ونسجّل الخطأ
        if (!$g['success']) {
            error_log('mehkam gdrive upload failed (office ' . $office_id . '): ' . $g['error'] . ' — falling back to server');
            return _storage_local($file, $section, $office_id, $ext, $enc_key);
        }
        return $g;
    }

    return _storage_local($file, $section, $office_id, $ext, $enc_key);
}

// ════════════════════════════════════════════
//  حذف ملف
// ════════════════════════════════════════════
function storage_delete(string $path_or_id, string $driver = '', int $office_id = 0): void {
    if (empty($path_or_id)) return;
    $driver = $driver ?: 'local';

    if ($driver === 'gdrive') {
        if (!function_exists('gd_office_token')) {
            @require_once dirname(__DIR__) . '/includes/gdrive_helper.php';
        }
        $conn = $GLOBALS['conn'] ?? null;
        if ($office_id <= 0) $office_id = (int)($_SESSION['office_id'] ?? 0);
        if (function_exists('gd_office_token') && $conn instanceof mysqli && $office_id > 0) {
            $token = gd_office_token($conn, $office_id);
            if ($token) gd_delete_file($token, $path_or_id);
        }
        return;
    }

    $rel = ltrim($path_or_id, '/');
    if (strncmp($rel, 'uploads/', 8) === 0) $rel = substr($rel, 8);
    $base = realpath(STORAGE_LOCAL_BASE);
    $full = realpath(STORAGE_LOCAL_BASE . $rel);
    if ($full && $base && str_starts_with($full, $base) && is_file($full)) {
        @unlink($full);
    }
}

// ════════════════════════════════════════════
//  رابط التحميل المباشر (للملفات المحلية غير المشفَّرة فقط)
//  الملفات المشفَّرة و Google Drive تُخدَم عبر office/file.php
// ════════════════════════════════════════════
function storage_url(string $path_or_id, string $driver = ''): string {
    if (empty($path_or_id)) return '#';
    $driver = $driver ?: (STORAGE_DRIVER === 'gdrive' ? 'gdrive' : 'local');

    if ($driver === 'gdrive') {
        // لم يعد رابطاً عاماً — يجب المرور عبر file.php
        return '#';
    }

    // ملف محلي مشفَّر (البادئة enc_) — لا يُخدَم مباشرة
    if (strpos(basename($path_or_id), 'enc_') === 0) return '#';

    return '../uploads/' . ltrim($path_or_id, '/');
}

function storage_view_url(string $path_or_id, string $driver = ''): string {
    return storage_url($path_or_id, $driver);
}

/**
 * رابط تحميل آمن مبني على صف قاعدة بيانات — الطريقة المعتمدة.
 * يتحقق office/file.php من ملكية المكتب ويفكّ التشفير.
 * $type ∈ case | contract | poa | corr | archive | library | att
 * $row  = صف الجدول (يحتاج id، ويُفضَّل file_name/original_name)
 */
function storage_file_link($conn, string $type, array $row, string $mode = 'view'): string {
    $id   = (int)($row['id'] ?? 0);
    $name = $row['file_name'] ?? $row['original_name'] ?? 'ملف مرفق';
    $has  = !empty($row['file_path']) || !empty($row['stored_name']) || !empty($row['file_id']);
    if (!$id || !$has) return '<span class="text-muted">—</span>';
    $icon  = storage_icon($name);
    $label = htmlspecialchars(mb_substr($name, 0, 40));
    $url   = 'file.php?t=' . rawurlencode($type) . '&id=' . $id . ($mode === 'download' ? '&dl=1' : '');
    return sprintf(
        '<a href="%s" target="_blank" class="text-primary text-decoration-none d-inline-flex align-items-center gap-1" title="%s">'
            . '<i class="fas %s"></i> %s</a>',
        htmlspecialchars($url), htmlspecialchars($name), $icon, $label
    );
}

/**
 * رابط HTML قديم (متوافق مع الكود السابق).
 * للملفات المحلية غير المشفَّرة فقط — يُفضَّل استخدام storage_file_link().
 */
function storage_link(
    string $path_or_id,
    string $file_name,
    string $driver   = '',
    string $type     = 'download'
): string {
    if (empty($path_or_id)) return '<span class="text-muted">—</span>';
    $icon  = storage_icon($file_name ?: $path_or_id);
    $label = htmlspecialchars(mb_substr($file_name ?: basename($path_or_id), 0, 40));
    $url   = htmlspecialchars(storage_url($path_or_id, $driver));
    return sprintf(
        '<a href="%s" target="_blank" class="text-primary text-decoration-none d-inline-flex align-items-center gap-1" title="%s">'
            . '<i class="fas %s"></i> %s</a>',
        $url, htmlspecialchars($file_name ?: ''), $icon, $label
    );
}

// ════════════════════════════════════════════
//  أيقونة الملف
// ════════════════════════════════════════════
function storage_icon(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $map = [
        'pdf'=>'fa-file-pdf text-danger','doc'=>'fa-file-word text-primary','docx'=>'fa-file-word text-primary',
        'xls'=>'fa-file-excel text-success','xlsx'=>'fa-file-excel text-success',
        'odt'=>'fa-file-alt text-info','ods'=>'fa-file-alt text-info',
        'jpg'=>'fa-file-image text-warning','jpeg'=>'fa-file-image text-warning','png'=>'fa-file-image text-warning',
        'gif'=>'fa-file-image text-warning','webp'=>'fa-file-image text-warning',
        'zip'=>'fa-file-archive text-secondary','rar'=>'fa-file-archive text-secondary','txt'=>'fa-file-alt text-muted',
    ];
    return 'fas ' . ($map[$ext] ?? 'fa-file text-secondary');
}

// ════════════════════════════════════════════
//  معلومات مزود التخزين للمكتب (للعرض في الإعدادات)
// ════════════════════════════════════════════
function storage_driver_info(int $office_id = 0): array {
    $ctx = storage_office_ctx($office_id);
    $gd  = $ctx['driver'] === 'gdrive';
    return [
        'driver'  => $ctx['driver'],
        'encrypt' => $ctx['encrypt'],
        'label'   => $gd ? 'Google Drive الخاص بالمكتب' : 'خادم المنصة',
        'icon'    => $gd ? 'fab fa-google-drive text-success' : 'fas fa-server text-primary',
    ];
}

// ════════════════════════════════════════════
//  جلب ملف مفكوك التشفير — يستخدمه office/file.php
//  يُعيد ['ok'=>bool,'data'=>bytes,'name'=>string,'error'=>string]
// ════════════════════════════════════════════
function storage_fetch_bytes(string $ref, string $driver, int $office_id): array {
    $out = ['ok' => false, 'data' => '', 'name' => '', 'error' => ''];
    $key = storage_office_key($office_id);

    if ($driver === 'gdrive') {
        if (!function_exists('gd_office_token')) {
            @require_once dirname(__DIR__) . '/includes/gdrive_helper.php';
        }
        $conn = $GLOBALS['conn'] ?? null;
        if (!function_exists('gd_office_token') || !($conn instanceof mysqli)) {
            return array_merge($out, ['error' => 'وحدة Google Drive غير متوفرة']);
        }
        $token = gd_office_token($conn, $office_id);
        if (!$token) return array_merge($out, ['error' => 'انتهت صلاحية ربط Google Drive — أعِد الربط من إعدادات المكتب']);
        $bytes = gd_download_bytes($token, $ref);
        if ($bytes === null) return array_merge($out, ['error' => 'تعذّر جلب الملف من Google Drive']);
    } else {
        // ملف محلي — امنع الخروج من مجلد uploads/{office}
        $rel = ltrim($ref, '/');
        if (strncmp($rel, 'uploads/', 8) === 0) $rel = substr($rel, 8); // توافق مع المسارات القديمة
        if (strpos($rel, (string)$office_id . '/') !== 0) {
            return array_merge($out, ['error' => 'غير مصرّح بالوصول لهذا الملف']);
        }
        $base = realpath(STORAGE_LOCAL_BASE);
        $full = realpath(STORAGE_LOCAL_BASE . $rel);
        if (!$full || !$base || !str_starts_with($full, $base) || !is_file($full)) {
            return array_merge($out, ['error' => 'الملف غير موجود']);
        }
        $bytes = file_get_contents($full);
        if ($bytes === false) return array_merge($out, ['error' => 'تعذّرت قراءة الملف']);
    }

    // فك التشفير إن لزم
    if (mehkam_is_encrypted($bytes)) {
        if ($key === '') $key = mehkam_office_key($office_id, storage_office_ctx($office_id)['salt']);
        $plain = mehkam_decrypt_bytes($bytes, $key);
        if ($plain === null) return array_merge($out, ['error' => 'فشل فك تشفير الملف — قد يكون المفتاح الرئيسي تغيّر']);
        $bytes = $plain;
    }

    return ['ok' => true, 'data' => $bytes, 'name' => basename($ref), 'error' => ''];
}

// ════════════════════════════════════════════
//  دوال داخلية
// ════════════════════════════════════════════

/** رفع محلي (+ تشفير اختياري) */
function _storage_local(array $file, string $section, int $office_id, string $ext, string $enc_key = ''): array {
    $base  = rtrim(STORAGE_LOCAL_BASE, '/') . '/';
    $empty = ['success'=>false,'path'=>'','name'=>'','size'=>'','url'=>'','driver'=>'local','error'=>''];

    if ($file['size'] > STORAGE_MAX_MB * 1024 * 1024) {
        return array_merge($empty, ['error' => 'حجم الملف يتجاوز ' . STORAGE_MAX_MB . ' MB']);
    }

    $allowed_sections = ['cases','contracts','poa','correspondence','archive','library','attachments'];
    $section = preg_replace('/[^a-z_]/', '', strtolower($section));
    if (!in_array($section, $allowed_sections, true)) {
        return array_merge($empty, ['error' => 'قسم غير مسموح به: ' . $section]);
    }

    $dir = $base . $office_id . '/' . $section . '/';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return array_merge($empty, ['error' => 'تعذر إنشاء مجلد التخزين. تحقق من صلاحيات uploads/']);
        }
        @file_put_contents($dir . '.htaccess', "Options -Indexes\n");
        @file_put_contents($dir . 'index.php', '<?php header("Location: ../../../"); exit;');
    }

    $office_dir = $base . $office_id . '/';
    if (!is_file($office_dir . '.htaccess')) {
        @file_put_contents($office_dir . '.htaccess', "Options -Indexes\n");
        @file_put_contents($office_dir . 'index.php', '<?php header("Location: ../../"); exit;');
    }

    $safe     = preg_replace('/[^a-zA-Z0-9\-_.]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $safe     = substr($safe, 0, 60);
    $prefix   = $enc_key !== '' ? 'enc_' : '';
    $filename = $prefix . date('Ymd_His') . '_' . $safe . '.' . $ext;
    $fullpath = $dir . $filename;

    if (file_exists($fullpath)) {
        $filename = $prefix . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
        $fullpath = $dir . $filename;
    }

    if (!move_uploaded_file($file['tmp_name'], $fullpath)) {
        return array_merge($empty, ['error' => 'فشل حفظ الملف. تحقق من صلاحيات مجلد uploads/']);
    }

    // تشفير في المكان
    if ($enc_key !== '') {
        if (!mehkam_encrypt_file_inplace($fullpath, $enc_key)) {
            @unlink($fullpath);
            return array_merge($empty, ['error' => 'فشل تشفير الملف — لم يُحفَظ']);
        }
    }

    $sz  = $file['size'];
    $str = $sz >= 1048576 ? round($sz / 1048576, 1) . ' MB' : round($sz / 1024) . ' KB';
    $rel = $office_id . '/' . $section . '/' . $filename;

    return [
        'success' => true,
        'path'    => $rel,
        'name'    => $file['name'],
        'size'    => $str,
        'url'     => $enc_key !== '' ? '#' : ('../uploads/' . $rel),
        'driver'  => 'local',
        'error'   => '',
    ];
}

/** رفع إلى Google Drive الخاص بالمكتب عبر OAuth (+ تشفير اختياري) */
function _storage_gdrive(array $file, string $section, int $office_id, string $office_name, string $ext, string $enc_key = ''): array {
    $empty = ['success'=>false,'path'=>'','name'=>'','size'=>'','url'=>'','driver'=>'gdrive','error'=>''];

    if (!function_exists('gd_office_token')) {
        $gd = dirname(__DIR__) . '/includes/gdrive_helper.php';
        if (!is_file($gd)) return array_merge($empty, ['error' => 'ملف gdrive_helper.php غير موجود']);
        require_once $gd;
    }
    if (!function_exists('gd_office_token')) {
        return array_merge($empty, ['error' => 'وحدة Google Drive قديمة — حدّث gdrive_helper.php']);
    }

    if ($file['size'] > STORAGE_GD_MAX_MB * 1024 * 1024) {
        return array_merge($empty, ['error' => 'حجم الملف يتجاوز ' . STORAGE_GD_MAX_MB . ' MB']);
    }

    $conn = $GLOBALS['conn'] ?? null;
    if (!($conn instanceof mysqli)) return array_merge($empty, ['error' => 'لا اتصال بقاعدة البيانات']);

    $token = gd_office_token($conn, $office_id);
    if (!$token) return array_merge($empty, ['error' => 'ربط Google Drive غير صالح — أعِد الربط']);

    $root = gd_office_root($conn, $office_id, $token, $office_name);
    if ($root === '') return array_merge($empty, ['error' => 'تعذّر إنشاء مجلد مِحكام في درايف المكتب']);

    $section_labels = [
        'archive'=>'الأرشيف','contracts'=>'العقود والوكالات','poa'=>'الوكالات',
        'correspondence'=>'الصادر والوارد','cases'=>'القضايا','library'=>'المكتبة','attachments'=>'مرفقات',
    ];
    $folder = gd_find_or_create_folder($token, $section_labels[$section] ?? $section, $root);
    if ($folder === '') $folder = $root;

    $bytes = file_get_contents($file['tmp_name']);
    if ($bytes === false) return array_merge($empty, ['error' => 'تعذّرت قراءة الملف المؤقت']);
    $store_ext = $ext;
    if ($enc_key !== '') {
        if (!function_exists('mehkam_encrypt_bytes')) require_once __DIR__ . '/crypto_helper.php';
        try { $bytes = mehkam_encrypt_bytes($bytes, $enc_key); }
        catch (\Throwable $e) { return array_merge($empty, ['error' => 'فشل تشفير الملف']); }
        $store_ext = $ext . '.enc';
    }

    $safe = preg_replace('/[^\w.-]/u', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $fname = date('Ymd_His') . '_' . mb_substr($safe, 0, 60) . '.' . $store_ext;

    $up = gd_upload_bytes($token, $folder, $fname, $bytes);
    if (!$up['ok']) return array_merge($empty, ['error' => $up['error']]);

    $sz  = (int)($file['size'] ?? 0);
    $str = $sz >= 1048576 ? round($sz/1048576, 1) . ' MB' : round($sz/1024) . ' KB';

    return [
        'success' => true,
        'path'    => $up['id'],     // Google Drive file id
        'name'    => $file['name'],
        'size'    => $str,
        'url'     => '#',
        'driver'  => 'gdrive',
        'error'   => '',
    ];
}
