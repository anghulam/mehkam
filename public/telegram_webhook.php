<?php
/**
 * public/telegram_webhook.php
 * يستقبل تحديثات بوت تيليغرام.
 * سجّله مرة واحدة عبر: لوحة الإدارة ← إعدادات المنصة ← «تفعيل الويبهوك»
 * أو يدوياً:
 *   https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://<نطاقك>/public/telegram_webhook.php
 */
require_once '../config/db.php';
require_once '../includes/telegram.php';

http_response_code(200); // نرد بسرعة دائماً

$raw = file_get_contents('php://input');
$upd = json_decode($raw, true);
if (!is_array($upd)) exit;

$msg = $upd['message'] ?? $upd['edited_message'] ?? null;
if (!$msg) exit;

$chat_id = $msg['chat']['id'] ?? null;
$text    = trim($msg['text'] ?? '');
if (!$chat_id) exit;

function _wh_reply($conn, $chat_id, $t) { tg_send($conn, $chat_id, $t); }

// /start <token>  → ربط الحساب
if (preg_match('/^\/start(?:\s+(\S+))?/', $text, $m)) {
    $tok = $m[1] ?? '';
    if ($tok === '') {
        _wh_reply($conn, $chat_id, "مرحباً بك في تنبيهات <b>مِحكام</b> ⚖️\nلربط حسابك، افتح المنصة ← الملف الشخصي ← «ربط تيليغرام» واستخدم الرابط هناك.");
        exit;
    }
    $te = $conn->real_escape_string($tok);
    $ci = $conn->real_escape_string((string)$chat_id);
    try {
        $r = $conn->query("SELECT id, full_name FROM users WHERE tg_link_token='$te' LIMIT 1");
        $u = $r ? $r->fetch_assoc() : null;
        if (!$u) {
            _wh_reply($conn, $chat_id, "رابط الربط غير صالح أو منتهٍ. أنشئ رابطاً جديداً من المنصة.");
            exit;
        }
        // أزل هذا chat_id من أي مستخدم آخر (منع التكرار)
        $conn->query("UPDATE users SET telegram_chat_id=NULL WHERE telegram_chat_id='$ci'");
        $conn->query("UPDATE users SET telegram_chat_id='$ci', notify_telegram=1, tg_link_token=NULL WHERE id=" . (int)$u['id']);
        _wh_reply($conn, $chat_id, "✅ تم ربط حسابك بنجاح يا <b>" . htmlspecialchars($u['full_name']) . "</b>.\nستصلك تنبيهات الجلسات والمواعيد هنا.\n\nلإيقاف التنبيهات: /stop");
    } catch (\Throwable $e) {}
    exit;
}

// /stop  → إيقاف التنبيهات
if ($text === '/stop') {
    $ci = $conn->real_escape_string((string)$chat_id);
    try { $conn->query("UPDATE users SET notify_telegram=0 WHERE telegram_chat_id='$ci'"); } catch (\Throwable $e) {}
    _wh_reply($conn, $chat_id, "🔕 تم إيقاف التنبيهات. لإعادة التفعيل: /resume");
    exit;
}

if ($text === '/resume') {
    $ci = $conn->real_escape_string((string)$chat_id);
    try { $conn->query("UPDATE users SET notify_telegram=1 WHERE telegram_chat_id='$ci'"); } catch (\Throwable $e) {}
    _wh_reply($conn, $chat_id, "🔔 تم تفعيل التنبيهات مجدداً.");
    exit;
}

if ($text === '/status' || $text === '/ping') {
    $ci = $conn->real_escape_string((string)$chat_id);
    try {
        $r = $conn->query("SELECT full_name, notify_telegram FROM users WHERE telegram_chat_id='$ci' LIMIT 1");
        $u = $r ? $r->fetch_assoc() : null;
    } catch (\Throwable $e) { $u = null; }
    if ($u) {
        _wh_reply($conn, $chat_id, "الحساب: <b>" . htmlspecialchars($u['full_name']) . "</b>\nالتنبيهات: " . ($u['notify_telegram'] ? '🔔 مفعّلة' : '🔕 متوقفة'));
    } else {
        _wh_reply($conn, $chat_id, "حسابك غير مربوط بعد. اربطه من المنصة ← الملف الشخصي.");
    }
    exit;
}

_wh_reply($conn, $chat_id, "الأوامر المتاحة:\n/status — حالة الربط\n/stop — إيقاف التنبيهات\n/resume — إعادة التفعيل");
