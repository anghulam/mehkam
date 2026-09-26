<?php
/**
 * ajax_paypal_capture.php
 * التقاط دفعة PayPal بعد موافقة المستخدم
 */
header('Content-Type: application/json');
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/payment_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error'=>'method']); exit; }

$order_id = preg_replace('/[^a-z0-9]/i', '', $_POST['order_id'] ?? '');
if (!$order_id) { echo json_encode(['error'=>'missing order_id']); exit; }

$result = paypal_capture_order($conn, $order_id);

if (($result['status'] ?? '') === 'COMPLETED') {
    echo json_encode(['ok'=>true, 'order_id'=>$order_id]);
} else {
    echo json_encode(['error' => $result['message'] ?? 'capture failed', 'detail'=>$result]);
}
