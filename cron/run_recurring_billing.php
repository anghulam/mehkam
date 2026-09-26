<?php
/**
 * cron/run_recurring_billing.php
 * ينشئ فواتير الاشتراكات المتكررة المستحقة تلقائياً لكل المكاتب.
 *
 *  جدولة مقترحة (cPanel → Cron Jobs) يومياً الساعة 06:00:
 *      0 6 * * *   curl -s "https://mehkam.app/cron/run_recurring_billing.php?key=CRON_SECRET"
 *  (CRON_SECRET من: لوحة الإدارة ← إعدادات المنصة)
 *
 *  للاختبار الفوري: curl عادي (بدون &force) — يعمل بأي وقت، الحماية الوحيدة هي مفتاح CRON.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/content_helper.php';
require_once __DIR__ . '/../includes/zatca_helper.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $secret = sc($conn, 'cron_secret', '');
    if ($secret === '' || ($_GET['key'] ?? '') !== $secret) {
        http_response_code(403); echo 'forbidden'; exit;
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS recurring_billing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    client_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    billing_cycle ENUM('monthly','quarterly','yearly') DEFAULT 'monthly',
    next_run_date DATE NOT NULL,
    last_run_date DATE DEFAULT NULL,
    is_active TINYINT DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function rb_next_date($date, $cycle) {
    $add = ['monthly'=>'+1 month','quarterly'=>'+3 months','yearly'=>'+1 year'][$cycle] ?? '+1 month';
    return date('Y-m-d', strtotime($date . ' ' . $add));
}

function rb_make_invoice($conn, $oid, array $plan) {
    $setg = $conn->query("SELECT tax_number, invoice_prefix FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?: [];
    $office = $conn->query("SELECT name FROM offices WHERE id=$oid")->fetch_assoc();
    $prefix = $setg['invoice_prefix'] ?? 'INV';
    $invNum = next_invoice_num($conn, $oid, $prefix);
    $ptok = bin2hex(random_bytes(16));
    $uuid = $conn->real_escape_string(function_exists('zatca_uuid') ? zatca_uuid() : bin2hex(random_bytes(8)));

    $amount = round((float)$plan['amount'] / 1.15, 2);
    $vat = round((float)$plan['amount'] - $amount, 2);
    $total = round((float)$plan['amount'], 2);
    $cid = (int)$plan['client_id'];
    $tdy = date('Y-m-d');
    $titleEsc = $conn->real_escape_string($plan['title']);
    $itemsEsc = $conn->real_escape_string(json_encode([['description'=>$plan['title'],'qty'=>1,'price'=>$amount]], JSON_UNESCAPED_UNICODE));

    $qr = '';
    if (!empty($setg['tax_number']) && function_exists('zatca_qr')) {
        try { $qr = $conn->real_escape_string(zatca_qr($office['name'] ?? '', $setg['tax_number'], $total, $vat, $tdy.'T00:00:00Z')); } catch (\Throwable $e) { $qr = ''; }
    }
    $seller = $conn->real_escape_string($setg['tax_number'] ?? '');
    $invId = 0;
    try {
        $conn->query("INSERT INTO invoices
            (office_id,client_id,invoice_number,title,items,subtotal,discount,discount_type,discount_value,tax_rate,tax_amount,total,status,due_date,notes,direction,
             uuid,public_token,invoice_type,issue_date,supply_date,buyer_vat,seller_vat,qr_data,zatca_status)
            VALUES ($oid,$cid,'$invNum','$titleEsc','$itemsEsc',$amount,0,'fixed',0,15,$vat,$total,'sent',NULL,'اشتراك متكرر تلقائي','income',
                    '$uuid','$ptok','simplified','$tdy','$tdy','','$seller','$qr','" . ($qr ? 'valid' : 'draft') . "')");
        $invId = (int)$conn->insert_id;
    } catch (\Throwable $e1) {
        try {
            $conn->query("INSERT INTO invoices (office_id,client_id,invoice_number,title,items,subtotal,tax_rate,tax_amount,total,status,direction,issue_date,public_token)
                VALUES ($oid,$cid,'$invNum','$titleEsc','$itemsEsc',$amount,15,$vat,$total,'sent','income','$tdy','$ptok')");
            $invId = (int)$conn->insert_id;
        } catch (\Throwable $e2) {}
    }
    return $invId;
}

$due = $conn->query("SELECT * FROM recurring_billing WHERE is_active=1 AND next_run_date <= CURDATE()");
$processed = 0; $failed = 0;
if ($due) {
    while ($plan = $due->fetch_assoc()) {
        $chk = $conn->query("SELECT id FROM clients WHERE id=".(int)$plan['client_id']." AND office_id=".(int)$plan['office_id'])->num_rows;
        if (!$chk) continue;
        $invId = rb_make_invoice($conn, (int)$plan['office_id'], $plan);
        if ($invId) {
            $nextDate = rb_next_date(date('Y-m-d'), $plan['billing_cycle']);
            // نُقدّم next_run_date فوراً قبل أي معالجة أخرى — يمنع تكرار الفاتورة لو تشغّل الكرون مرتين بنفس اليوم
            $conn->query("UPDATE recurring_billing SET last_run_date=CURDATE(), next_run_date='$nextDate' WHERE id=".(int)$plan['id']);
            $processed++;
        } else {
            $failed++;
        }
    }
}

echo "processed=$processed failed=$failed\n";
