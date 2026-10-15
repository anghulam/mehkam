<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/gemini.php';
requireOffice();

header('Content-Type: application/json; charset=utf-8');

// ═══════════════════════════════════════════════════════════
//  المنصة تستخدم Google Gemini فقط (المساعد الذكي، المساعد القانوني، صياغة العقود).
//  المفتاح والنموذج من: لوحة الأدمن ← إعدادات النظام ← الذكاء الاصطناعي
//  (أو config/ai_keys.php / متغير البيئة GEMINI_API_KEY) — راجع includes/gemini.php
// ═══════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']); exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['messages'])) {
    echo json_encode(['error' => 'طلب غير صالح']); exit;
}

$r = gemini_generate($conn, (string)($body['system'] ?? ''), (array)$body['messages'], ['max_tokens' => 2048]);
if (!$r['ok']) {
    echo json_encode(['error' => $r['error']], JSON_UNESCAPED_UNICODE); exit;
}
echo json_encode(['content' => $r['content'], 'model' => $r['model']], JSON_UNESCAPED_UNICODE);
