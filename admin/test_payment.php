<?php
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/payment_helper.php';
if (session_status()===PHP_SESSION_NONE) session_start();
/* حماية: يجب أن يكون المستخدم أدمن أو يمرر مفتاح مؤقت */
$is_admin_session = isset($_SESSION['role']) && $_SESSION['role']==='admin';
$dev_key = '?key=mehkam_dev_2024';
$has_key = ($_GET['key'] ?? '') === 'mehkam_dev_2024';
if (!$is_admin_session && !$has_key) {
    die('<p style="font-family:Arial;padding:20px">أضف <b>?key=mehkam_dev_2024</b> للرابط</p>');
}

header('Content-Type: text/html; charset=utf-8');

function show($label, $val, $ok = null) {
    $color = $ok === null ? '#1e293b' : ($ok ? '#15803d' : '#dc2626');
    $icon  = $ok === null ? '🔍' : ($ok ? '✅' : '❌');
    echo "<div style='margin:6px 0;font-family:monospace;font-size:13px'>
        <strong style='color:$color'>$icon $label:</strong>
        <span style='color:#475569'>".htmlspecialchars(print_r($val,true))."</span>
    </div>";
}

$api_key   = sc($conn,'paymob_api_key','');
$card_id   = sc($conn,'paymob_card_integration_id','');
$apple_id  = sc($conn,'paymob_applepay_integration_id','');
$iframe_id = sc($conn,'paymob_iframe_id','');
$pm_on     = sc($conn,'paymob_enabled','0');

$pp_cid    = sc($conn,'paypal_client_id','');
$pp_sec    = sc($conn,'paypal_secret','');
$pp_mode   = sc($conn,'paypal_mode','sandbox');
$pp_on     = sc($conn,'paypal_enabled','0');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>اختبار بوابات الدفع</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
</head>
<body style="background:#f1f5f9;padding:30px;font-family:Tahoma,Arial,sans-serif">
<div style="max-width:820px;margin:0 auto">

<h4 style="color:#0f172a;margin-bottom:20px">🔧 تشخيص بوابات الدفع</h4>

<!-- ══ Paymob ══ -->
<div class="card mb-4" style="border-top:4px solid #7c3aed">
<div class="card-header" style="background:#f5f3ff;font-weight:700;color:#5b21b6">Paymob</div>
<div class="card-body">
<?php
show('paymob_enabled', $pm_on, $pm_on === '1');
show('API Key', $api_key ? substr($api_key,0,12).'...' : '(فارغ)', !empty($api_key));
show('Card Integration ID', $card_id ?: '(فارغ)', !empty($card_id));
show('Apple Pay Integration ID', $apple_id ?: '(فارغ)');
show('Iframe ID', $iframe_id ?: '(فارغ)', !empty($iframe_id));

if (!$api_key) {
    echo "<div class='alert alert-danger mt-3'>❌ API Key فارغ — احفظ الإعدادات أولاً</div>";
} else {
    echo "<hr><strong>اختبار Auth Token:</strong><br>";
    $ch = curl_init('https://accept.paymob.com/api/auth/tokens');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['api_key' => $api_key]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    show('HTTP Status', $code, $code === 200);
    show('cURL Error', $err ?: 'لا يوجد', empty($err));
    show('Raw Response', $res ?: '(فارغ)');

    $data = json_decode($res, true);
    $token = $data['token'] ?? null;
    show('Token', $token ? substr($token,0,20).'...' : 'لم يُستلم', !empty($token));

    if ($token && $card_id && $iframe_id) {
        echo "<hr><strong>اختبار إنشاء أمر (100 قرش تجريبي):</strong><br>";
        $order = paymob_create_order($conn, $token, 100, 'TEST_'.time());
        show('Order ID', $order['id'] ?? 'لم يُنشأ', !empty($order['id']));
        if (!empty($order['id'])) {
            show('Order Response', json_encode($order, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        }
    }
}
?>
</div>
</div>

<!-- ══ PayPal ══ -->
<div class="card mb-4" style="border-top:4px solid #0070ba">
<div class="card-header" style="background:#f0f6ff;font-weight:700;color:#003087">PayPal</div>
<div class="card-body">
<?php
show('paypal_enabled', $pp_on, $pp_on === '1');
show('Mode', $pp_mode);
show('Client ID', $pp_cid ? substr($pp_cid,0,20).'...' : '(فارغ)', !empty($pp_cid));
show('Secret', $pp_sec ? '***' : '(فارغ)', !empty($pp_sec));

if (!$pp_cid || !$pp_sec) {
    echo "<div class='alert alert-danger mt-3'>❌ Client ID أو Secret فارغ</div>";
} else {
    echo "<hr><strong>اختبار OAuth Token:</strong><br>";
    $base = $pp_mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    $ch = curl_init($base.'/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => "$pp_cid:$pp_sec",
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    show('HTTP Status', $code, $code === 200);
    show('cURL Error', $err ?: 'لا يوجد', empty($err));
    $data  = json_decode($res, true);
    $token = $data['access_token'] ?? null;
    show('Access Token', $token ? substr($token,0,20).'...' : 'لم يُستلم', !empty($token));
    if (!$token) show('Response', $res);
}
?>
</div>
</div>

<a href="payment_gateways.php" class="btn btn-outline-secondary btn-sm">← العودة للإعدادات</a>
</div>
</body>
</html>
