<?php
/**
 * includes/whatsapp.php — إرسال تنبيهات واتساب
 * ════════════════════════════════════════════════════════════
 *  المزوّدات (wa_provider في site_content):
 *
 *   callmebot  ✅ مجاني تماماً — لا خادم ولا اشتراك
 *       كل مستخدم يفعّله لنفسه مرة واحدة:
 *         - يضيف الرقم  +34 644 51 95 23  في جهات اتصاله
 *         - يرسل له واتساب: "I allow callmebot to send me messages"
 *         - يستقبل apikey ويضعه في ملفه الشخصي (مع رقم جواله)
 *       مناسب لتنبيهات مكتب صغير (حد ~رسالة/دقيقة لكل رقم).
 *
 *   greenapi   ✅ باقة مطوّر مجانية — مسح QR واحد للمكتب
 *       green-api.com → Developer plan → idInstance + apiTokenInstance
 *       الإعدادات: wa_green_instance + wa_green_token
 *
 *   ultramsg   💲 مدفوع — رقم واتساب عادي، رسائل حرّة
 *       الإعدادات: wa_ultramsg_instance + wa_ultramsg_token
 *
 *   meta       💲 رسمي — يتطلب قوالب معتمدة للرسائل التلقائية
 *       الإعدادات: wa_meta_phone_id + wa_meta_token
 *
 *  ربط المستخدم: users.whatsapp_number + users.notify_whatsapp + users.wa_callmebot_key
 * ════════════════════════════════════════════════════════════
 */

if (!function_exists('wa_get')):

function wa_get($conn, $key, $default = '') {
    static $c = null;
    if ($c === null) {
        $c = [];
        $keys = "'wa_provider','wa_ultramsg_instance','wa_ultramsg_token',"
              . "'wa_meta_phone_id','wa_meta_token','wa_green_instance','wa_green_token','wa_green_url',"
              . "'wa_selfhost_url','wa_selfhost_secret'";
        try {
            $r = $conn->query("SELECT setting_key, setting_value FROM site_content WHERE setting_key IN ($keys)");
            if ($r) while ($row = $r->fetch_assoc()) $c[$row['setting_key']] = $row['setting_value'];
        } catch (\Throwable $e) {}
    }
    return isset($c[$key]) && $c[$key] !== '' ? $c[$key] : $default;
}

function wa_provider($conn) {
    $p = wa_get($conn, 'wa_provider', 'selfhost');
    return in_array($p, ['selfhost', 'callmebot', 'greenapi', 'ultramsg', 'meta'], true) ? $p : 'selfhost';
}

/** هل المزوّد جاهز على مستوى المنصة؟ (callmebot يعمل بمفتاح كل مستخدم) */
function wa_configured($conn) {
    switch (wa_provider($conn)) {
        case 'selfhost':  return wa_get($conn, 'wa_selfhost_url') !== '';
        case 'callmebot': return true;
        case 'greenapi':  return wa_get($conn, 'wa_green_instance') !== '' && wa_get($conn, 'wa_green_token') !== '';
        case 'meta':      return wa_get($conn, 'wa_meta_phone_id') !== '' && wa_get($conn, 'wa_meta_token') !== '';
        case 'ultramsg':  return wa_get($conn, 'wa_ultramsg_instance') !== '' && wa_get($conn, 'wa_ultramsg_token') !== '';
    }
    return false;
}

/** توحيد الرقم إلى صيغة دولية بدون + (966XXXXXXXXX) */
function wa_normalize_phone($p) {
    $p = preg_replace('/\D/', '', (string) $p);
    if ($p === '') return '';
    if (strpos($p, '00') === 0)  $p = substr($p, 2);
    if (strpos($p, '966') === 0) return $p;
    if (strpos($p, '0') === 0)   return '966' . substr($p, 1);
    if (strlen($p) === 9)        return '966' . $p;
    return $p;
}

function wa_http($method, $url, array $headers = [], $body = null, $json = true) {
    if (!function_exists('curl_init')) return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'cURL غير متاح'];
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($body !== null) $opt[CURLOPT_POSTFIELDS] = $json ? json_encode($body, JSON_UNESCAPED_UNICODE) : $body;
    curl_setopt_array($ch, $opt);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) return ['ok' => false, 'status' => $st, 'data' => null, 'error' => $err];
    $d = json_decode((string) $res, true);
    return ['ok' => ($st >= 200 && $st < 300), 'status' => $st, 'data' => ($d !== null ? $d : (string) $res), 'error' => ($st >= 400 ? "HTTP $st" : '')];
}

/**
 * إرسال رسالة واتساب.
 * $opts['callmebot_key'] — مفتاح المستخدم (لمزوّد callmebot فقط)
 * يُعيد ['ok'=>bool,'msg'=>string]
 */
function wa_send($conn, $phone, $text, array $opts = []) {
    $to = wa_normalize_phone($phone);
    if ($to === '') return ['ok' => false, 'msg' => 'رقم غير صالح'];
    $prov = wa_provider($conn);

    if ($prov === 'selfhost') {
        $url = rtrim(wa_get($conn, 'wa_selfhost_url'), '/');
        $sec = wa_get($conn, 'wa_selfhost_secret');
        if ($url === '') return ['ok' => false, 'msg' => 'رابط البوت غير مضبوط'];
        $r = wa_http('POST', $url . '/send', ['Content-Type: application/json'],
            ['secret' => $sec, 'phone' => $to, 'text' => $text]);
        $ok = $r['ok'] && is_array($r['data']) && !empty($r['data']['ok']);
        $err = is_array($r['data']) ? ($r['data']['error'] ?? 'خطأ') : ($r['error'] ?: 'HTTP ' . $r['status']);
        return ['ok' => $ok, 'msg' => $ok ? 'أُرسلت' : ('فشل: ' . $err)];
    }

    if ($prov === 'callmebot') {
        $key = trim($opts['callmebot_key'] ?? '');
        if ($key === '') return ['ok' => false, 'msg' => 'لا يوجد مفتاح CallMeBot لهذا المستخدم'];
        $url = 'https://api.callmebot.com/whatsapp.php?phone=' . rawurlencode('+' . $to)
             . '&text=' . rawurlencode($text) . '&apikey=' . rawurlencode($key);
        $r = wa_http('GET', $url);
        $txt = is_string($r['data']) ? $r['data'] : json_encode($r['data']);
        $ok = $r['ok'] && (stripos($txt, 'queued') !== false || stripos($txt, 'sent') !== false || stripos($txt, 'success') !== false);
        return ['ok' => $ok, 'msg' => $ok ? 'أُرسلت' : ('فشل: ' . trim(strip_tags($txt)))];
    }

    if ($prov === 'greenapi') {
        $id  = wa_get($conn, 'wa_green_instance');
        $tok = wa_get($conn, 'wa_green_token');
        if ($id === '' || $tok === '') return ['ok' => false, 'msg' => 'إعداد Green API ناقص'];
        $base = rtrim(wa_get($conn, 'wa_green_url', 'https://api.green-api.com'), '/');
        $r = wa_http('POST', "{$base}/waInstance{$id}/sendMessage/{$tok}",
            ['Content-Type: application/json'],
            ['chatId' => $to . '@c.us', 'message' => $text]);
        $ok = $r['ok'] && is_array($r['data']) && !empty($r['data']['idMessage']);
        return ['ok' => $ok, 'msg' => $ok ? 'أُرسلت' : ('فشل: ' . (is_array($r['data']) ? json_encode($r['data'], JSON_UNESCAPED_UNICODE) : ($r['error'] ?: 'HTTP ' . $r['status'])))];
    }

    if ($prov === 'meta') {
        $pid = wa_get($conn, 'wa_meta_phone_id');
        $tok = wa_get($conn, 'wa_meta_token');
        $r = wa_http('POST', "https://graph.facebook.com/v21.0/{$pid}/messages",
            ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'],
            ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $text]]);
        $ok = $r['ok'] && is_array($r['data']) && !empty($r['data']['messages']);
        return ['ok' => $ok, 'msg' => $ok ? 'أُرسلت' : ('فشل: ' . (is_array($r['data']) ? ($r['data']['error']['message'] ?? 'خطأ') : ($r['error'] ?: 'HTTP ' . $r['status'])))];
    }

    // ultramsg
    $inst = wa_get($conn, 'wa_ultramsg_instance');
    $tok  = wa_get($conn, 'wa_ultramsg_token');
    $r = wa_http('POST', "https://api.ultramsg.com/{$inst}/messages/chat",
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['token' => $tok, 'to' => $to, 'body' => $text]), false);
    $sent = is_array($r['data']) ? (($r['data']['sent'] ?? '') === 'true' || ($r['data']['sent'] ?? false) === true)
                                  : (stripos((string) $r['data'], '"sent":"true"') !== false);
    $ok = $r['ok'] && $sent;
    return ['ok' => $ok, 'msg' => $ok ? 'أُرسلت' : ('فشل: ' . (is_array($r['data']) ? ($r['data']['error'] ?? $r['data']['message'] ?? 'خطأ') : (string) $r['data']))];
}

/** نص واتساب مبسّط من HTML */
function wa_from_html($s) {
    return trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string) $s)), ENT_QUOTES, 'UTF-8'));
}

/** إرسال لمستخدم من مصفوفة صف users (يقرأ الرقم والمفتاح والتفعيل) */
function wa_send_user($conn, array $user, $text) {
    if (empty($user['whatsapp_number'])) return ['ok' => false, 'msg' => 'لا رقم'];
    if (isset($user['notify_whatsapp']) && !$user['notify_whatsapp']) return ['ok' => false, 'msg' => 'التنبيهات موقوفة'];
    return wa_send($conn, $user['whatsapp_number'], $text, ['callmebot_key' => $user['wa_callmebot_key'] ?? '']);
}

endif;
