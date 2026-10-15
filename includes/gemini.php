<?php
/**
 * includes/gemini.php — مزوّد الذكاء الاصطناعي الوحيد في المنصة: Google Gemini.
 *
 * المفتاح يُؤخذ بالترتيب من:
 *   1) إعدادات النظام في لوحة الأدمن (site_content: gemini_api_key)
 *   2) config/ai_keys.php (['gemini' => '...'] — غير مرفوع إلى git)
 *   3) متغير البيئة GEMINI_API_KEY
 * والنموذج من gemini_model في الإعدادات (الافتراضي أدناه).
 */

if (!defined('GEMINI_DEFAULT_MODEL')) define('GEMINI_DEFAULT_MODEL', 'gemini-3-flash-preview');

function gemini_setting($conn, string $key): string {
    try {
        $k = $conn->real_escape_string($key);
        $r = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='$k' LIMIT 1");
        if ($r && ($row = $r->fetch_assoc())) return trim((string)$row['setting_value']);
    } catch (\Throwable $e) {}
    return '';
}

/** مفتاح Gemini أو '' إن لم يُضبط */
function gemini_key($conn): string {
    $k = gemini_setting($conn, 'gemini_api_key');
    if ($k === '') {
        $f = __DIR__ . '/../config/ai_keys.php';
        if (is_file($f)) { $cfg = @include $f; if (is_array($cfg)) $k = trim((string)($cfg['gemini'] ?? '')); }
    }
    if ($k === '') $k = trim((string)(getenv('GEMINI_API_KEY') ?: ''));
    if ($k === 'YOUR_GEMINI_API_KEY_HERE') $k = '';
    return $k;
}

function gemini_model($conn): string {
    $m = gemini_setting($conn, 'gemini_model');
    return $m !== '' ? $m : GEMINI_DEFAULT_MODEL;
}

/**
 * يرسل محادثة إلى Gemini.
 * $messages: [['role' => 'user'|'model'|'assistant', 'content' => '...'], ...]
 * $opts: max_tokens (افتراضي 2048)، temperature (0.7)، json (true = رد JSON)، timeout (60)
 * يُرجع: ['ok' => bool, 'content' => string, 'model' => string, 'error' => string, 'http' => int]
 */
function gemini_generate($conn, string $system, array $messages, array $opts = []): array {
    $key   = gemini_key($conn);
    $model = gemini_model($conn);
    $out   = ['ok' => false, 'content' => '', 'model' => $model, 'error' => '', 'http' => 0];
    if ($key === '') {
        $out['error'] = 'لم يُضبط مفتاح Gemini بعد — أضفه من لوحة الإدارة ← إعدادات النظام ← الذكاء الاصطناعي.';
        return $out;
    }

    $contents = [];
    foreach (array_slice($messages, -20) as $m) {
        $role = (($m['role'] ?? '') === 'model' || ($m['role'] ?? '') === 'assistant') ? 'model' : 'user';
        $contents[] = ['role' => $role, 'parts' => [['text' => (string)($m['content'] ?? '')]]];
    }
    if (!$contents) { $out['error'] = 'طلب غير صالح'; return $out; }

    $gen = ['maxOutputTokens' => (int)($opts['max_tokens'] ?? 2048), 'temperature' => (float)($opts['temperature'] ?? 0.7)];
    if (!empty($opts['json'])) $gen['responseMimeType'] = 'application/json';
    $body = ['contents' => $contents, 'generationConfig' => $gen];
    if ($system !== '') $body['systemInstruction'] = ['parts' => [['text' => $system]]];

    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $key], // المفتاح في الترويسة لا في الرابط
        CURLOPT_TIMEOUT        => (int)($opts['timeout'] ?? 60),
    ]);
    $res  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $out['http'] = $http;

    if ($res === false) { $out['error'] = 'خطأ في الاتصال بـ Gemini: ' . $err; return $out; }
    $data = json_decode($res, true);

    if ($http === 429) { $out['error'] = 'نفدت حصة Gemini مؤقتاً. انتظر دقيقة وأعد المحاولة، أو فعّل الفوترة على Google Cloud.'; return $out; }
    if ($http === 400 && stripos((string)($data['error']['message'] ?? ''), 'API key') !== false) { $out['error'] = 'مفتاح Gemini غير صحيح — راجعه في إعدادات النظام.'; return $out; }
    if ($http === 403) { $out['error'] = 'مفتاح Gemini غير مصرّح له باستخدام هذا النموذج — راجع المفتاح والنموذج في إعدادات النظام.'; return $out; }
    if ($http !== 200) { $out['error'] = (string)($data['error']['message'] ?? ('HTTP ' . $http)); return $out; }

    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    $text  = '';
    foreach ($parts as $p) if (isset($p['text']) && empty($p['thought'])) $text .= $p['text'];
    if ($text === '') {
        $out['error'] = (($data['candidates'][0]['finishReason'] ?? '') === 'SAFETY')
            ? 'رُفض الرد بسبب سياسات الأمان. أعد صياغة السؤال.'
            : 'لم يُرجع Gemini رداً. أعد المحاولة.';
        return $out;
    }
    $out['ok'] = true; $out['content'] = $text;
    return $out;
}
