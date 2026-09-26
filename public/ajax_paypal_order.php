<?php
/**
 * ajax_paypal_order.php
 * إنشاء أمر PayPal وإعادة order_id للـ JS
 */
header('Content-Type: application/json');
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/payment_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error'=>'method']); exit; }

$amount = max(1, (float)($_POST['amount'] ?? 0));
$ref    = preg_replace('/[^a-z0-9_-]/i', '', $_POST['ref'] ?? '');

$result = paypal_create_order($conn, $amount, 'SAR', $ref);

if (!empty($result['id'])) {
    echo json_encode(['id' => $result['id']]);
} else {
    echo json_encode(['error' => $result['message'] ?? 'PayPal error']);
}
