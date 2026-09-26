<?php
/**
 * payment_helper.php
 * دوال مساعدة لبوابتَي PayPal وPaymob
 */

/* ══════════════════════════════════════════
   PayPal — REST Orders API v2
══════════════════════════════════════════ */

function paypal_base_url($conn) {
    return sc($conn,'paypal_mode','sandbox') === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

function paypal_get_token($conn) {
    $cid = sc($conn,'paypal_client_id','');
    $sec = sc($conn,'paypal_secret','');
    if (!$cid || !$sec) return null;

    $ch = curl_init(paypal_base_url($conn).'/v1/oauth2/token');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => "$cid:$sec",
        CURLOPT_HTTPHEADER     => ['Accept: application/json','Accept-Language: en_US'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $res  = curl_exec($ch); curl_close($ch);
    $data = json_decode($res, true);
    return $data['access_token'] ?? null;
}

function paypal_create_order($conn, float $amount, string $currency = 'SAR', string $ref = '') {
    $token = paypal_get_token($conn);
    if (!$token) return ['error'=>'لا يمكن الاتصال بـ PayPal'];

    $body = json_encode([
        'intent'        => 'CAPTURE',
        'purchase_units'=> [[
            'amount'        => ['currency_code'=>$currency,'value'=>number_format($amount,2,'.','')],
            'custom_id'     => $ref,
            'description'   => 'مِحكام — اشتراك',
        ]],
    ]);
    $ch = curl_init(paypal_base_url($conn).'/v2/checkout/orders');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer $token",
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $res  = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?? ['error'=>'PayPal error'];
}

function paypal_capture_order($conn, string $order_id) {
    $token = paypal_get_token($conn);
    if (!$token) return ['error'=>'لا يمكن الاتصال بـ PayPal'];

    $ch = curl_init(paypal_base_url($conn)."/v2/checkout/orders/$order_id/capture");
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer $token",
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $res  = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?? ['error'=>'PayPal capture error'];
}

/* ══════════════════════════════════════════
   Paymob — Accept Payment
══════════════════════════════════════════ */

function paymob_base_url($conn) {
    return rtrim(sc($conn,'paymob_api_base_url','https://accept.paymob.com'), '/');
}

function paymob_currency($conn) {
    return sc($conn,'paymob_currency','SAR');
}

function paymob_get_token($conn) {
    $api_key = sc($conn,'paymob_api_key','');
    if (!$api_key) return null;

    $ch = curl_init(paymob_base_url($conn).'/api/auth/tokens');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => json_encode(['api_key'=>$api_key]),
        CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT         => 15,
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_SSL_VERIFYHOST  => 0,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) { error_log("Paymob auth cURL error: $err"); return null; }
    $data = json_decode($res, true);
    if (!isset($data['token'])) { error_log("Paymob auth response: $res"); }
    return $data['token'] ?? null;
}

function paymob_create_order($conn, string $token, int $amount_cents, string $ref = '') {
    $body = json_encode([
        'auth_token'        => $token,
        'delivery_needed'   => false,
        'amount_cents'      => $amount_cents,
        'currency'          => paymob_currency($conn),
        'merchant_order_id' => $ref ?: uniqid('MHK'),
        'items'             => [],
    ]);
    $ch = curl_init(paymob_base_url($conn).'/api/ecommerce/orders');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $body,
        CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT         => 15,
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_SSL_VERIFYHOST  => 0,
    ]);
    $res  = curl_exec($ch); curl_close($ch);
    return json_decode($res, true) ?? [];
}

function paymob_get_payment_key($conn, string $token, int $order_id, int $amount_cents, array $billing, string $integration_id) {
    $body = json_encode([
        'auth_token'     => $token,
        'amount_cents'   => $amount_cents,
        'expiration'     => 3600,
        'order_id'       => $order_id,
        'billing_data'   => $billing,
        'currency'       => paymob_currency($conn),
        'integration_id' => (int)$integration_id,
        'lock_order_when_paid' => false,
    ]);
    $ch = curl_init(paymob_base_url($conn).'/api/acceptance/payment_keys');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $body,
        CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT         => 15,
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_SSL_VERIFYHOST  => 0,
    ]);
    $res  = curl_exec($ch); curl_close($ch);
    $data = json_decode($res, true);
    return $data['token'] ?? null;
}

function paymob_verify_hmac($conn, array $data): bool {
    $secret = sc($conn,'paymob_hmac_secret','');
    if (!$secret) return false;

    $keys = ['amount_cents','created_at','currency','error_occured','has_parent_transaction',
             'id','integration_id','is_3d_secure','is_auth','is_capture','is_refunded',
             'is_standalone_payment','is_voided','order','owner','pending',
             'source_data_pan','source_data_sub_type','source_data_type','success'];
    $str = '';
    foreach ($keys as $k) $str .= ($data[$k] ?? '');
    return hash_equals(hash_hmac('sha512',$str,$secret), $data['hmac'] ?? '');
}
