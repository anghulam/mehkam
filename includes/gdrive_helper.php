<?php
/**
 * includes/gdrive_helper.php
 * ════════════════════════════════════════════════════════════
 *  ربط Google Drive لكل مكتب على حِدة (OAuth 2.0).
 *
 *  - المنصّة تسجّل OAuth Client واحداً في Google Cloud
 *    (Client ID + Secret تُحفظ في site_content من لوحة الإدارة).
 *  - كل مكتب يضغط "ربط Google Drive" → يوافق بحسابه → نحفظ له
 *    refresh_token في office_settings ونرفع ملفاته إلى درايفه هو.
 *  - نطاق الصلاحية: drive.file فقط — التطبيق يرى/يدير الملفات التي
 *    ينشئها هو داخل مجلد "مِحكام" في درايف المكتب، لا شيء غيرها.
 * ════════════════════════════════════════════════════════════
 */

if (!defined('GD_MAX_MB'))       define('GD_MAX_MB', 50);
if (!defined('GD_ALLOWED_EXT'))  define('GD_ALLOWED_EXT', ['pdf','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','gif','webp','zip','rar','txt','mp4']);
if (!defined('GD_SCOPE'))        define('GD_SCOPE', 'https://www.googleapis.com/auth/drive.file openid email');

// ════════════════════════════════════════════════
//  HTTP مساعد
// ════════════════════════════════════════════════
function _gd_post(string $url, $body, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $res, 'err' => $err, 'json' => json_decode((string)$res, true) ?: []];
}

function _gd_get(string $url, string $token, bool $raw = false): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
        CURLOPT_TIMEOUT        => 180,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return $raw
        ? ['code' => $code, 'body' => $res, 'err' => $err]
        : ['code' => $code, 'body' => $res, 'err' => $err, 'json' => json_decode((string)$res, true) ?: []];
}

// ════════════════════════════════════════════════
//  إعداد OAuth للمنصة
// ════════════════════════════════════════════════
function gd_oauth_config(): array {
    static $c = null;
    if ($c !== null) return $c;
    $id = $sec = '';
    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        try {
            $r = $conn->query("SELECT setting_key, setting_value FROM site_content
                               WHERE setting_key IN ('gdrive_oauth_client_id','gdrive_oauth_client_secret')");
            if ($r) while ($row = $r->fetch_assoc()) {
                if ($row['setting_key'] === 'gdrive_oauth_client_id')     $id  = trim($row['setting_value']);
                if ($row['setting_key'] === 'gdrive_oauth_client_secret') $sec = trim($row['setting_value']);
            }
        } catch (\Throwable $e) {}
    }
    return $c = ['client_id' => $id, 'client_secret' => $sec];
}

function gd_oauth_ready(): bool {
    $c = gd_oauth_config();
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

function gd_oauth_redirect_uri(): string {
    // override يدوي من الإعدادات (الأضمن)
    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        try {
            $r = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='gdrive_redirect_uri' LIMIT 1");
            if ($r && ($row = $r->fetch_assoc()) && trim($row['setting_value']) !== '') {
                return trim($row['setting_value']);
            }
        } catch (\Throwable $e) {}
    }

    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443)
          || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (strtolower($_SERVER['REQUEST_SCHEME'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . $host . '/office/gdrive_callback.php';
}

function gd_oauth_auth_url(string $state): string {
    $c = gd_oauth_config();
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'              => $c['client_id'],
        'redirect_uri'           => gd_oauth_redirect_uri(),
        'response_type'          => 'code',
        'scope'                  => GD_SCOPE,
        'access_type'            => 'offline',
        'prompt'                 => 'consent',
        'include_granted_scopes' => 'true',
        'state'                  => $state,
    ]);
}

function gd_oauth_exchange_code(string $code): array {
    $c = gd_oauth_config();
    $r = _gd_post('https://oauth2.googleapis.com/token', http_build_query([
        'code'          => $code,
        'client_id'     => $c['client_id'],
        'client_secret' => $c['client_secret'],
        'redirect_uri'  => gd_oauth_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]));
    return $r['json'];
}

function gd_oauth_refresh(string $refresh_token): array {
    $c = gd_oauth_config();
    $r = _gd_post('https://oauth2.googleapis.com/token', http_build_query([
        'client_id'     => $c['client_id'],
        'client_secret' => $c['client_secret'],
        'refresh_token' => $refresh_token,
        'grant_type'    => 'refresh_token',
    ]));
    return $r['json'];
}

function gd_oauth_revoke(string $token): void {
    if ($token === '') return;
    _gd_post('https://oauth2.googleapis.com/revoke', http_build_query(['token' => $token]));
}

/**
 * فحص صحة بيانات OAuth Client المُدخلة (بدون الحاجة لمستخدم).
 * نرسل refresh_token وهمياً: إن كان الرد invalid_grant فالـ Client صحيح،
 * وإن كان invalid_client فالمعرّف/السر غلط.
 */
function gd_oauth_verify_credentials(): array {
    $c = gd_oauth_config();
    if ($c['client_id'] === '' || $c['client_secret'] === '') {
        return ['ok' => false, 'msg' => 'أدخل Client ID و Client Secret واحفظ أولاً'];
    }
    if (strpos($c['client_id'], '.apps.googleusercontent.com') === false) {
        return ['ok' => false, 'msg' => 'صيغة Client ID غير صحيحة — يجب أن ينتهي بـ .apps.googleusercontent.com'];
    }
    $r = _gd_post('https://oauth2.googleapis.com/token', http_build_query([
        'client_id'     => $c['client_id'],
        'client_secret' => $c['client_secret'],
        'refresh_token' => 'mehkam-connectivity-probe',
        'grant_type'    => 'refresh_token',
    ]));
    $err = $r['json']['error'] ?? '';
    if ($err === 'invalid_grant') {
        return ['ok' => true, 'msg' => 'بيانات OAuth Client صحيحة ✔ — الآن يقدر كل مكتب يربط حسابه'];
    }
    if ($err === 'invalid_client') {
        return ['ok' => false, 'msg' => 'Client ID أو Client Secret غير صحيح — تحقق من النسخ من Google Cloud'];
    }
    if ($r['err']) {
        return ['ok' => false, 'msg' => 'تعذّر الاتصال بـ Google: ' . $r['err']];
    }
    return ['ok' => false, 'msg' => 'رد غير متوقع من Google: ' . ($err ?: ('HTTP ' . $r['code']))];
}

/**
 * اختبار ربط مكتب فعلي — يستدعي Drive API بتوكن المكتب.
 */
function gd_office_test($conn, int $office_id): array {
    if (!function_exists('gd_office_token')) return ['ok' => false, 'msg' => 'الوحدة غير محمّلة'];
    if (!gd_office_connected($conn, $office_id)) {
        return ['ok' => false, 'msg' => 'المكتب غير مربوط بحساب Google. اضغط «ربط حساب Google Drive» أولاً.'];
    }
    $token = gd_office_token($conn, $office_id);
    if (!$token) {
        return ['ok' => false, 'msg' => 'انتهت صلاحية الربط أو أُلغيت من حساب Google — أعِد الربط'];
    }
    $r = _gd_get('https://www.googleapis.com/drive/v3/about?fields=user(emailAddress),storageQuota(limit,usage)', $token);
    if ($r['code'] !== 200) {
        return ['ok' => false, 'msg' => 'فشل: ' . ($r['json']['error']['message'] ?? ('HTTP ' . $r['code']))];
    }
    $email = $r['json']['user']['emailAddress'] ?? '';
    // تأكد من إمكانية إنشاء/إيجاد مجلد مِحكام
    $root = gd_office_root($conn, $office_id, $token);
    $folder_ok = $root !== '';
    $msg = 'الاتصال يعمل ✔' . ($email ? ' — الحساب: ' . $email : '')
         . ($folder_ok ? ' — مجلد «مِحكام» جاهز' : ' — لكن تعذّر إنشاء مجلد مِحكام');
    return ['ok' => $folder_ok, 'msg' => $msg];
}

function gd_oauth_userinfo(string $access_token): array {
    $r = _gd_get('https://openidconnect.googleapis.com/v1/userinfo', $access_token);
    return $r['code'] === 200 ? $r['json'] : [];
}

// ════════════════════════════════════════════════
//  توكن المكتب (يُجدَّد تلقائياً)
// ════════════════════════════════════════════════
function gd_office_token($conn, int $office_id): ?string {
    if (!($conn instanceof mysqli) || $office_id <= 0) return null;
    try {
        $r = $conn->query("SELECT gdrive_refresh_token, gdrive_access_token, gdrive_token_expiry
                           FROM office_settings WHERE office_id=" . (int)$office_id . " LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
    } catch (\Throwable $e) { return null; }
    if (!$row || empty($row['gdrive_refresh_token'])) return null;

    if (!empty($row['gdrive_access_token']) && (int)$row['gdrive_token_expiry'] > time() + 90) {
        return $row['gdrive_access_token'];
    }

    $t = gd_oauth_refresh($row['gdrive_refresh_token']);
    if (empty($t['access_token'])) {
        error_log('gdrive: refresh failed for office ' . $office_id . ' — ' . json_encode($t));
        return null;
    }
    $at  = $conn->real_escape_string($t['access_token']);
    $exp = time() + (int)($t['expires_in'] ?? 3600);
    // قد ترجع Google refresh_token جديداً أحياناً
    $rt_sql = '';
    if (!empty($t['refresh_token'])) {
        $rt_sql = ", gdrive_refresh_token='" . $conn->real_escape_string($t['refresh_token']) . "'";
    }
    $conn->query("UPDATE office_settings SET gdrive_access_token='$at', gdrive_token_expiry=$exp$rt_sql
                  WHERE office_id=" . (int)$office_id);
    return $t['access_token'];
}

function gd_office_connected($conn, int $office_id): bool {
    if (!($conn instanceof mysqli) || $office_id <= 0) return false;
    try {
        $r = $conn->query("SELECT gdrive_refresh_token FROM office_settings WHERE office_id=" . (int)$office_id . " LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        return $row && !empty($row['gdrive_refresh_token']);
    } catch (\Throwable $e) { return false; }
}

function gd_office_email($conn, int $office_id): string {
    try {
        $r = $conn->query("SELECT gdrive_email FROM office_settings WHERE office_id=" . (int)$office_id . " LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        return $row['gdrive_email'] ?? '';
    } catch (\Throwable $e) { return ''; }
}

// ════════════════════════════════════════════════
//  عمليات Drive
// ════════════════════════════════════════════════
function gd_find_or_create_folder(string $token, string $name, string $parent_id = 'root'): string {
    $safe = str_replace("'", "\\'", $name);
    $q = rawurlencode("name='$safe' and mimeType='application/vnd.google-apps.folder' and '$parent_id' in parents and trashed=false");
    $r = _gd_get("https://www.googleapis.com/drive/v3/files?q=$q&fields=files(id)&spaces=drive", $token);
    if ($r['code'] === 200 && !empty($r['json']['files'][0]['id'])) return $r['json']['files'][0]['id'];

    $meta = json_encode(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parent_id]]);
    $r = _gd_post('https://www.googleapis.com/drive/v3/files', $meta, [
        "Authorization: Bearer $token", "Content-Type: application/json",
    ]);
    return $r['json']['id'] ?? '';
}

/** مجلد "مِحكام" الجذر في درايف المكتب — يُنشأ مرة ويُخزَّن */
function gd_office_root($conn, int $office_id, string $token, string $office_name = ''): string {
    try {
        $r = $conn->query("SELECT gdrive_root_folder_id FROM office_settings WHERE office_id=" . (int)$office_id . " LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        if ($row && !empty($row['gdrive_root_folder_id'])) return $row['gdrive_root_folder_id'];
    } catch (\Throwable $e) {}

    $label = 'مِحكام' . ($office_name !== '' ? ' — ' . $office_name : '');
    $fid = gd_find_or_create_folder($token, $label, 'root');
    if ($fid !== '') {
        $fe = $conn->real_escape_string($fid);
        $conn->query("UPDATE office_settings SET gdrive_root_folder_id='$fe' WHERE office_id=" . (int)$office_id);
    }
    return $fid;
}

/** رفع بايتات إلى مجلد — يُعيد ['ok'=>bool,'id'=>string,'error'=>string] */
function gd_upload_bytes(string $token, string $folder_id, string $filename, string $bytes): array {
    $boundary = 'MHK_' . bin2hex(random_bytes(8));
    $meta = json_encode(['name' => $filename, 'parents' => [$folder_id]]);
    $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
          . $meta . "\r\n--$boundary\r\nContent-Type: application/octet-stream\r\n\r\n"
          . $bytes . "\r\n--$boundary--";
    $r = _gd_post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id', $body, [
        "Authorization: Bearer $token",
        "Content-Type: multipart/related; boundary=$boundary",
        "Content-Length: " . strlen($body),
    ]);
    if ($r['err']) return ['ok' => false, 'id' => '', 'error' => 'اتصال Drive فشل: ' . $r['err']];
    if ($r['code'] !== 200 || empty($r['json']['id'])) {
        return ['ok' => false, 'id' => '', 'error' => 'Drive: ' . ($r['json']['error']['message'] ?? ('HTTP ' . $r['code']))];
    }
    return ['ok' => true, 'id' => $r['json']['id'], 'error' => ''];
}

function gd_download_bytes(string $token, string $file_id): ?string {
    $file_id = trim($file_id);
    if ($file_id === '') return null;
    $r = _gd_get('https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?alt=media', $token, true);
    return ($r['code'] === 200 && $r['body'] !== false) ? $r['body'] : null;
}

function gd_delete_file(string $token, string $file_id): void {
    $file_id = trim($file_id);
    if ($file_id === '') return;
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ════════════════════════════════════════════════
//  أيقونة
// ════════════════════════════════════════════════
function gd_fileIcon(string $ext): string {
    $icons = [
        'pdf'=>'fa-file-pdf text-danger','doc'=>'fa-file-word text-primary','docx'=>'fa-file-word text-primary',
        'xls'=>'fa-file-excel text-success','xlsx'=>'fa-file-excel text-success',
        'jpg'=>'fa-file-image text-warning','jpeg'=>'fa-file-image text-warning','png'=>'fa-file-image text-warning',
        'zip'=>'fa-file-archive text-secondary','rar'=>'fa-file-archive text-secondary',
    ];
    return 'fas ' . ($icons[strtolower($ext)] ?? 'fa-file text-secondary');
}
