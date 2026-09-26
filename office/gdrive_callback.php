<?php
/**
 * office/gdrive_callback.php
 * يستقبل رد Google بعد موافقة المكتب، ويحفظ refresh_token.
 * ⚠️ هذا هو "Authorized redirect URI" الذي يجب إدخاله في Google Cloud:
 *     https://<نطاقك>/office/gdrive_callback.php
 */
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/gdrive_helper.php';
requireOffice();

$oid = (int)($_SESSION['office_id'] ?? 0);

function gd_cb_fail(string $msg) {
    $_SESSION['gdrive_connect_error'] = $msg;
    header('Location: profile.php?tab=office&msg=gd_fail');
    exit;
}

if (currentRole() !== 'office_owner') gd_cb_fail('الربط متاح لمالك المكتب فقط');

// أخطاء Google (رفض المستخدم مثلاً)
if (!empty($_GET['error'])) gd_cb_fail('أُلغي الربط: ' . preg_replace('/[^a-z_]/', '', $_GET['error']));

// التحقق من state
$state = $_GET['state'] ?? '';
if (!$state || empty($_SESSION['gdrive_oauth_state']) || !hash_equals($_SESSION['gdrive_oauth_state'], $state)) {
    gd_cb_fail('انتهت صلاحية جلسة الربط، حاول مرة أخرى');
}
unset($_SESSION['gdrive_oauth_state']);

$code = $_GET['code'] ?? '';
if (!$code) gd_cb_fail('لم يصل رمز التفويض من Google');

// تبديل الرمز بالتوكنات
$tok = gd_oauth_exchange_code($code);
if (empty($tok['refresh_token'])) {
    // بدون refresh_token لا نقدر نرفع لاحقاً — غالباً المستخدم وافق سابقاً بدون prompt=consent
    gd_cb_fail('لم تُرجِع Google رمز تحديث. من إعدادات حساب Google → الأمان → تطبيقات الطرف الثالث، احذف "مِحكام" ثم أعد الربط.');
}

// أعمدة office_settings (توافق)
foreach ([
    "ALTER TABLE office_settings ADD COLUMN storage_driver ENUM('server','gdrive') NOT NULL DEFAULT 'server'",
    "ALTER TABLE office_settings ADD COLUMN encrypt_files TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE office_settings ADD COLUMN storage_salt VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE office_settings ADD COLUMN gdrive_refresh_token TEXT",
    "ALTER TABLE office_settings ADD COLUMN gdrive_access_token TEXT",
    "ALTER TABLE office_settings ADD COLUMN gdrive_token_expiry BIGINT NOT NULL DEFAULT 0",
    "ALTER TABLE office_settings ADD COLUMN gdrive_root_folder_id VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE office_settings ADD COLUMN gdrive_email VARCHAR(200) DEFAULT NULL",
] as $ddl) { try { $conn->query($ddl); } catch (\Throwable $e) {} }

$email = '';
$info = gd_oauth_userinfo($tok['access_token'] ?? '');
if (!empty($info['email'])) $email = $info['email'];

$rt  = $conn->real_escape_string($tok['refresh_token']);
$at  = $conn->real_escape_string($tok['access_token'] ?? '');
$exp = time() + (int)($tok['expires_in'] ?? 3600);
$em  = $conn->real_escape_string($email);

$conn->query("INSERT INTO office_settings (office_id, storage_driver, gdrive_refresh_token, gdrive_access_token, gdrive_token_expiry, gdrive_email, gdrive_root_folder_id)
    VALUES ($oid, 'gdrive', '$rt', '$at', $exp, " . ($em !== '' ? "'$em'" : "NULL") . ", NULL)
    ON DUPLICATE KEY UPDATE
    storage_driver='gdrive',
    gdrive_refresh_token='$rt',
    gdrive_access_token='$at',
    gdrive_token_expiry=$exp,
    gdrive_email=" . ($em !== '' ? "'$em'" : "gdrive_email") . ",
    gdrive_root_folder_id=NULL");

// أنشئ مجلد "مِحكام" في درايف المكتب الآن (اختبار عملي للربط)
try {
    $token = gd_office_token($conn, $oid);
    if ($token) gd_office_root($conn, $oid, $token, $_SESSION['office_name'] ?? '');
} catch (\Throwable $e) {}

header('Location: profile.php?tab=office&msg=gd_connected');
exit;
