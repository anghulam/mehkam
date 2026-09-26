<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();

header('Content-Type: application/json; charset=utf-8');

// ═══════════════════════════════════════════════════════════
//  إعدادات المزود — اختر واحداً وضع مفتاحه
//
//  GROQ (مجاني تماماً، سريع جداً):
//    سجل على https://console.groq.com → API Keys
//    النماذج المتاحة مجاناً: llama-3.3-70b-versatile, gemma2-9b-it
//
//  GEMINI (بعد تفعيل Billing على Google Cloud):
//    https://aistudio.google.com/app/apikey
//    النماذج: gemini-1.5-flash, gemini-1.5-pro
//
//  OPENROUTER (يوفر credits مجانية عند التسجيل):
//    https://openrouter.ai/keys
//    النماذج المجانية: google/gemma-3-27b-it:free, meta-llama/llama-4-scout:free
// ═══════════════════════════════════════════════════════════

// ─── اختر المزود: 'groq' | 'gemini' | 'openrouter' ───
$PROVIDER = 'groq';

// ─── مفاتيح API — من config/ai_keys.php (غير مرفوع) أو متغيرات البيئة ───
$KEYS = is_file(__DIR__ . '/../config/ai_keys.php') ? (require __DIR__ . '/../config/ai_keys.php') : [];
$KEYS += ['groq' => getenv('GROQ_API_KEY') ?: '', 'gemini' => getenv('GEMINI_API_KEY') ?: '', 'openrouter' => getenv('OPENROUTER_API_KEY') ?: ''];

// ─── النماذج لكل مزود ───
$MODELS = [
    'groq'       => 'llama-3.3-70b-versatile',
    'gemini'     => 'gemini-3-flash-preview',
    'openrouter' => 'google/gemma-3-27b-it:free',
];

// ═══════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']); exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['messages'])) {
    echo json_encode(['error' => 'طلب غير صالح']); exit;
}

$system_prompt = $body['system']   ?? '';
$messages      = $body['messages'] ?? [];
$messages      = array_slice($messages, -20);

$api_key = $KEYS[$PROVIDER]   ?? '';
$model   = $MODELS[$PROVIDER] ?? '';

if (empty($api_key) || $api_key === 'YOUR_GROQ_API_KEY_HERE' ||
    $api_key === 'YOUR_GEMINI_API_KEY_HERE' || $api_key === 'YOUR_OPENROUTER_API_KEY_HERE') {
    echo json_encode(['error' => 'لم يتم إعداد مفتاح API بعد. افتح ملف ai_proxy.php وضع مفتاحك.']);
    exit;
}

// ─── GROQ / OPENROUTER — يستخدمان صيغة OpenAI ───
if ($PROVIDER === 'groq' || $PROVIDER === 'openrouter') {

    // بناء الرسائل بصيغة OpenAI
    $msgs = [];
    if ($system_prompt) {
        $msgs[] = ['role' => 'system', 'content' => $system_prompt];
    }
    foreach ($messages as $m) {
        $role = ($m['role'] === 'model') ? 'assistant' : $m['role'];
        $msgs[] = ['role' => $role, 'content' => $m['content']];
    }

    $payload = json_encode([
        'model'       => $model,
        'messages'    => $msgs,
        'max_tokens'  => 1500,
        'temperature' => 0.7,
    ], JSON_UNESCAPED_UNICODE);

    $url     = $PROVIDER === 'groq'
        ? 'https://api.groq.com/openai/v1/chat/completions'
        : 'https://openrouter.ai/api/v1/chat/completions';

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
    ];
    if ($PROVIDER === 'openrouter') {
        $headers[] = 'HTTP-Referer: https://lawsaas.com';
        $headers[] = 'X-Title: LawSaaS Legal Assistant';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo json_encode(['error' => 'خطأ في الاتصال: ' . $curlErr]); exit;
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? ('HTTP Error ' . $httpCode);
        // رسالة صديقة لخطأ الحصة
        if ($httpCode === 429) {
            $errMsg = 'نفدت حصة الطلبات المجانية مؤقتاً. انتظر دقيقة وأعد المحاولة.';
        }
        echo json_encode(['error' => $errMsg]); exit;
    }

    $content = $data['choices'][0]['message']['content'] ?? '';
    echo json_encode(['content' => $content, 'model' => $model], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── GEMINI ───
if ($PROVIDER === 'gemini') {

    $gemini_contents = [];
    if ($system_prompt) {
        $gemini_contents[] = ['role' => 'user',  'parts' => [['text' => $system_prompt]]];
        $gemini_contents[] = ['role' => 'model', 'parts' => [['text' => 'حسناً، سأتبع هذه التعليمات.']]];
    }
    foreach ($messages as $m) {
        $role = ($m['role'] === 'model' || $m['role'] === 'assistant') ? 'model' : 'user';
        $gemini_contents[] = ['role' => $role, 'parts' => [['text' => $m['content']]]];
    }

    $payload = json_encode([
        'contents'         => $gemini_contents,
        'generationConfig' => ['maxOutputTokens' => 1500, 'temperature' => 0.7],
    ], JSON_UNESCAPED_UNICODE);

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo json_encode(['error' => 'خطأ في الاتصال: ' . $curlErr]); exit;
    }

    $data = json_decode($response, true);

    if ($httpCode === 429) {
        echo json_encode(['error' => 'نفدت حصة Gemini المجانية. فعّل الـ Billing على Google Cloud أو استخدم مزوداً آخر.']); exit;
    }
    if ($httpCode !== 200) {
        echo json_encode(['error' => $data['error']['message'] ?? 'HTTP ' . $httpCode]); exit;
    }

    $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (empty($content) && ($data['candidates'][0]['finishReason'] ?? '') === 'SAFETY') {
        echo json_encode(['error' => 'رُفض الرد بسبب سياسات الأمان. أعد صياغة السؤال.']); exit;
    }

    echo json_encode(['content' => $content, 'model' => $model], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'مزود غير معروف: ' . $PROVIDER]);
