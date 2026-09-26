<?php
/**
 * includes/affiliate_helper.php — نظام الأفلييت (روابط إحالة، عمولات، محفظة، سحوبات)
 * القرارات المعتمدة:
 *  - التتبّع: رابط/كود فريد ?ref=CODE + كوكي 30 يوماً.
 *  - العمولة: نسبة % من أول دفعة فقط (تُحتسب مرة واحدة لكل مكتب).
 *  - حسابات الأفلييت: يضيفها الأدمن يدوياً فقط (بدون تسجيل ذاتي).
 *  - السحب: طلب من الأفلييت يراجعه الأدمن يدوياً (نفس أسلوب اعتماد طلبات الباقات).
 */

function affiliate_migrate($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    $stmts = [
        "CREATE TABLE IF NOT EXISTS affiliates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            username VARCHAR(100) NOT NULL UNIQUE,
            email VARCHAR(200) NOT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            password VARCHAR(255) NOT NULL,
            ref_code VARCHAR(30) NOT NULL UNIQUE,
            commission_pct DECIMAL(5,2) NOT NULL DEFAULT 20.00,
            bank_name VARCHAR(150) DEFAULT NULL,
            bank_iban VARCHAR(100) DEFAULT NULL,
            wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_earned DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS affiliate_clicks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            affiliate_id INT NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_aff (affiliate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS affiliate_referrals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            affiliate_id INT NOT NULL,
            office_id INT NOT NULL,
            office_name VARCHAR(300) DEFAULT NULL,
            status ENUM('pending','converted') NOT NULL DEFAULT 'pending',
            payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            converted_at DATETIME DEFAULT NULL,
            UNIQUE KEY uq_office (office_id),
            INDEX idx_aff (affiliate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS affiliate_wallet_tx (
            id INT AUTO_INCREMENT PRIMARY KEY,
            affiliate_id INT NOT NULL,
            type ENUM('commission','withdrawal','adjustment') NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            note VARCHAR(255) DEFAULT NULL,
            balance_after DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_aff (affiliate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS affiliate_withdrawals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            affiliate_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            bank_name VARCHAR(150) DEFAULT NULL,
            bank_iban VARCHAR(100) DEFAULT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            admin_note VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            INDEX idx_aff (affiliate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($stmts as $s) { try { $conn->query($s); } catch (\Throwable $e) {} }
    // توافق مع جدول موجود مسبقاً بدون عمود اسم المستخدم
    try { $conn->query("ALTER TABLE affiliates ADD COLUMN username VARCHAR(100) DEFAULT NULL UNIQUE AFTER full_name"); } catch (\Throwable $e) {}
}

/** كود إحالة فريد قصير وسهل المشاركة */
function affiliate_gen_ref_code($conn) {
    do {
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $exists = $conn->query("SELECT id FROM affiliates WHERE ref_code='" . $conn->real_escape_string($code) . "' LIMIT 1");
    } while ($exists && $exists->num_rows);
    return $code;
}

/** يلتقط ?ref=CODE من الروابط العامة ويحفظه بكوكي 30 يوماً + يسجّل نقرة */
function affiliate_capture_click($conn) {
    if (empty($_GET['ref'])) return;
    affiliate_migrate($conn);
    $code = $conn->real_escape_string(trim($_GET['ref']));
    if ($code === '') return;
    $r = $conn->query("SELECT id FROM affiliates WHERE ref_code='$code' AND is_active=1 LIMIT 1");
    if (!$r || !$r->num_rows) return;
    $aff = $r->fetch_assoc();
    setcookie('mk_aff_ref', $code, time() + 30 * 86400, '/');
    $_COOKIE['mk_aff_ref'] = $code;
    $ip = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
    $conn->query("INSERT INTO affiliate_clicks (affiliate_id, ip_address) VALUES ({$aff['id']}, '$ip')");
}

/** يتحقق من صحة كود أفلييت (نشط وموجود) — تُستخدم عند إلزام إدخاله يدوياً بنموذج التسجيل */
function affiliate_code_valid($conn, $code) {
    affiliate_migrate($conn);
    $code = $conn->real_escape_string(trim($code));
    if ($code === '') return null;
    $r = $conn->query("SELECT * FROM affiliates WHERE ref_code='$code' AND is_active=1 LIMIT 1");
    return ($r && $r->num_rows) ? $r->fetch_assoc() : null;
}

/**
 * يُستدعى عند إنشاء مكتب جديد — يربطه بالأفلييت (بانتظار أول دفعة).
 * $explicit_code: كود أُدخل صراحةً بنموذج التسجيل — له الأولوية على كوكي الرابط إن وُجد.
 */
function affiliate_track_signup($conn, $office_id, $office_name, $explicit_code = null) {
    affiliate_migrate($conn);
    $codeRaw = $explicit_code !== null ? $explicit_code : ($_COOKIE['mk_aff_ref'] ?? '');
    if (empty($codeRaw)) return;
    $code = $conn->real_escape_string(trim($codeRaw));
    $r = $conn->query("SELECT id FROM affiliates WHERE ref_code='$code' AND is_active=1 LIMIT 1");
    if (!$r || !$r->num_rows) return;
    $aff = $r->fetch_assoc();
    $oid = (int)$office_id;
    $name = $conn->real_escape_string($office_name);
    $conn->query("INSERT IGNORE INTO affiliate_referrals (affiliate_id, office_id, office_name)
        VALUES ({$aff['id']}, $oid, '$name')");
}

/**
 * يُستدعى عند تسجيل أول دفعة فعلية لمكتب (اعتماد الأدمن لطلب/تفعيل مدفوع) —
 * يحوّل الإحالة المعلّقة (إن وُجدت) إلى عمولة مُضافة لمحفظة الأفلييت. آمن للاستدعاء
 * أكثر من مرة لنفس المكتب (لا يُحتسب إلا أول دفعة بفضل status='pending' + UNIQUE office_id).
 */
function affiliate_credit_conversion($conn, $office_id, $payment_amount) {
    affiliate_migrate($conn);
    $oid = (int)$office_id;
    $amount = (float)$payment_amount;
    if ($amount <= 0) return;
    $r = $conn->query("SELECT * FROM affiliate_referrals WHERE office_id=$oid AND status='pending' LIMIT 1");
    if (!$r || !$r->num_rows) return;
    $ref = $r->fetch_assoc();
    $aff = $conn->query("SELECT * FROM affiliates WHERE id={$ref['affiliate_id']} LIMIT 1")->fetch_assoc();
    if (!$aff) return;
    $commission = round($amount * ((float)$aff['commission_pct'] / 100), 2);
    $conn->query("UPDATE affiliate_referrals SET status='converted', payment_amount=$amount,
        commission_amount=$commission, converted_at=NOW() WHERE id={$ref['id']}");
    $newBal = (float)$aff['wallet_balance'] + $commission;
    $conn->query("UPDATE affiliates SET wallet_balance=$newBal, total_earned=total_earned+$commission WHERE id={$aff['id']}");
    $note = $conn->real_escape_string('عمولة إحالة مكتب: ' . ($ref['office_name'] ?: ('#' . $oid)));
    $conn->query("INSERT INTO affiliate_wallet_tx (affiliate_id, type, amount, note, balance_after)
        VALUES ({$aff['id']}, 'commission', $commission, '$note', $newBal)");
}
