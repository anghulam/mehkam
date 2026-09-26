<?php
/**
 * ajax_paymob_init.php
 * تهيئة Paymob وإعادة رابط iframe للدفع
 */
header('Content-Type: application/json');
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/payment_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error'=>'method']); exit; }

$amount_sar  = max(1, (float)($_POST['amount'] ?? 0));
$method      = in_array($_POST['method'] ?? '', ['card','applepay']) ? $_POST['method'] : 'card';
$ref         = preg_replace('/[^a-z0-9_-]/i', '', $_POST['ref'] ?? uniqid('MHK'));
$payer_name  = trim($_POST['name'] ?? 'Guest');
$payer_email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: 'guest@example.com';
$payer_phone = preg_replace('/[^0-9+]/','',$_POST['phone'] ?? '0000000000') ?: '0000000000';

// Paymob يعمل بالجنيه المصري — نحول بالتعادل التقريبي أو نستخدم SAR مباشرة إذا الحساب مهيأ
// لو الحساب مهيأ بـ SAR استخدم: $amount_cents = (int)($amount_sar * 100);
// لو EGP: $amount_cents = (int)($amount_sar * 1340); // ≈ 1 SAR = 13.4 EGP
// هنا نفترض أن الحساب مهيأ بـ SAR ويقبل cents
$amount_cents = (int)($amount_sar * 100);

$integration_id = $method === 'applepay'
    ? sc($conn,'paymob_applepay_integration_id','')
    : sc($conn,'paymob_card_integration_id','');

$iframe_id = sc($conn,'paymob_iframe_id','');

if (!sc($conn,'paymob_api_key','')) {
    echo json_encode(['error'=>'مفتاح Paymob API غير مُدخل. اذهب إلى إعدادات بوابات الدفع.']); exit;
}
if (!$integration_id) {
    echo json_encode(['error'=>'Integration ID لـ '.($method==='applepay'?'Apple Pay':'البطاقة').' غير مُدخل في إعدادات Paymob.']); exit;
}
if (!$iframe_id) {
    echo json_encode(['error'=>'Iframe ID لـ Paymob غير مُدخل في الإعدادات.']); exit;
}

// 1. Auth token
$token = paymob_get_token($conn);
if (!$token) { echo json_encode(['error'=>'فشل الاتصال بـ Paymob']); exit; }

// 2. Create order
$order = paymob_create_order($conn, $token, $amount_cents, $ref);
if (empty($order['id'])) { echo json_encode(['error'=>'فشل إنشاء الأمر في Paymob','detail'=>$order]); exit; }

// 3. Payment key
$billing = [
    'apartment'=>'NA','email'=>$payer_email,'floor'=>'NA','first_name'=>explode(' ',$payer_name)[0],
    'street'=>'NA','building'=>'NA','phone_number'=>$payer_phone,'shipping_method'=>'NA',
    'postal_code'=>'NA','city'=>'NA','country'=>'SA','last_name'=>explode(' ',$payer_name,2)[1] ?? 'NA',
    'state'=>'NA',
];
$pay_token = paymob_get_payment_key($conn, $token, (int)$order['id'], $amount_cents, $billing, $integration_id);
if (!$pay_token) { echo json_encode(['error'=>'فشل الحصول على مفتاح الدفع']); exit; }

$iframe_base = rtrim(sc($conn,'paymob_iframe_base_url','https://ksa.paymob.com/api/acceptance/iframes'), '/');
$iframe_url  = "{$iframe_base}/{$iframe_id}?payment_token={$pay_token}";

echo json_encode(['ok'=>true, 'iframe_url'=>$iframe_url, 'order_id'=>$order['id']]);
