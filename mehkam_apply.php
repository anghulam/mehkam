<?php
/**
 * mehkam_apply.php  —  ترقية تلقائية لقاعدة البيانات
 * ─────────────────────────────────────────────────────────
 *  ارفعه في المجلد الرئيسي للموقع (نفس مكان index.php وlogin.php)،
 *  سجّل دخولك كـ "أدمن" في المنصة، ثم افتح:
 *      https://mehkam.net/mehkam_apply.php
 *  بيطبّق كل التعديلات المطلوبة ويعطيك تقريراً.
 *  ⚠️ احذف هذا الملف من السيرفر بعد ما يخلص.
 * ─────────────────────────────────────────────────────────
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
   . '<title>ترقية مِحكام</title>'
   . '<style>body{font-family:Tahoma,Arial;background:#0a1628;color:#e2e8f0;max-width:760px;margin:40px auto;padding:24px;line-height:1.9}'
   . 'h1{color:#c9a227}code{background:#1e293b;padding:2px 6px;border-radius:4px;font-size:13px}'
   . '.ok{color:#4ade80}.skip{color:#94a3b8}.err{color:#f87171}.box{background:#0f2040;border:1px solid #1e3a5f;border-radius:10px;padding:16px 20px;margin:14px 0}</style>'
   . '</head><body><h1>ترقية قاعدة بيانات مِحكام</h1>';

// ── حماية: أدمن فقط ──
if (($_SESSION['role'] ?? '') !== 'admin') {
    echo '<div class="box err">غير مصرّح. سجّل دخولك كمدير في المنصة أولاً، ثم افتح هذا الرابط مجدداً.</div>';
    echo '<p><a href="login.php" style="color:#c9a227">تسجيل الدخول</a></p></body></html>';
    exit;
}

$steps = [
    'أعمدة الدفع في جدول المكاتب' => [
        "ALTER TABLE offices ADD COLUMN billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly'",
        "ALTER TABLE offices ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'bank'",
        "ALTER TABLE offices ADD COLUMN payment_ref VARCHAR(500) DEFAULT NULL",
    ],
    'أعمدة المستخدمين (بريد + تقويم + تيليغرام)' => [
        "ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN calendar_pref ENUM('gregorian','hijri','both') NOT NULL DEFAULT 'gregorian'",
        "ALTER TABLE users ADD COLUMN telegram_chat_id VARCHAR(40) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN notify_telegram TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE users ADD COLUMN tg_link_token VARCHAR(40) DEFAULT NULL",
    ],
    'جدول سجل التنبيهات' => [
        "CREATE TABLE IF NOT EXISTS notification_log (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            kind VARCHAR(30) NOT NULL, ref VARCHAR(80) NOT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_notif (user_id, kind, ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'الصلاحيات ونطاق المستخدم + إسناد القضايا + قوالب الأدوار' => [
        "ALTER TABLE users ADD COLUMN permissions TEXT DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN restricted_scope TINYINT(1) NOT NULL DEFAULT 0",
        "CREATE TABLE IF NOT EXISTS case_assignments (
            case_id INT NOT NULL, user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (case_id, user_id), INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS office_role_perms (
            office_id INT NOT NULL, role VARCHAR(30) NOT NULL,
            permissions TEXT DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (office_id, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'مكتبة السوابق القضائية' => [
        "CREATE TABLE IF NOT EXISTS precedents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT NOT NULL,
            title VARCHAR(500) NOT NULL,
            court VARCHAR(200) DEFAULT NULL,
            subject VARCHAR(200) DEFAULT NULL,
            case_type VARCHAR(100) DEFAULT NULL,
            law_system VARCHAR(200) DEFAULT NULL,
            article VARCHAR(200) DEFAULT NULL,
            ruling_number VARCHAR(100) DEFAULT NULL,
            ruling_date DATE DEFAULT NULL,
            summary TEXT DEFAULT NULL,
            full_text LONGTEXT DEFAULT NULL,
            keywords VARCHAR(500) DEFAULT NULL,
            lawyer_notes TEXT DEFAULT NULL,
            file_name VARCHAR(255) DEFAULT NULL,
            file_path VARCHAR(500) DEFAULT NULL,
            file_driver VARCHAR(20) DEFAULT 'local',
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_office (office_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS case_precedents (
            case_id INT NOT NULL, precedent_id INT NOT NULL,
            PRIMARY KEY (case_id, precedent_id), INDEX idx_prec (precedent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'إسناد المهام لمستخدم + سجل الإجراءات' => [
        "ALTER TABLE tasks ADD COLUMN assigned_to_id INT DEFAULT NULL",
        "ALTER TABLE tasks ADD COLUMN completed_at DATETIME DEFAULT NULL",
        "CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT NOT NULL,
            user_id   INT DEFAULT NULL,
            user_name VARCHAR(200) DEFAULT NULL,
            action    VARCHAR(30) NOT NULL,
            entity    VARCHAR(30) NOT NULL,
            entity_id INT DEFAULT NULL,
            summary   VARCHAR(400) DEFAULT NULL,
            ip        VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_office_time (office_id, created_at),
            INDEX idx_entity (entity, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'ملاحظات الصادر والوارد' => [
        "ALTER TABLE correspondence ADD COLUMN notes TEXT DEFAULT NULL",
    ],
    'اتجاه الفاتورة + ربط القيود' => [
        "ALTER TABLE invoices ADD COLUMN direction ENUM('income','expense') NOT NULL DEFAULT 'income'",
        "ALTER TABLE transactions ADD COLUMN invoice_id INT DEFAULT NULL",
        "ALTER TABLE transactions ADD COLUMN source ENUM('manual','invoice') NOT NULL DEFAULT 'manual'",
        "ALTER TABLE transactions ADD COLUMN case_id INT DEFAULT NULL",
    ],
    'أعمدة ZATCA للفواتير' => [
        "ALTER TABLE invoices ADD COLUMN uuid VARCHAR(36) DEFAULT NULL",
        "ALTER TABLE invoices ADD COLUMN invoice_type ENUM('simplified','standard') NOT NULL DEFAULT 'simplified'",
        "ALTER TABLE invoices ADD COLUMN issue_date DATE DEFAULT NULL",
        "ALTER TABLE invoices ADD COLUMN supply_date DATE DEFAULT NULL",
        "ALTER TABLE invoices ADD COLUMN buyer_vat VARCHAR(20) DEFAULT NULL",
        "ALTER TABLE invoices ADD COLUMN seller_vat VARCHAR(20) DEFAULT NULL",
        "ALTER TABLE invoices ADD COLUMN qr_data TEXT DEFAULT NULL",
        "ALTER TABLE invoices ADD COLUMN zatca_status ENUM('draft','valid') NOT NULL DEFAULT 'draft'",
    ],
    'أعمدة ZATCA للعملاء وإعدادات المكتب' => [
        "ALTER TABLE clients ADD COLUMN vat_number VARCHAR(20) DEFAULT NULL",
        "ALTER TABLE clients ADD COLUMN cr_number VARCHAR(20) DEFAULT NULL",
        "ALTER TABLE office_settings ADD COLUMN cr_number VARCHAR(20) DEFAULT NULL",
        "ALTER TABLE office_settings ADD COLUMN office_logo VARCHAR(500) DEFAULT NULL",
    ],
    'طلبات الباقة المخصصة' => [
        "ALTER TABLE package_requests ADD COLUMN payment_proof VARCHAR(500) DEFAULT NULL",
        "ALTER TABLE package_requests ADD COLUMN is_custom TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE package_requests ADD COLUMN custom_features JSON DEFAULT NULL",
        "ALTER TABLE package_requests ADD COLUMN custom_limits JSON DEFAULT NULL",
        "ALTER TABLE package_requests ADD COLUMN custom_price_monthly DECIMAL(10,2) NOT NULL DEFAULT 0",
        "ALTER TABLE package_requests ADD COLUMN admin_final_price DECIMAL(10,2) DEFAULT NULL",
    ],
    'تسعير الميزات' => [
        "ALTER TABLE feature_prices ADD COLUMN discount_pct DECIMAL(5,2) DEFAULT 0",
        "ALTER TABLE feature_prices ADD COLUMN is_active TINYINT DEFAULT 1",
    ],
    'تخزين/تشفير + ربط Google Drive لكل مكتب' => [
        "ALTER TABLE office_settings ADD COLUMN storage_driver ENUM('server','gdrive') NOT NULL DEFAULT 'server'",
        "ALTER TABLE office_settings ADD COLUMN encrypt_files TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE office_settings ADD COLUMN storage_salt VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE office_settings ADD COLUMN gdrive_refresh_token TEXT",
        "ALTER TABLE office_settings ADD COLUMN gdrive_access_token TEXT",
        "ALTER TABLE office_settings ADD COLUMN gdrive_token_expiry BIGINT NOT NULL DEFAULT 0",
        "ALTER TABLE office_settings ADD COLUMN gdrive_root_folder_id VARCHAR(120) DEFAULT NULL",
        "ALTER TABLE office_settings ADD COLUMN gdrive_email VARCHAR(200) DEFAULT NULL",
    ],
    'جدول تأكيد البريد' => [
        "CREATE TABLE IF NOT EXISTS email_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            token VARCHAR(128) NOT NULL UNIQUE, expires_at DATETIME NOT NULL,
            used TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (token), INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'جدول سجل مدفوعات البوابات' => [
        "CREATE TABLE IF NOT EXISTS payment_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY, gateway VARCHAR(20), txn_id VARCHAR(100),
            order_ref VARCHAR(100), amount_cents INT, success TINYINT(1),
            payload JSON, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'العملاء — الرقم الموحّد والعنوان الوطني' => [
        "ALTER TABLE clients ADD COLUMN unified_number VARCHAR(20) DEFAULT NULL",
        "ALTER TABLE clients ADD COLUMN na_building VARCHAR(10) DEFAULT NULL",
        "ALTER TABLE clients ADD COLUMN na_street VARCHAR(120) DEFAULT NULL",
        "ALTER TABLE clients ADD COLUMN na_district VARCHAR(120) DEFAULT NULL",
        "ALTER TABLE clients ADD COLUMN na_city VARCHAR(80) DEFAULT NULL",
        "ALTER TABLE clients ADD COLUMN na_postal VARCHAR(10) DEFAULT NULL",
        "ALTER TABLE clients ADD COLUMN na_additional VARCHAR(10) DEFAULT NULL",
        "ALTER TABLE clients ADD COLUMN na_short VARCHAR(12) DEFAULT NULL",
    ],
    'العقود — التمهيد والبنود والقوالب والربط' => [
        "ALTER TABLE contracts ADD COLUMN preamble TEXT DEFAULT NULL",
        "ALTER TABLE contracts ADD COLUMN clauses MEDIUMTEXT DEFAULT NULL",
        "ALTER TABLE contracts ADD COLUMN template_key VARCHAR(60) DEFAULT NULL",
        "ALTER TABLE contracts ADD COLUMN linked_ids VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE contracts ADD COLUMN party_first TEXT DEFAULT NULL",
        "ALTER TABLE contracts ADD COLUMN party_second TEXT DEFAULT NULL",
    ],
    'الفواتير — نوع الخصم والرمز العام' => [
        "ALTER TABLE invoices ADD COLUMN discount_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed'",
        "ALTER TABLE invoices ADD COLUMN discount_value DECIMAL(12,2) NOT NULL DEFAULT 0",
        "ALTER TABLE invoices ADD COLUMN paid_date DATE DEFAULT NULL",
        "ALTER TABLE invoices ADD COLUMN public_token VARCHAR(40) DEFAULT NULL",
    ],
    'المكتبة القانونية — حجم الملف' => [
        "ALTER TABLE library ADD COLUMN file_size INT DEFAULT 0",
    ],
    'الليتر هيد للطباعة' => [
        "ALTER TABLE office_settings ADD COLUMN letterhead_path VARCHAR(500) DEFAULT NULL",
        "ALTER TABLE office_settings ADD COLUMN letterhead_enabled TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE office_settings ADD COLUMN letterhead_top INT NOT NULL DEFAULT 42",
        "ALTER TABLE office_settings ADD COLUMN letterhead_bottom INT NOT NULL DEFAULT 26",
        "ALTER TABLE office_settings ADD COLUMN letterhead_side INT NOT NULL DEFAULT 18",
    ],
    'قوالب عقود المكتب' => [
        "CREATE TABLE IF NOT EXISTS office_contract_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            contract_type VARCHAR(100) DEFAULT NULL,
            preamble TEXT DEFAULT NULL,
            clauses MEDIUMTEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_office (office_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'تنبيهات واتساب + بريد' => [
        "ALTER TABLE users ADD COLUMN whatsapp_number VARCHAR(25) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN notify_whatsapp TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE users ADD COLUMN wa_callmebot_key VARCHAR(40) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 1",
    ],
    'دور المتدرّب' => [
        "ALTER TABLE users MODIFY COLUMN role ENUM('admin','office_owner','lawyer','secretary','trainee') DEFAULT 'lawyer'",
    ],
    'الخدمات الرقمية للمكتب' => [
        "CREATE TABLE IF NOT EXISTS office_services (
            id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL,
            name VARCHAR(300) NOT NULL, description TEXT DEFAULT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0, vat_rate DECIMAL(5,2) NOT NULL DEFAULT 15,
            is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_office (office_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS service_requests (
            id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL,
            service_id INT DEFAULT NULL, service_name VARCHAR(300) NOT NULL,
            client_id INT DEFAULT NULL, case_id INT DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0, vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM('new','in_progress','completed','cancelled') NOT NULL DEFAULT 'new',
            notes TEXT DEFAULT NULL, invoice_id INT DEFAULT NULL,
            created_by INT DEFAULT NULL, created_by_name VARCHAR(200) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_office_date (office_id, created_at), INDEX idx_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
];

$done = 0; $skipped = 0; $failed = 0;
foreach ($steps as $label => $queries) {
    echo '<div class="box"><strong>' . htmlspecialchars($label) . '</strong><br>';
    foreach ($queries as $q) {
        $short = htmlspecialchars(substr(preg_replace('/\s+/', ' ', $q), 0, 90));
        try {
            $ok = $conn->query($q);
            if ($ok) { echo '<span class="ok">✔</span> ' . $short . "<br>"; $done++; }
            else     { echo '<span class="err">✖</span> ' . $short . ' — ' . htmlspecialchars($conn->error) . "<br>"; $failed++; }
        } catch (\Throwable $e) {
            $m = $e->getMessage();
            if (stripos($m, 'Duplicate column') !== false || stripos($m, 'exists') !== false) {
                echo '<span class="skip">•</span> ' . $short . ' <span class="skip">(مطبَّق مسبقاً)</span><br>'; $skipped++;
            } else {
                echo '<span class="err">✖</span> ' . $short . ' — ' . htmlspecialchars($m) . "<br>"; $failed++;
            }
        }
    }
    echo '</div>';
}

echo '<div class="box"><h2 class="ok">انتهت الترقية</h2>'
   . "<p>جديد: <strong>$done</strong> &nbsp;·&nbsp; موجود مسبقاً: <strong>$skipped</strong> &nbsp;·&nbsp; فشل: <strong>$failed</strong></p>";
if ($failed === 0) {
    echo '<p class="ok">كل شيء تمام. الآن <strong>احذف ملف <code>mehkam_apply.php</code></strong> من السيرفر.</p>';
} else {
    echo '<p class="err">فيه أخطاء أعلاه — انسخها وأرسلها للمطوّر.</p>';
}
echo '<p><a href="admin/settings.php" style="color:#c9a227">← إعدادات المنصة</a></p></div></body></html>';
