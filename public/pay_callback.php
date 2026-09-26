<?php
/**
 * pay_callback.php
 * استقبال نتائج Paymob بعد الدفع (Redirect + Webhook)
 */
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/payment_helper.php';

// ── Paymob webhook (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    $obj = $payload['obj'] ?? [];
    if (!paymob_verify_hmac($conn, $obj)) {
        http_response_code(403); echo 'HMAC_FAIL'; exit;
    }
    // سجّل المعاملة
    $success    = ($obj['success'] ?? false) ? 1 : 0;
    $txn_id     = (int)($obj['id'] ?? 0);
    $order_ref  = $conn->real_escape_string($obj['order']['merchant_order_id'] ?? '');
    $amount_c   = (int)($obj['amount_cents'] ?? 0);
    $conn->query("CREATE TABLE IF NOT EXISTS payment_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY, gateway VARCHAR(20), txn_id VARCHAR(100),
        order_ref VARCHAR(100), amount_cents INT, success TINYINT(1),
        payload JSON, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pl = $conn->real_escape_string(json_encode($obj));
    $conn->query("INSERT INTO payment_transactions (gateway,txn_id,order_ref,amount_cents,success,payload)
        VALUES ('paymob','$txn_id','$order_ref',$amount_c,$success,'$pl')");
    http_response_code(200); echo 'OK'; exit;
}

// ── Paymob redirect (GET) ──
$data    = $_GET;
$success = ($data['success'] ?? 'false') === 'true';
$txn_id  = $data['id'] ?? '';
$ref     = $data['merchant_order_id'] ?? '';

// تحديد صفحة الرجوع
$return = 'home.php';
if (isset($_SESSION['pay_return'])) {
    $return = $_SESSION['pay_return'];
    unset($_SESSION['pay_return']);
}

// إذا كانت الصفحة مُحمَّلة داخل iframe يُرسَل postMessage للأب ثم يتم التحويل
$txn_json  = json_encode(['type'=>'PAYMOB_CALLBACK','success'=>$success,'txn_id'=>$txn_id,'ref'=>$ref]);
$return_ok = htmlspecialchars($return.'?pay_ok=1&ref='.urlencode($txn_id), ENT_QUOTES);
$return_fail = htmlspecialchars($return.'?pay_fail=1', ENT_QUOTES);
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body>
<script>
var msg = <?= $txn_json ?>;
if (window.parent && window.parent !== window) {
  // داخل iframe — أرسل النتيجة للصفحة الأب
  window.parent.postMessage(msg, '*');
} else {
  // وصول مباشر — وجّه المتصفح
  if (msg.success) {
    window.location.href = '<?= $return_ok ?>';
  } else {
    window.location.href = '<?= $return_fail ?>';
  }
}
</script>
</body>
</html>
