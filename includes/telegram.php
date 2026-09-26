<?php
/**
 * includes/telegram.php
 * ════════════════════════════════════════════════════════════
 *  تكامل تيليغرام — إرسال تنبيهات للمحامين.
 *
 *  الإعداد (لوحة الإدارة ← إعدادات المنصة):
 *    telegram_bot_token     توكن البوت من @BotFather
 *    telegram_bot_username  معرّف البوت بدون @ (مثال: MehkamAlerts_bot)
 *    telegram_lead_hours    ساعات التذكير قبل الجلسة، مفصولة بفاصلة (افتراضي: 24,3)
 *    telegram_digest_hour   ساعة الملخّص اليومي 0-23 (افتراضي: 7)
 *    cron_secret            مفتاح تشغيل ملف الكرون عبر الويب
 *
 *  ربط المستخدم: users.telegram_chat_id + users.notify_telegram
 * ════════════════════════════════════════════════════════════
 */

if (is_file(__DIR__ . '/whatsapp.php'))       require_once __DIR__ . '/whatsapp.php';
if (is_file(__DIR__ . '/content_helper.php')) require_once __DIR__ . '/content_helper.php';
if (is_file(__DIR__ . '/mailer.php'))         require_once __DIR__ . '/mailer.php';

function tg_get($conn, $key, $default = '') {
    static $c = null;
    if ($c === null) {
        $c = [];
        try {
            $r = $conn->query("SELECT setting_key, setting_value FROM site_content
                WHERE setting_key IN ('telegram_bot_token','telegram_bot_username','telegram_lead_hours','telegram_digest_hour','cron_secret')");
            if ($r) while ($row = $r->fetch_assoc()) $c[$row['setting_key']] = $row['setting_value'];
        } catch (\Throwable $e) {}
    }
    return isset($c[$key]) && $c[$key] !== '' ? $c[$key] : $default;
}

function tg_configured($conn) {
    return tg_get($conn, 'telegram_bot_token') !== '';
}

/** استدعاء Telegram Bot API — يُعيد ['ok'=>bool, ...] */
function tg_api($conn, $method, array $params = []) {
    $token = tg_get($conn, 'telegram_bot_token');
    if ($token === '') return ['ok' => false, 'description' => 'توكن البوت غير مضبوط'];
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok' => false, 'description' => 'اتصال فشل: ' . $err];
    $d = json_decode((string)$res, true);
    return is_array($d) ? $d : ['ok' => false, 'description' => 'رد غير صالح من تيليغرام'];
}

/** إرسال رسالة لمستخدم عبر chat_id */
function tg_send($conn, $chat_id, $text) {
    if (!$chat_id) return false;
    $r = tg_api($conn, 'sendMessage', [
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
    ]);
    if (empty($r['ok'])) error_log('tg_send failed: ' . json_encode($r));
    return !empty($r['ok']);
}

/** تسجيل التنبيه لمنع التكرار — يُعيد true إذا كان جديداً (يجب الإرسال) */
function tg_should_send($conn, $user_id, $kind, $ref) {
    $u = (int)$user_id;
    $k = $conn->real_escape_string($kind);
    $rf = $conn->real_escape_string($ref);
    try {
        $conn->query("INSERT IGNORE INTO notification_log (user_id, kind, ref) VALUES ($u, '$k', '$rf')");
        return $conn->affected_rows === 1;
    } catch (\Throwable $e) {
        return false;
    }
}

/** رابط ربط تيليغرام العميق للمستخدم الحالي */
function tg_link_url($conn, $token) {
    $bot = tg_get($conn, 'telegram_bot_username');
    if ($bot === '' || $token === '') return '';
    return 'https://t.me/' . ltrim($bot, '@') . '?start=' . rawurlencode($token);
}

/** تنسيق تاريخ حسب تفضيل المستخدم */
function tg_fmt_date($datetime, $pref, $withTime = true) {
    $ts = is_numeric($datetime) ? (int)$datetime : strtotime((string)$datetime);
    if (!$ts) return (string)$datetime;
    $g = date('d/m/Y', $ts) . ($withTime ? ' الساعة ' . date('H:i', $ts) : '');
    if ($pref === 'gregorian' || !function_exists('hijriDate')) return $g;
    $h = hijriDate($ts) . ($withTime ? ' الساعة ' . date('H:i', $ts) : '');
    if ($pref === 'hijri') return $h;
    return $h . ' (' . $g . ')';
}

/** إشعار "جلسة جديدة" فوراً — داخل المنصة + تيليغرام لمستخدمي المكتب النشطين */
function tg_notify_new_session($conn, $office_id, $case_title, $case_number, $session_date) {
    $oid = (int)$office_id;
    $tg_on = tg_configured($conn);
    $wa_on = function_exists('wa_configured') && wa_configured($conn);
    $mail_on = function_exists('sc') && function_exists('sendMail') && sc($conn, 'smtp_enabled', '0') === '1';
    $site = function_exists('sc') ? sc($conn, 'site_name', 'مِحكام') : 'مِحكام';
    try {
        $r = $conn->query("SELECT id, full_name, email, telegram_chat_id, notify_telegram, whatsapp_number, notify_whatsapp, wa_callmebot_key, notify_email, calendar_pref FROM users
            WHERE office_id=$oid AND is_active=1 AND (notify_telegram=1 OR notify_whatsapp=1 OR notify_email=1)");
        if (!$r) return;
        while ($u = $r->fetch_assoc()) {
            $when = tg_fmt_date($session_date, $u['calendar_pref'] ?? 'gregorian');
            $line = 'القضية ' . $case_number . ' — ' . mb_substr($case_title, 0, 80) . ' — ' . $when;
            // داخل المنصة
            $t = $conn->real_escape_string('جلسة جديدة');
            $m = $conn->real_escape_string(mb_substr($line, 0, 500));
            $conn->query("INSERT INTO notifications (user_id,office_id,title,message,type) VALUES (" . (int)$u['id'] . ",$oid,'$t','$m','info')");
            // تيليغرام
            if ($tg_on && !empty($u['telegram_chat_id']) && !empty($u['notify_telegram'])) {
                tg_send($conn, $u['telegram_chat_id'],
                    "📅 <b>جلسة جديدة</b>\nالقضية: " . htmlspecialchars($case_number . ' — ' . mb_substr($case_title, 0, 80)) . "\nالموعد: " . htmlspecialchars($when));
            }
            // واتساب
            if ($wa_on && !empty($u['whatsapp_number']) && !empty($u['notify_whatsapp'])) {
                wa_send_user($conn, $u,
                    "📅 *جلسة جديدة*\nالقضية: " . $case_number . ' — ' . mb_substr($case_title, 0, 80) . "\nالموعد: " . $when);
            }
            // بريد إلكتروني
            if ($mail_on && !empty($u['email']) && (!isset($u['notify_email']) || $u['notify_email'])) {
                $eb = 'القضية: ' . htmlspecialchars($case_number . ' — ' . mb_substr($case_title, 0, 80)) . '<br>الموعد: ' . htmlspecialchars($when);
                @sendMail($conn, $u['email'], $u['full_name'] ?? '', 'جلسة جديدة — ' . $case_number, mailHtml($site, '📅 جلسة جديدة', $eb));
            }
        }
    } catch (\Throwable $e) {}
}
