<?php
/**
 * includes/crypto_helper.php
 * ════════════════════════════════════════════════════════════
 *  تشفير ملفات المكاتب — AES-256-GCM
 *
 *  - كل مكتب له مفتاح مشتق خاص (لا يمكن لمكتب فك تشفير ملفات مكتب آخر)
 *  - المفتاح الرئيسي يُحفظ خارج قاعدة البيانات في config/storage_key.php
 *  - الملف المشفَّر يبدأ بالبصمة "MEHKAMe1" ثم IV(12) + TAG(16) + النص المشفَّر
 *  - fallback: إن تعذّرت الكتابة في config/ يُحفظ المفتاح في site_content
 *
 *  ⚠️ فقدان المفتاح الرئيسي = فقدان القدرة على فتح كل الملفات المشفَّرة.
 *     خذ نسخة احتياطية من config/storage_key.php فور إنشائه.
 * ════════════════════════════════════════════════════════════
 */

if (!defined('MEHKAM_ENC_MAGIC')) define('MEHKAM_ENC_MAGIC', 'MEHKAMe1');

/**
 * إرجاع المفتاح الرئيسي (64 حرف hex = 32 بايت). يُنشئه إن لم يوجد.
 */
if (!function_exists('mehkam_master_key_hex')) {
    function mehkam_master_key_hex(): string {
        static $cached = null;
        if ($cached !== null) return $cached;

        $key_file = dirname(__DIR__) . '/config/storage_key.php';

        // 1) موجود كملف
        if (is_file($key_file)) {
            require_once $key_file;
            if (defined('MEHKAM_MASTER_KEY') && preg_match('/^[0-9a-f]{64}$/i', MEHKAM_MASTER_KEY)) {
                $GLOBALS['_mehkam_key_persistent'] = true;
                return $cached = strtolower(MEHKAM_MASTER_KEY);
            }
        }

        // 2) توليد مفتاح جديد
        try {
            $new = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            $new = hash('sha256', uniqid('', true) . mt_rand());
        }

        // 3) محاولة الكتابة في config/storage_key.php
        $php = "<?php\n"
             . "// مفتاح تشفير ملفات المكاتب — لا تشاركه ولا ترفعه على أي مستودع.\n"
             . "// فقدانه = فقدان القدرة على فتح كل الملفات المشفَّرة. خذ نسخة احتياطية الآن.\n"
             . "define('MEHKAM_MASTER_KEY', '{$new}');\n";
        if (@file_put_contents($key_file, $php, LOCK_EX) !== false) {
            @chmod($key_file, 0600);
            $GLOBALS['_mehkam_key_persistent'] = true;
            return $cached = $new;
        }

        // 4) fallback — الحفظ في قاعدة البيانات (وضع أقل أماناً لكنه ثابت)
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $c = $GLOBALS['conn'];
            try {
                $c->query("CREATE TABLE IF NOT EXISTS site_content (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(100) NOT NULL UNIQUE,
                    setting_value LONGTEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                $r = $c->query("SELECT setting_value FROM site_content WHERE setting_key='_storage_master_key' LIMIT 1");
                if ($r && ($row = $r->fetch_assoc()) && preg_match('/^[0-9a-f]{64}$/i', $row['setting_value'])) {
                    $GLOBALS['_mehkam_key_persistent'] = true;
                    return $cached = strtolower($row['setting_value']);
                }
                $ne = $c->real_escape_string($new);
                $c->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('_storage_master_key','$ne')
                           ON DUPLICATE KEY UPDATE setting_value='$ne'");
                $GLOBALS['_mehkam_key_persistent'] = true;
                return $cached = $new;
            } catch (\Throwable $e) {}
        }

        // آخر حل — مفتاح غير ثابت (سيُرفض التشفير حينها لتجنّب فقدان الملفات)
        $GLOBALS['_mehkam_key_persistent'] = false;
        return $cached = $new;
    }
}

/** هل المفتاح الرئيسي محفوظ بشكل ثابت؟ (شرط لتفعيل التشفير) */
if (!function_exists('mehkam_key_is_persistent')) {
    function mehkam_key_is_persistent(): bool {
        mehkam_master_key_hex(); // يضبط العلم
        return !empty($GLOBALS['_mehkam_key_persistent']);
    }
}

/**
 * مفتاح المكتب المشتق (خام، 32 بايت).
 * @param string $salt ملح المكتب (hex) — من office_settings.storage_salt
 */
if (!function_exists('mehkam_office_key')) {
    function mehkam_office_key(int $office_id, string $salt = ''): string {
        $master = hex2bin(mehkam_master_key_hex());
        $salt   = preg_match('/^[0-9a-f]{2,64}$/i', $salt) ? hex2bin($salt) : hash('sha256', "office$office_id", true);
        return hash_hkdf('sha256', $master, 32, "mehkam-office-$office_id", $salt);
    }
}

/** توليد ملح جديد للمكتب (hex, 32 حرف) */
if (!function_exists('mehkam_new_salt')) {
    function mehkam_new_salt(): string {
        try { return bin2hex(random_bytes(16)); }
        catch (\Throwable $e) { return substr(hash('sha256', uniqid('', true)), 0, 32); }
    }
}

/** تشفير سلسلة بايتات */
if (!function_exists('mehkam_encrypt_bytes')) {
    function mehkam_encrypt_bytes(string $plain, string $key): string {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) throw new RuntimeException('فشل التشفير');
        return MEHKAM_ENC_MAGIC . $iv . $tag . $ct;
    }
}

/** فك تشفير — يُعيد النص الأصلي، أو null عند الفشل. غير المشفَّر يُعاد كما هو. */
if (!function_exists('mehkam_decrypt_bytes')) {
    function mehkam_decrypt_bytes(string $blob, string $key): ?string {
        if (!mehkam_is_encrypted($blob)) return $blob;
        $iv  = substr($blob, 8, 12);
        $tag = substr($blob, 20, 16);
        $ct  = substr($blob, 36);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? null : $pt;
    }
}

/** هل هذا المحتوى مشفَّراً بواسطتنا؟ */
if (!function_exists('mehkam_is_encrypted')) {
    function mehkam_is_encrypted(string $blob): bool {
        return strncmp($blob, MEHKAM_ENC_MAGIC, 8) === 0 && strlen($blob) > 36;
    }
}

/** تشفير ملف على القرص في مكانه (يُرجع true عند النجاح) */
if (!function_exists('mehkam_encrypt_file_inplace')) {
    function mehkam_encrypt_file_inplace(string $path, string $key): bool {
        $data = @file_get_contents($path);
        if ($data === false) return false;
        if (mehkam_is_encrypted($data)) return true; // مشفَّر مسبقاً
        try {
            $enc = mehkam_encrypt_bytes($data, $key);
        } catch (\Throwable $e) {
            return false;
        }
        return @file_put_contents($path, $enc, LOCK_EX) !== false;
    }
}
