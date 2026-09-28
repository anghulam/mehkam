<?php
/**
 * install.php — معالج تثبيت مِحكام
 * نظام إدارة مكاتب المحاماة
 */

session_start();

// ── حماية: إذا كان المثبِّت سبق تشغيله ──────────────────────
$lockFile = __DIR__ . '/install.lock';
if (file_exists($lockFile) && !isset($_GET['force'])) {
    die(renderDone('مثبَّت مسبقاً', 'النظام مثبَّت بالفعل. إذا أردت إعادة التثبيت احذف ملف <code>install.lock</code> أولاً.', false));
}

// ── الخطوة الحالية ────────────────────────────────────────────
$step = (int)($_POST['step'] ?? $_GET['step'] ?? 1);

// ── معالجة POST ──────────────────────────────────────────────
$errors = [];
$info   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // الخطوة 2 → التحقق من الاتصال وحفظ بيانات DB في الجلسة
    if ($step === 2) {
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbPort = trim($_POST['db_port'] ?? '3306');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = $_POST['db_pass'] ?? '';
        $dbName = trim($_POST['db_name'] ?? 'mehkam_db');

        if (!$dbUser) $errors[] = 'يرجى إدخال اسم مستخدم قاعدة البيانات';

        if (!$errors) {
            // اختبار الاتصال
            $testConn = @new mysqli($dbHost, $dbUser, $dbPass, '', (int)$dbPort ?: 3306);
            if ($testConn->connect_error) {
                $errors[] = 'فشل الاتصال بقاعدة البيانات: ' . $testConn->connect_error;
            } else {
                $_SESSION['install_db'] = compact('dbHost','dbPort','dbUser','dbPass','dbName');
                $testConn->close();
                $step = 3;
            }
        }
    }

    // الخطوة 3 → التحقق من بيانات الأدمن
    elseif ($step === 3) {
        $adminUser  = trim($_POST['admin_user']  ?? '');
        $adminPass  = $_POST['admin_pass']  ?? '';
        $adminPass2 = $_POST['admin_pass2'] ?? '';
        $adminName  = trim($_POST['admin_name']  ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $siteName   = trim($_POST['site_name']   ?? 'مِحكام');

        if (strlen($adminUser) < 3)  $errors[] = 'اسم المستخدم يجب أن يكون 3 أحرف على الأقل';
        if (strlen($adminPass) < 6)  $errors[] = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
        if ($adminPass !== $adminPass2) $errors[] = 'كلمتا المرور غير متطابقتان';
        if (!$adminName)             $errors[] = 'يرجى إدخال الاسم الكامل للمدير';

        if (!$errors) {
            $_SESSION['install_admin'] = compact('adminUser','adminPass','adminName','adminEmail','siteName');
            $step = 4; // → تنفيذ التثبيت
        }
    }

    // الخطوة 4 → تنفيذ التثبيت
    elseif ($step === 4) {
        $db    = $_SESSION['install_db']    ?? null;
        $admin = $_SESSION['install_admin'] ?? null;

        if (!$db || !$admin) {
            $errors[] = 'انتهت صلاحية الجلسة. يرجى البدء من جديد.';
            $step = 1;
        } else {
            $result = runInstallation($db, $admin);
            if ($result['success']) {
                // كتابة ملف القفل
                file_put_contents($lockFile, date('Y-m-d H:i:s') . "\nInstalled by: " . $admin['adminUser']);
                // مسح بيانات الجلسة
                unset($_SESSION['install_db'], $_SESSION['install_admin']);
                $step = 5;
                $info = $result;
            } else {
                $errors = $result['errors'];
                $step = 4;
            }
        }
    }
}

// ── دوال التثبيت ──────────────────────────────────────────────
function runInstallation($db, $admin) {
    $errors = [];

    // فتح الاتصال
    $conn = @new mysqli($db['dbHost'], $db['dbUser'], $db['dbPass'], '', (int)($db['dbPort']) ?: 3306);
    if ($conn->connect_error) {
        return ['success' => false, 'errors' => ['فشل الاتصال: ' . $conn->connect_error]];
    }

    // إنشاء قاعدة البيانات
    $dbName = $conn->real_escape_string($db['dbName']);
    if (!$conn->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        return ['success' => false, 'errors' => ['فشل إنشاء قاعدة البيانات: ' . $conn->error]];
    }
    $conn->select_db($dbName);
    $conn->set_charset('utf8mb4');

    // تشغيل جمل SQL
    $statements = getInstallSQL();
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($statements as $sql) {
        $sql = trim($sql);
        if (!$sql) continue;
        if (!$conn->query($sql)) {
            // تجاهل أخطاء "already exists" وأخطاء "Duplicate column"
            $code = $conn->errno;
            if (!in_array($code, [1050, 1060, 1061, 1062, 1091])) {
                $errors[] = "خطأ SQL (كود $code): " . $conn->error . "<br><small>" . htmlspecialchars(substr($sql,0,120)) . "...</small>";
            }
        }
    }
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    if ($errors) {
        return ['success' => false, 'errors' => $errors];
    }

    // إنشاء مستخدم الأدمن
    $hash = password_hash($admin['adminPass'], PASSWORD_BCRYPT);
    $uname = $conn->real_escape_string($admin['adminUser']);
    $uname_esc = $uname;
    $fname = $conn->real_escape_string($admin['adminName']);
    $email = $conn->real_escape_string($admin['adminEmail']);
    $hash  = $conn->real_escape_string($hash);

    // حذف أي أدمن قديم بنفس الاسم
    $conn->query("DELETE FROM users WHERE username='$uname_esc' AND role='admin'");
    $conn->query("INSERT INTO users (username,password,role,full_name,email,is_active) VALUES ('$uname_esc','$hash','admin','$fname','$email',1)");

    // تحديث اسم الموقع
    $sName = $conn->real_escape_string($admin['siteName']);
    $conn->query("INSERT INTO site_content (setting_key,setting_value) VALUES ('site_name','$sName') ON DUPLICATE KEY UPDATE setting_value='$sName'");

    $conn->close();

    // كتابة config/db.php
    $configPath = __DIR__ . '/config/db.php';
    $configContent = "<?php\n\$host = '{$db['dbHost']}';\n\$db   = '{$db['dbName']}';\n\$user = '{$db['dbUser']}';\n\$pass = '{$db['dbPass']}';\n\n\$conn = new mysqli(\$host, \$user, \$pass, \$db" . ($db['dbPort'] && $db['dbPort'] != '3306' ? ", {$db['dbPort']}" : '') . ");\nif (\$conn->connect_error) {\n    die('خطأ في الاتصال: ' . \$conn->connect_error);\n}\n\$conn->set_charset('utf8mb4');\n?>\n";

    file_put_contents($configPath, $configContent);

    return [
        'success'   => true,
        'dbName'    => $db['dbName'],
        'adminUser' => $admin['adminUser'],
        'adminPass' => $admin['adminPass'],
    ];
}

// ── كامل SQL التثبيت ─────────────────────────────────────────
function getInstallSQL() {
    return [
        /* ── جدول الباقات ── */
        "CREATE TABLE IF NOT EXISTS packages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            price_monthly DECIMAL(10,2),
            price_yearly DECIMAL(10,2),
            max_users INT DEFAULT 5,
            max_cases INT DEFAULT 100,
            features TEXT,
            is_active TINYINT(1) DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول المكاتب ── */
        "CREATE TABLE IF NOT EXISTS offices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200),
            license_number VARCHAR(100),
            owner_name VARCHAR(200),
            package_id INT,
            city VARCHAR(100),
            phone VARCHAR(50),
            email VARCHAR(200),
            subscription_start DATE,
            subscription_end DATE,
            status ENUM('active','expired','suspended','trial') DEFAULT 'trial',
            billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
            payment_method VARCHAR(30) NOT NULL DEFAULT 'bank',
            payment_ref VARCHAR(500) DEFAULT NULL,
            slug VARCHAR(100) UNIQUE DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول المستخدمين ── */
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','office_owner','lawyer','secretary','trainee') DEFAULT 'lawyer',
            office_id INT NULL,
            full_name VARCHAR(200),
            email VARCHAR(200),
            phone VARCHAR(50),
            is_active TINYINT(1) DEFAULT 1,
            email_verified TINYINT(1) NOT NULL DEFAULT 0,
            calendar_pref  ENUM('gregorian','hijri','both') NOT NULL DEFAULT 'gregorian',
            telegram_chat_id VARCHAR(40) DEFAULT NULL,
            notify_telegram  TINYINT(1) NOT NULL DEFAULT 1,
            tg_link_token    VARCHAR(40) DEFAULT NULL,
            whatsapp_number  VARCHAR(25) DEFAULT NULL,
            notify_whatsapp  TINYINT(1) NOT NULL DEFAULT 1,
            wa_callmebot_key VARCHAR(40) DEFAULT NULL,
            notify_email     TINYINT(1) NOT NULL DEFAULT 1,
            permissions      TEXT DEFAULT NULL,
            restricted_scope TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول العملاء ── */
        "CREATE TABLE IF NOT EXISTS clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT,
            full_name VARCHAR(200),
            id_number VARCHAR(50),
            phone VARCHAR(50),
            email VARCHAR(200),
            city VARCHAR(100),
            client_type ENUM('individual','company') DEFAULT 'individual',
            notes TEXT,
            vat_number VARCHAR(20) DEFAULT NULL,
            cr_number  VARCHAR(20) DEFAULT NULL,
            unified_number VARCHAR(20) DEFAULT NULL,
            na_building VARCHAR(10) DEFAULT NULL,
            na_street VARCHAR(120) DEFAULT NULL,
            na_district VARCHAR(120) DEFAULT NULL,
            na_city VARCHAR(80) DEFAULT NULL,
            na_postal VARCHAR(10) DEFAULT NULL,
            na_additional VARCHAR(10) DEFAULT NULL,
            na_short VARCHAR(12) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول القضايا ── */
        "CREATE TABLE IF NOT EXISTS cases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT,
            case_number VARCHAR(100),
            client_id INT,
            title VARCHAR(500),
            description LONGTEXT,
            notes TEXT,
            case_type VARCHAR(100),
            court_name VARCHAR(200),
            status ENUM('active','closed','suspended','won','lost','settled') DEFAULT 'active',
            priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
            next_session DATETIME,
            fees DECIMAL(10,2) DEFAULT 0,
            paid_amount DECIMAL(10,2) DEFAULT 0,
            file_name VARCHAR(255) DEFAULT NULL,
            file_path VARCHAR(500) DEFAULT NULL,
            file_driver VARCHAR(20) DEFAULT 'local',
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            archived_at DATETIME DEFAULT NULL,
            archived_by INT DEFAULT NULL,
            archive_note VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول الجلسات ── */
        "CREATE TABLE IF NOT EXISTS sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            case_id INT,
            office_id INT,
            session_date DATETIME,
            description TEXT,
            result TEXT,
            notes TEXT,
            next_session_date DATETIME,
            status ENUM('scheduled','held','postponed','cancelled') DEFAULT 'scheduled',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول العقود ── */
        "CREATE TABLE IF NOT EXISTS contracts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT,
            client_id INT,
            contract_number VARCHAR(100),
            title VARCHAR(500),
            contract_type VARCHAR(100),
            start_date DATETIME,
            end_date DATETIME,
            value DECIMAL(10,2),
            status ENUM('draft','active','expired','cancelled') DEFAULT 'draft',
            notes TEXT,
            description TEXT,
            preamble TEXT DEFAULT NULL,
            clauses MEDIUMTEXT DEFAULT NULL,
            template_key VARCHAR(60) DEFAULT NULL,
            linked_ids VARCHAR(255) DEFAULT NULL,
            party_first TEXT DEFAULT NULL,
            party_second TEXT DEFAULT NULL,
            file_name VARCHAR(255),
            file_path VARCHAR(500),
            file_driver VARCHAR(20) DEFAULT 'local',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول الوكالات ── */
        "CREATE TABLE IF NOT EXISTS poa (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT,
            client_id INT,
            poa_number VARCHAR(100),
            title VARCHAR(500),
            granted_to VARCHAR(200),
            issue_date DATETIME,
            expiry_date DATETIME,
            status ENUM('active','expired','revoked') DEFAULT 'active',
            file_name VARCHAR(255),
            file_path VARCHAR(500),
            file_driver VARCHAR(20) DEFAULT 'local',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول المهام ── */
        "CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT,
            case_id INT NULL,
            title VARCHAR(500),
            description TEXT,
            assigned_to VARCHAR(200),
            assigned_to_id INT DEFAULT NULL,
            priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
            status ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending',
            due_date DATETIME,
            completed_at DATETIME DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── قوالب صلاحيات الأدوار + إسناد القضايا ── */
        "CREATE TABLE IF NOT EXISTS office_role_perms (
            office_id INT NOT NULL, role VARCHAR(30) NOT NULL, permissions TEXT DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (office_id, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS case_assignments (
            case_id INT NOT NULL, user_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (case_id, user_id), INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── مكتبة السوابق القضائية ── */
        "CREATE TABLE IF NOT EXISTS precedents (
            id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL,
            title VARCHAR(500) NOT NULL, court VARCHAR(200) DEFAULT NULL, subject VARCHAR(200) DEFAULT NULL,
            case_type VARCHAR(100) DEFAULT NULL, law_system VARCHAR(200) DEFAULT NULL, article VARCHAR(200) DEFAULT NULL,
            ruling_number VARCHAR(100) DEFAULT NULL, ruling_date DATE DEFAULT NULL,
            summary TEXT DEFAULT NULL, full_text LONGTEXT DEFAULT NULL, keywords VARCHAR(500) DEFAULT NULL,
            lawyer_notes TEXT DEFAULT NULL, file_name VARCHAR(255) DEFAULT NULL, file_path VARCHAR(500) DEFAULT NULL,
            file_driver VARCHAR(20) DEFAULT 'local', created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_office (office_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS case_precedents (
            case_id INT NOT NULL, precedent_id INT NOT NULL,
            PRIMARY KEY (case_id, precedent_id), INDEX idx_prec (precedent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── سجل الإجراءات ── */
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول المواعيد ── */
        "CREATE TABLE IF NOT EXISTS appointments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT,
            client_id INT NULL,
            title VARCHAR(500),
            appointment_date DATETIME,
            location VARCHAR(300),
            type ENUM('meeting','court','consultation','other') DEFAULT 'meeting',
            status ENUM('scheduled','completed','cancelled') DEFAULT 'scheduled',
            assigned_to_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول الصادر والوارد ── */
        "CREATE TABLE IF NOT EXISTS correspondence (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT,
            reference_number VARCHAR(100),
            subject VARCHAR(500),
            type ENUM('incoming','outgoing'),
            from_entity VARCHAR(300),
            to_entity VARCHAR(300),
            correspondence_date DATE,
            status ENUM('new','read','replied','archived') DEFAULT 'new',
            notes TEXT DEFAULT NULL,
            file_name VARCHAR(255),
            file_path VARCHAR(500),
            file_driver VARCHAR(20) DEFAULT 'local',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول المعاملات المالية ── */
        "CREATE TABLE IF NOT EXISTS transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT,
            case_id INT NULL,
            client_id INT NULL,
            invoice_id INT DEFAULT NULL,
            source ENUM('manual','invoice') DEFAULT 'manual',
            type ENUM('income','expense'),
            category VARCHAR(100),
            amount DECIMAL(10,2),
            description TEXT,
            payment_method ENUM('cash','bank_transfer','check','card') DEFAULT 'cash',
            transaction_date DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_invoice (invoice_id),
            FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول المكتبة القانونية ── */
        "CREATE TABLE IF NOT EXISTS library (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT NULL,
            title VARCHAR(500),
            category VARCHAR(100),
            content LONGTEXT,
            is_public TINYINT(1) DEFAULT 0,
            file_name VARCHAR(255),
            file_path VARCHAR(500),
            file_driver VARCHAR(20) DEFAULT 'local',
            file_url VARCHAR(500),
            file_size INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول الأرشيف ── */
        "CREATE TABLE IF NOT EXISTS archive (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT,
            title VARCHAR(500),
            category VARCHAR(100),
            file_name VARCHAR(255),
            file_path VARCHAR(500) DEFAULT NULL,
            file_driver VARCHAR(20) DEFAULT 'local',
            file_size VARCHAR(50),
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول الإشعارات ── */
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            office_id INT NULL,
            title VARCHAR(300),
            message TEXT,
            type ENUM('info','warning','success','danger') DEFAULT 'info',
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── الخدمات الرقمية للمكتب ── */
        "CREATE TABLE IF NOT EXISTS office_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT NOT NULL,
            name VARCHAR(300) NOT NULL,
            description TEXT DEFAULT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            vat_rate DECIMAL(5,2) NOT NULL DEFAULT 15,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_office (office_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS service_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT NOT NULL,
            service_id INT DEFAULT NULL,
            service_name VARCHAR(300) NOT NULL,
            client_id INT DEFAULT NULL,
            case_id INT DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM('new','in_progress','completed','cancelled') NOT NULL DEFAULT 'new',
            notes TEXT DEFAULT NULL,
            invoice_id INT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_by_name VARCHAR(200) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_office_date (office_id, created_at),
            INDEX idx_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول إيرادات المنصة ── */
        "CREATE TABLE IF NOT EXISTS platform_revenue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT,
            package_id INT,
            amount DECIMAL(10,2),
            type ENUM('subscription','renewal','upgrade','manual') DEFAULT 'subscription',
            billing_period ENUM('monthly','yearly') DEFAULT 'monthly',
            payment_date DATE,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول فواتير اشتراكات المنصة (ZATCA) ── */
        "CREATE TABLE IF NOT EXISTS subscription_invoices (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(30)  NOT NULL UNIQUE,
            uuid           VARCHAR(36)  NOT NULL,
            office_id      INT          NOT NULL,
            revenue_id     INT          DEFAULT NULL,
            invoice_type   ENUM('simplified','standard') DEFAULT 'standard',
            issue_date     DATE         NOT NULL,
            supply_date    DATE         NOT NULL,
            seller_name    VARCHAR(200) DEFAULT NULL,
            seller_vat     VARCHAR(20)  DEFAULT NULL,
            seller_cr      VARCHAR(20)  DEFAULT NULL,
            seller_address TEXT         DEFAULT NULL,
            buyer_name     VARCHAR(200) DEFAULT NULL,
            buyer_vat      VARCHAR(20)  DEFAULT NULL,
            buyer_cr       VARCHAR(20)  DEFAULT NULL,
            buyer_address  TEXT         DEFAULT NULL,
            package_name   VARCHAR(100) DEFAULT NULL,
            billing_period ENUM('monthly','yearly') DEFAULT 'monthly',
            subtotal       DECIMAL(10,2) DEFAULT 0,
            discount       DECIMAL(10,2) DEFAULT 0,
            tax_rate       DECIMAL(5,2)  DEFAULT 15.00,
            tax_amount     DECIMAL(10,2) DEFAULT 0,
            total          DECIMAL(10,2) DEFAULT 0,
            qr_data        TEXT          DEFAULT NULL,
            notes          TEXT          DEFAULT NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_office  (office_id),
            INDEX idx_revenue (revenue_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول مفاتيح API ── */
        "CREATE TABLE IF NOT EXISTS api_keys (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            office_id      INT NOT NULL,
            key_name       VARCHAR(100) DEFAULT 'مفتاح API',
            api_key        VARCHAR(64)  NOT NULL UNIQUE,
            is_active      TINYINT(1)   DEFAULT 1,
            last_used      DATETIME     DEFAULT NULL,
            requests_count INT          DEFAULT 0,
            created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX (office_id),
            INDEX (api_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول طلبات تغيير الباقة ── */
        "CREATE TABLE IF NOT EXISTS package_requests (
            id                   INT AUTO_INCREMENT PRIMARY KEY,
            office_id            INT NOT NULL,
            current_package_id   INT DEFAULT NULL,
            requested_package_id INT NOT NULL DEFAULT 0,
            reason               TEXT,
            commitment           TINYINT(1) DEFAULT 0,
            status               ENUM('pending','approved','rejected') DEFAULT 'pending',
            admin_note           TEXT,
            payment_proof        VARCHAR(500) DEFAULT NULL,
            is_custom            TINYINT(1) DEFAULT 0,
            custom_features      JSON DEFAULT NULL,
            custom_limits        JSON DEFAULT NULL,
            custom_price_monthly DECIMAL(10,2) DEFAULT 0,
            admin_final_price    DECIMAL(10,2) DEFAULT NULL,
            created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at         DATETIME DEFAULT NULL,
            INDEX (office_id), INDEX (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول تسعير الميزات للباقة المخصصة ── */
        "CREATE TABLE IF NOT EXISTS feature_prices (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            feature_key   VARCHAR(100) NOT NULL UNIQUE,
            feature_label VARCHAR(200) NOT NULL,
            feature_icon  VARCHAR(50)  DEFAULT 'star',
            price_monthly DECIMAL(10,2) DEFAULT 0,
            price_yearly  DECIMAL(10,2) DEFAULT 0,
            sort_order    INT DEFAULT 0,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول مزايا الباقات ── */
        "CREATE TABLE IF NOT EXISTS package_features (
            id INT AUTO_INCREMENT PRIMARY KEY,
            package_id INT NOT NULL,
            feature_key VARCHAR(100) NOT NULL,
            feature_value VARCHAR(255) DEFAULT '1',
            UNIQUE KEY uk_pkg_feat (package_id, feature_key),
            FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول المرفقات الموحّد ── */
        "CREATE TABLE IF NOT EXISTS file_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT NOT NULL,
            entity_type ENUM('case','client','contract','correspondence','task','invoice','general') NOT NULL,
            entity_id INT NOT NULL DEFAULT 0,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL DEFAULT '',
            file_type VARCHAR(100),
            file_size BIGINT DEFAULT 0,
            driver ENUM('local','gdrive') DEFAULT 'local',
            uploaded_by INT,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_office (office_id),
            FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول الفواتير ── */
        "CREATE TABLE IF NOT EXISTS invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT NOT NULL,
            client_id INT,
            case_id INT,
            invoice_number VARCHAR(100),
            title VARCHAR(500),
            items JSON,
            subtotal DECIMAL(10,2) DEFAULT 0,
            discount DECIMAL(10,2) DEFAULT 0,
            discount_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
            discount_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax_rate DECIMAL(5,2) DEFAULT 15.00,
            tax_amount DECIMAL(10,2) DEFAULT 0,
            total DECIMAL(10,2) DEFAULT 0,
            status ENUM('draft','sent','paid','overdue','cancelled') DEFAULT 'draft',
            direction ENUM('income','expense') DEFAULT 'income',
            due_date DATETIME,
            paid_date DATE,
            notes TEXT,
            uuid VARCHAR(36) DEFAULT NULL,
            invoice_type ENUM('simplified','standard') DEFAULT 'simplified',
            issue_date DATE DEFAULT NULL,
            supply_date DATE DEFAULT NULL,
            buyer_vat VARCHAR(20) DEFAULT NULL,
            seller_vat VARCHAR(20) DEFAULT NULL,
            qr_data TEXT DEFAULT NULL,
            zatca_status ENUM('draft','valid') DEFAULT 'draft',
            public_token VARCHAR(40) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول إعدادات المكتب ── */
        "CREATE TABLE IF NOT EXISTS office_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT NOT NULL UNIQUE,
            office_logo VARCHAR(500) DEFAULT NULL,
            address TEXT,
            website VARCHAR(300),
            tax_number VARCHAR(100),
            cr_number VARCHAR(20) DEFAULT NULL,
            bank_name VARCHAR(200),
            bank_iban VARCHAR(100),
            invoice_prefix VARCHAR(20) DEFAULT 'INV',
            invoice_footer TEXT,
            letterhead_path    VARCHAR(500) DEFAULT NULL,
            letterhead_enabled TINYINT(1) NOT NULL DEFAULT 0,
            letterhead_top     INT NOT NULL DEFAULT 42,
            letterhead_bottom  INT NOT NULL DEFAULT 26,
            letterhead_side    INT NOT NULL DEFAULT 18,
            storage_driver        ENUM('server','gdrive') NOT NULL DEFAULT 'server',
            encrypt_files         TINYINT(1) NOT NULL DEFAULT 0,
            storage_salt          VARCHAR(64) DEFAULT NULL,
            gdrive_refresh_token  TEXT,
            gdrive_access_token   TEXT,
            gdrive_token_expiry   BIGINT NOT NULL DEFAULT 0,
            gdrive_root_folder_id VARCHAR(120) DEFAULT NULL,
            gdrive_email          VARCHAR(200) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── جدول محتوى الموقع ── */
        "CREATE TABLE IF NOT EXISTS site_content (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── رسائل اتصل بنا ── */
        "CREATE TABLE IF NOT EXISTS contact_messages (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(150) NOT NULL,
            email       VARCHAR(200) NOT NULL,
            phone       VARCHAR(50)  DEFAULT '',
            subject     VARCHAR(200) DEFAULT '',
            message     TEXT         NOT NULL,
            is_read     TINYINT(1)   DEFAULT 0,
            status      ENUM('new','read','replied','archived') DEFAULT 'new',
            admin_notes TEXT,
            ip_address  VARCHAR(45),
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── سجل التنبيهات (تيليغرام) ── */
        "CREATE TABLE IF NOT EXISTS notification_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            kind VARCHAR(30) NOT NULL,
            ref  VARCHAR(80) NOT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_notif (user_id, kind, ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* ── بيانات الباقات الافتراضية ── */
        "INSERT IGNORE INTO packages (id,name,price_monthly,price_yearly,max_users,max_cases,features,is_active) VALUES
        (1,'الأساسية',  199, 1990, 3,  50,  'إدارة القضايا,إدارة العملاء,التقارير الأساسية,الشؤون المالية',1),
        (2,'الاحترافية',499, 4990, 10, 300, 'كل مزايا الأساسية,المكتبة القانونية,المراسلات,العقود,الأرشيف,الفواتير,المساعد الذكي',1),
        (3,'المؤسسية',  999, 9990, 999, 0,  'كل المزايا,دعم أولوية,تقارير متقدمة,API',1)",

        /* ── مزايا الباقة الأساسية ── */
        "INSERT IGNORE INTO package_features (package_id,feature_key,feature_value) VALUES
        (1,'max_cases','50'),(1,'max_clients','100'),(1,'max_users','3'),
        (1,'storage_mb','0'),(1,'has_finance','1'),(1,'has_contracts','0'),
        (1,'has_poa','0'),(1,'has_library','0'),(1,'has_correspondence','0'),
        (1,'has_archive','0'),(1,'has_ai','0'),(1,'has_invoices','0'),
        (1,'has_reports','1'),(1,'has_api','0')",

        /* ── مزايا الباقة الاحترافية ── */
        "INSERT IGNORE INTO package_features (package_id,feature_key,feature_value) VALUES
        (2,'max_cases','300'),(2,'max_clients','500'),(2,'max_users','10'),
        (2,'storage_mb','1024'),(2,'has_finance','1'),(2,'has_contracts','1'),
        (2,'has_poa','1'),(2,'has_library','1'),(2,'has_correspondence','1'),
        (2,'has_archive','1'),(2,'has_ai','1'),(2,'has_invoices','1'),
        (2,'has_reports','1'),(2,'has_api','0')",

        /* ── مزايا الباقة المؤسسية ── */
        "INSERT IGNORE INTO package_features (package_id,feature_key,feature_value) VALUES
        (3,'max_cases','0'),(3,'max_clients','0'),(3,'max_users','50'),
        (3,'storage_mb','5120'),(3,'has_finance','1'),(3,'has_contracts','1'),
        (3,'has_poa','1'),(3,'has_library','1'),(3,'has_correspondence','1'),
        (3,'has_archive','1'),(3,'has_ai','1'),(3,'has_invoices','1'),
        (3,'has_reports','1'),(3,'has_api','1')",

        /* ── تسعير الميزات الافتراضي ── */
        "INSERT IGNORE INTO feature_prices (feature_key,feature_label,feature_icon,price_monthly,price_yearly,sort_order) VALUES
        ('has_finance','الشؤون المالية','coins',0,0,1),
        ('has_invoices','الفواتير','file-invoice',0,0,2),
        ('has_contracts','العقود','file-signature',0,0,3),
        ('has_poa','الوكالات','stamp',0,0,4),
        ('has_correspondence','الصادر والوارد','envelope',0,0,5),
        ('has_library','المكتبة القانونية','book-open',0,0,6),
        ('has_archive','الأرشيف','archive',0,0,7),
        ('has_ai','المساعد الذكي AI','robot',0,0,8),
        ('has_reports','التقارير المتقدمة','chart-bar',0,0,9),
        ('has_api','واجهة برمجية API','code',0,0,10),
        ('per_user','إضافة مستخدم','user-plus',0,0,11),
        ('per_100_cases','كل 100 قضية إضافية','gavel',0,0,12),
        ('per_512mb','كل 512 MB تخزين','hdd',0,0,13)",

        /* ── محتوى الموقع الافتراضي ── */
        "INSERT IGNORE INTO site_content (setting_key,setting_value) VALUES
        ('site_name','مِحكام'),
        ('site_desc','نظام إدارة مكاتب المحاماة الأذكى'),
        ('contact_email','info@mehkam.sa'),
        ('contact_phone','920000000'),
        ('hero_title','أدِر مكتبك القانوني بذكاء'),
        ('hero_text','منصة سحابية متكاملة لإدارة القضايا والعملاء والشؤون المالية'),
        ('platform_bank_name',''),
        ('platform_bank_iban',''),
        ('platform_bank_account',''),
        ('platform_payment_instructions',''),
        ('custom_base_users','3'),
        ('custom_base_cases','50'),
        ('custom_base_price','0')",
    ];
}

// ── فحص المتطلبات ────────────────────────────────────────────
function checkRequirements() {
    $checks = [];

    // PHP Version
    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $checks[] = ['PHP ' . PHP_VERSION, $phpOk, $phpOk ? '' : 'مطلوب PHP 7.4 أو أحدث'];

    // mysqli
    $checks[] = ['امتداد mysqli', extension_loaded('mysqli'), extension_loaded('mysqli') ? '' : 'يرجى تفعيل امتداد mysqli'];

    // JSON
    $checks[] = ['امتداد json', extension_loaded('json'), extension_loaded('json') ? '' : 'يرجى تفعيل امتداد json'];

    // sessions
    $checks[] = ['امتداد session', extension_loaded('session'), ''];

    // config dir writable
    $configDir = __DIR__ . '/config/';
    $checks[] = ['مجلد config قابل للكتابة', is_writable($configDir), is_writable($configDir) ? '' : 'يرجى تغيير صلاحيات مجلد config إلى 755'];

    // uploads dir
    $uploadsDir = __DIR__ . '/uploads/';
    $uploadsOk  = is_dir($uploadsDir) && is_writable($uploadsDir);
    if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
    $uploadsOk = is_dir($uploadsDir) && is_writable($uploadsDir);
    $checks[] = ['مجلد uploads قابل للكتابة', $uploadsOk, $uploadsOk ? '' : 'يرجى إنشاء مجلد uploads وتعيين صلاحيات 755'];

    return $checks;
}

// ── مساعد HTML للصفحة النهائية ────────────────────────────────
function renderDone($title, $msg, $isError = true) {
    $color = $isError ? '#ef4444' : '#22c55e';
    $icon  = $isError ? 'fa-times-circle' : 'fa-check-circle';
    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>*{font-family:Tajawal,sans-serif;box-sizing:border-box}body{background:#0c1b36;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
    .box{background:#fff;border-radius:20px;padding:52px 40px;text-align:center;max-width:480px;width:90%;box-shadow:0 32px 80px rgba(0,0,0,.4)}
    i{font-size:56px;color:' . $color . ';margin-bottom:20px;display:block}
    h2{color:#0c1b36;font-weight:900;font-size:24px;margin-bottom:12px}
    p{color:#6b7280;font-size:14px;line-height:1.8}</style></head><body>
    <div class="box"><i class="fas ' . $icon . '"></i><h2>' . $title . '</h2><p>' . $msg . '</p></div></body></html>';
}

// ── عرض الواجهة ──────────────────────────────────────────────
$requirements = checkRequirements();
$allReqPassed = !in_array(false, array_column($requirements, 1));

// إذا كانت نتيجة التثبيت جاهزة
if ($step === 5 && isset($info['success']) && $info['success']) {
    echo renderInstallComplete($info);
    exit;
}

function renderInstallComplete($info) {
    $adminUser = htmlspecialchars($info['adminUser']);
    $adminPass = htmlspecialchars($info['adminPass']);
    $dbName    = htmlspecialchars($info['dbName']);
    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>اكتمل التثبيت — مِحكام</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <style>
    *{font-family:Tajawal,sans-serif}
    body{background:linear-gradient(135deg,#0c1b36 0%,#0f2040 50%,#1a3a6e 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{border-radius:24px;border:none;box-shadow:0 40px 100px rgba(0,0,0,.5)}
    .success-icon{width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#16a34a,#22c55e);display:flex;align-items:center;justify-content:center;font-size:36px;color:#fff;margin:0 auto 24px;box-shadow:0 8px 30px rgba(34,197,94,.4)}
    .cred-box{background:#f8fafc;border:2px solid #e2e8f0;border-radius:14px;padding:20px}
    .cred-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f0f0f0}
    .cred-row:last-child{border:none}
    .cred-label{font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase}
    .cred-val{font-family:monospace;font-size:15px;font-weight:700;color:#0c1b36;background:#e8f0fe;padding:4px 12px;border-radius:8px;letter-spacing:.5px}
    .warn-box{background:#fffbeb;border:1px solid #fcd34d;border-radius:12px;padding:16px;font-size:13px;color:#92400e}
    </style></head><body>
    <div style="max-width:560px;width:100%">
      <div class="card">
        <div class="card-body p-5 text-center">
          <div class="success-icon"><i class="fas fa-check"></i></div>
          <h2 style="color:#0c1b36;font-weight:900;font-size:26px;margin-bottom:8px">🎉 اكتمل التثبيت!</h2>
          <p style="color:#6b7280;font-size:14px;margin-bottom:28px">تم تثبيت <strong>مِحكام</strong> بنجاح وإنشاء قاعدة البيانات <strong>' . $dbName . '</strong></p>

          <div class="cred-box text-start mb-4">
            <h6 style="font-weight:800;color:#0c1b36;margin-bottom:16px"><i class="fas fa-key me-2 text-warning"></i>بيانات الدخول</h6>
            <div class="cred-row">
              <span class="cred-label">اسم المستخدم</span>
              <span class="cred-val">' . $adminUser . '</span>
            </div>
            <div class="cred-row">
              <span class="cred-label">كلمة المرور</span>
              <span class="cred-val">' . $adminPass . '</span>
            </div>
            <div class="cred-row">
              <span class="cred-label">رابط الدخول</span>
              <span class="cred-val">login.php</span>
            </div>
          </div>

          <div class="warn-box text-start mb-4">
            <i class="fas fa-exclamation-triangle me-2 text-warning"></i>
            <strong>تنبيه أمني:</strong> احفظ كلمة المرور في مكان آمن، ثم احذف ملف <code>install.php</code> من الخادم فوراً.
          </div>

          <a href="login.php" class="btn btn-lg w-100 text-white fw-bold" style="background:linear-gradient(135deg,#0c1b36,#1a3a6e);border:none;border-radius:12px;font-size:16px;padding:14px">
            <i class="fas fa-sign-in-alt me-2"></i>الذهاب للوحة التحكم
          </a>
        </div>
      </div>
    </div>
    </body></html>';
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>معالج التثبيت — مِحكام</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<style>
*{font-family:'Tajawal',sans-serif;box-sizing:border-box}

body{
  background:linear-gradient(135deg,#0c1b36 0%,#0f2040 50%,#1a3a6e 100%);
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px 10px;
}

/* grid bg */
body::before{
  content:'';position:fixed;inset:0;
  background-image:linear-gradient(rgba(255,255,255,.03) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px);
  background-size:48px 48px;pointer-events:none;
}

.installer{max-width:680px;width:100%;position:relative;z-index:1}

/* brand header */
.brand{text-align:center;margin-bottom:32px}
.brand-icon{
  width:68px;height:68px;border-radius:18px;margin:0 auto 14px;
  background:linear-gradient(135deg,#b8860b,#e8c040);
  display:flex;align-items:center;justify-content:center;
  font-size:28px;color:#0c1b36;box-shadow:0 8px 28px rgba(232,192,64,.4);
}
.brand-name{font-size:36px;font-weight:900;color:#fff;letter-spacing:-1px;margin-bottom:4px}
.brand-sub{color:rgba(255,255,255,.45);font-size:14px}

/* steps bar */
.steps-bar{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:28px}
.step-dot{
  width:36px;height:36px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:13px;font-weight:700;border:2px solid rgba(255,255,255,.2);
  color:rgba(255,255,255,.4);background:rgba(255,255,255,.06);
  transition:all .3s;flex-shrink:0;
}
.step-dot.active{background:linear-gradient(135deg,#b8860b,#e8c040);border-color:#e8c040;color:#0c1b36;box-shadow:0 4px 16px rgba(232,192,64,.4)}
.step-dot.done{background:#22c55e;border-color:#22c55e;color:#fff}
.step-line{flex:1;height:2px;background:rgba(255,255,255,.12);max-width:60px}
.step-line.done{background:#22c55e}
.steps-labels{display:flex;justify-content:space-between;padding:0 4px;margin-bottom:24px}
.step-lbl{font-size:11px;color:rgba(255,255,255,.4);text-align:center;flex:1}
.step-lbl.active{color:#e8c040;font-weight:700}
.step-lbl.done{color:#22c55e}

/* card */
.card{border:none;border-radius:20px;box-shadow:0 32px 80px rgba(0,0,0,.45);overflow:hidden}
.card-header-custom{
  background:linear-gradient(135deg,#0a1628,#0f2040);
  padding:24px 32px;border-bottom:1px solid rgba(255,255,255,.07);
}
.card-header-custom h5{color:#fff;font-weight:800;font-size:18px;margin:0}
.card-header-custom p{color:rgba(255,255,255,.5);font-size:13px;margin:4px 0 0}
.card-body{padding:32px}

/* form */
.form-label{font-weight:700;font-size:13px;color:#374151;margin-bottom:6px}
.form-control, .form-select{
  border:1.5px solid #e5e7eb;border-radius:10px;
  font-size:14px;font-family:'Tajawal',sans-serif;
  padding:11px 14px;transition:border-color .2s,box-shadow .2s;
}
.form-control:focus,.form-select:focus{
  border-color:#0c1b36;box-shadow:0 0 0 3px rgba(12,27,54,.1);outline:none;
}
.input-group .form-control{border-radius:0 10px 10px 0}
.input-group-text{border:1.5px solid #e5e7eb;border-right:none;border-radius:10px 0 0 10px;background:#f8f9fa;color:#6b7280;font-size:13px}

/* requirement item */
.req-item{
  display:flex;align-items:center;gap:12px;padding:12px 16px;
  border-radius:10px;margin-bottom:8px;font-size:14px;
}
.req-ok{background:#f0fdf4;border:1px solid #bbf7d0}
.req-ok .icon{color:#22c55e}
.req-fail{background:#fef2f2;border:1px solid #fecaca}
.req-fail .icon{color:#ef4444}
.req-warn{background:#fffbeb;border:1px solid #fde68a}
.req-warn .icon{color:#f59e0b}

/* btn */
.btn-install{
  background:linear-gradient(135deg,#0c1b36,#1a3a6e);
  color:#fff;border:none;border-radius:12px;
  padding:13px 28px;font-size:15px;font-weight:800;
  font-family:'Tajawal',sans-serif;
  transition:all .2s;
}
.btn-install:hover{opacity:.9;transform:translateY(-2px);box-shadow:0 8px 24px rgba(12,27,54,.4);color:#fff}
.btn-back{
  background:#f3f4f6;color:#374151;border:none;border-radius:12px;
  padding:13px 20px;font-size:14px;font-weight:700;
  font-family:'Tajawal',sans-serif;transition:all .2s;
}
.btn-back:hover{background:#e5e7eb;color:#111}

/* password eye */
.pw-wrap{position:relative}
.pw-wrap .form-control{padding-left:44px}
.pw-eye{position:absolute;left:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9ca3af;cursor:pointer;font-size:14px;padding:4px}

/* error */
.err-list{background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:16px 20px;margin-bottom:20px}
.err-list li{color:#dc2626;font-size:13px;margin-bottom:4px}
.err-list li:last-child{margin:0}

/* install progress */
.install-step{
  display:flex;align-items:center;gap:14px;padding:14px 16px;
  border-radius:10px;border:1px solid #e5e7eb;margin-bottom:10px;
  font-size:14px;opacity:.4;transition:all .4s;
}
.install-step.running{opacity:1;border-color:#bfdbfe;background:#eff6ff}
.install-step.done-s{opacity:1;border-color:#bbf7d0;background:#f0fdf4}
.install-step.err-s{opacity:1;border-color:#fecaca;background:#fef2f2}
.step-ico{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
</style>
</head>
<body>
<div class="installer">

  <!-- Brand -->
  <div class="brand">
    <div class="brand-icon"><i class="fas fa-gavel"></i></div>
    <div class="brand-name">مِحكام</div>
    <div class="brand-sub">معالج التثبيت — نظام إدارة مكاتب المحاماة</div>
  </div>

  <!-- Steps -->
  <?php
  $stepNames  = ['المتطلبات','قاعدة البيانات','حساب المدير','التثبيت'];
  $totalSteps = 4;
  $displayStep = min($step, $totalSteps);
  ?>
  <div class="steps-bar">
    <?php for($i=1;$i<=$totalSteps;$i++):
      $cls = $i < $displayStep ? 'done' : ($i === $displayStep ? 'active' : '');
    ?>
      <?php if($i>1): ?><div class="step-line <?= $i <= $displayStep ? 'done' : '' ?>"></div><?php endif; ?>
      <div class="step-dot <?= $cls ?>">
        <?= $cls==='done' ? '<i class="fas fa-check" style="font-size:12px"></i>' : $i ?>
      </div>
    <?php endfor; ?>
  </div>
  <div class="steps-labels">
    <?php for($i=1;$i<=$totalSteps;$i++):
      $cls = $i < $displayStep ? 'done' : ($i === $displayStep ? 'active' : '');
    ?>
    <div class="step-lbl <?= $cls ?>"><?= $stepNames[$i-1] ?></div>
    <?php endfor; ?>
  </div>

  <!-- Card -->
  <div class="card">

    <?php if ($errors): ?>
    <!-- ── أخطاء ── -->
    <div style="padding:0 32px;padding-top:24px">
      <ul class="err-list">
        <?php foreach($errors as $e): ?>
        <li><i class="fas fa-times-circle me-2"></i><?= $e ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════
         الخطوة 1 — فحص المتطلبات
    ════════════════════════════════════════════ -->
    <?php if ($step === 1): ?>
    <div class="card-header-custom">
      <h5><i class="fas fa-clipboard-check me-2 text-warning"></i>فحص المتطلبات</h5>
      <p>التحقق من جاهزية بيئة PHP والخادم</p>
    </div>
    <div class="card-body">

      <?php foreach($requirements as [$label, $pass, $note]): ?>
      <div class="req-item <?= $pass ? 'req-ok' : 'req-fail' ?>">
        <span class="icon"><i class="fas fa-<?= $pass ? 'check-circle' : 'times-circle' ?>"></i></span>
        <div class="flex-grow-1">
          <div class="fw-semibold"><?= $label ?></div>
          <?php if(!$pass && $note): ?><small class="text-muted"><?= $note ?></small><?php endif; ?>
        </div>
        <span class="badge <?= $pass ? 'bg-success' : 'bg-danger' ?> bg-opacity-10 <?= $pass ? 'text-success' : 'text-danger' ?>">
          <?= $pass ? 'ممتاز' : 'مشكلة' ?>
        </span>
      </div>
      <?php endforeach; ?>

      <?php if(!$allReqPassed): ?>
      <div class="alert alert-danger mt-3 rounded-3">
        <i class="fas fa-exclamation-triangle me-2"></i>
        يرجى إصلاح المشاكل أعلاه قبل المتابعة.
      </div>
      <?php else: ?>
      <div class="alert alert-success mt-3 rounded-3">
        <i class="fas fa-check-circle me-2"></i>
        جميع المتطلبات مستوفاة! يمكنك المتابعة.
      </div>
      <?php endif; ?>

      <div class="d-flex justify-content-end mt-4">
        <form method="GET" action="">
          <input type="hidden" name="step" value="2">
          <button type="submit" class="btn-install" <?= !$allReqPassed ? 'disabled' : '' ?>>
            التالي — إعداد قاعدة البيانات <i class="fas fa-arrow-left ms-2"></i>
          </button>
        </form>
      </div>
    </div>

    <!-- ════════════════════════════════════════════
         الخطوة 2 — إعداد قاعدة البيانات
    ════════════════════════════════════════════ -->
    <?php elseif ($step === 2): ?>
    <div class="card-header-custom">
      <h5><i class="fas fa-database me-2 text-warning"></i>إعداد قاعدة البيانات</h5>
      <p>أدخل بيانات الاتصال بخادم MySQL</p>
    </div>
    <div class="card-body">
      <form method="POST" action="">
        <input type="hidden" name="step" value="2">

        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label">خادم MySQL (Host)</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fas fa-server"></i></span>
              <input type="text" name="db_host" class="form-control" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" placeholder="localhost">
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">المنفذ (Port)</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fas fa-plug"></i></span>
              <input type="number" name="db_port" class="form-control" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>" placeholder="3306">
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">اسم قاعدة البيانات <small class="text-muted fw-normal">(ستُنشأ تلقائياً إذا لم تكن موجودة)</small></label>
            <div class="input-group">
              <span class="input-group-text"><i class="fas fa-database"></i></span>
              <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars($_POST['db_name'] ?? 'mehkam_db') ?>" required>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">اسم مستخدم MySQL</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fas fa-user-cog"></i></span>
              <input type="text" name="db_user" class="form-control" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required placeholder="root">
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">كلمة مرور MySQL</label>
            <div class="pw-wrap">
              <input type="password" name="db_pass" id="dbPass" class="form-control" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>" placeholder="اتركها فارغة إذا لم تكن موجودة">
              <button type="button" class="pw-eye" onclick="togglePw('dbPass','dbPassEye')"><i class="fas fa-eye" id="dbPassEye"></i></button>
            </div>
          </div>
        </div>

        <div class="alert mt-3 rounded-3" style="background:#eff6ff;border:1px solid #bfdbfe;font-size:13px;color:#1e40af">
          <i class="fas fa-info-circle me-2"></i>
          تأكد أن مستخدم MySQL يملك صلاحيات <strong>CREATE, SELECT, INSERT, UPDATE, DELETE, DROP</strong>
        </div>

        <div class="d-flex justify-content-between mt-4">
          <a href="install.php?step=1" class="btn-back"><i class="fas fa-arrow-right me-1"></i>رجوع</a>
          <button type="submit" class="btn-install">
            <i class="fas fa-plug me-2"></i>اختبار الاتصال والمتابعة <i class="fas fa-arrow-left ms-2"></i>
          </button>
        </div>
      </form>
    </div>

    <!-- ════════════════════════════════════════════
         الخطوة 3 — حساب المدير
    ════════════════════════════════════════════ -->
    <?php elseif ($step === 3): ?>
    <div class="card-header-custom">
      <h5><i class="fas fa-user-shield me-2 text-warning"></i>إعداد حساب المدير</h5>
      <p>أنشئ حساب مدير النظام وإعدادات المنصة</p>
    </div>
    <div class="card-body">
      <form method="POST" action="">
        <input type="hidden" name="step" value="3">

        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">اسم المنصة / الموقع</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fas fa-globe"></i></span>
              <input type="text" name="site_name" class="form-control" value="<?= htmlspecialchars($_POST['site_name'] ?? 'مِحكام') ?>" required>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">الاسم الكامل للمدير</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fas fa-user"></i></span>
              <input type="text" name="admin_name" class="form-control" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required placeholder="مدير النظام">
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">البريد الإلكتروني <small class="text-muted fw-normal">(اختياري)</small></label>
            <div class="input-group">
              <span class="input-group-text"><i class="fas fa-envelope"></i></span>
              <input type="email" name="admin_email" class="form-control" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" placeholder="admin@example.com">
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">اسم مستخدم الدخول</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fas fa-at"></i></span>
              <input type="text" name="admin_user" class="form-control" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required placeholder="admin" minlength="3">
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">كلمة المرور <small class="text-muted fw-normal">(6 أحرف على الأقل)</small></label>
            <div class="pw-wrap">
              <input type="password" name="admin_pass" id="adminPass" class="form-control" required minlength="6" placeholder="••••••••">
              <button type="button" class="pw-eye" onclick="togglePw('adminPass','adminPassEye')"><i class="fas fa-eye" id="adminPassEye"></i></button>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">تأكيد كلمة المرور</label>
            <div class="pw-wrap">
              <input type="password" name="admin_pass2" id="adminPass2" class="form-control" required placeholder="••••••••">
              <button type="button" class="pw-eye" onclick="togglePw('adminPass2','adminPass2Eye')"><i class="fas fa-eye" id="adminPass2Eye"></i></button>
            </div>
          </div>
        </div>

        <div class="alert mt-3 rounded-3" style="background:#fffbeb;border:1px solid #fde68a;font-size:13px;color:#92400e">
          <i class="fas fa-shield-alt me-2"></i>
          استخدم كلمة مرور قوية تحتوي على أحرف وأرقام ورموز. سيتم تشفيرها بـ bcrypt.
        </div>

        <div class="d-flex justify-content-between mt-4">
          <a href="install.php?step=2" class="btn-back"><i class="fas fa-arrow-right me-1"></i>رجوع</a>
          <button type="submit" class="btn-install">
            <i class="fas fa-rocket me-2"></i>بدء التثبيت <i class="fas fa-arrow-left ms-2"></i>
          </button>
        </div>
      </form>
    </div>

    <!-- ════════════════════════════════════════════
         الخطوة 4 — تنفيذ التثبيت
    ════════════════════════════════════════════ -->
    <?php elseif ($step === 4): ?>
    <div class="card-header-custom">
      <h5><i class="fas fa-cogs me-2 text-warning"></i>جارٍ التثبيت...</h5>
      <p>تهيئة قاعدة البيانات وإعداد النظام</p>
    </div>
    <div class="card-body">

      <?php if (empty($errors)): ?>
      <!-- نموذج auto-submit لتشغيل الخطوة 4 -->
      <div id="progressWrap">
        <?php
        $installSteps = [
          ['database','إنشاء قاعدة البيانات'],
          ['table','إنشاء الجداول (22 جدول)'],
          ['feature','تهيئة بيانات الباقات'],
          ['user','إنشاء حساب المدير'],
          ['config','كتابة ملف الإعدادات'],
          ['lock','تأمين المثبِّت'],
        ];
        foreach($installSteps as $idx => [$ico, $lbl]):
        ?>
        <div class="install-step" id="is<?= $idx ?>">
          <div class="step-ico" style="background:#f3f4f6">
            <i class="fas fa-<?= $ico === 'database' ? 'database' : ($ico === 'table' ? 'table' : ($ico === 'feature' ? 'box' : ($ico === 'user' ? 'user-shield' : ($ico === 'config' ? 'file-code' : 'lock')))) ?>"></i>
          </div>
          <span><?= $lbl ?></span>
          <span class="ms-auto" style="font-size:12px;color:#9ca3af">انتظار...</span>
        </div>
        <?php endforeach; ?>
      </div>
      <form method="POST" action="" id="installForm">
        <input type="hidden" name="step" value="4">
      </form>
      <?php else: ?>
      <div class="d-flex justify-content-between mt-2">
        <a href="install.php?step=2" class="btn-back"><i class="fas fa-redo me-1"></i>إعادة المحاولة</a>
      </div>
      <?php endif; ?>
    </div>

    <?php endif; ?>

  </div><!-- /card -->

  <div style="text-align:center;margin-top:20px;color:rgba(255,255,255,.3);font-size:12px">
    مِحكام — نظام إدارة مكاتب المحاماة &copy; <?= date('Y') ?>
  </div>
</div>

<script>
function togglePw(id, eyeId) {
  var f = document.getElementById(id),
      e = document.getElementById(eyeId);
  f.type = f.type === 'password' ? 'text' : 'password';
  e.className = f.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}

// خطوة 4 — محاكاة التقدم ثم إرسال الطلب
<?php if ($step === 4 && empty($errors)): ?>
(function() {
  var steps = document.querySelectorAll('.install-step');
  var icons = ['fa-database','fa-table','fa-box','fa-user-shield','fa-file-code','fa-lock'];
  var delay = 0;

  steps.forEach(function(el, i) {
    var lbl = el.querySelector('.step-ico i');
    var info = el.querySelector('span:last-child');

    setTimeout(function() {
      el.classList.add('running');
      info.textContent = 'جارٍ التنفيذ...';
      info.style.color = '#3b82f6';
      lbl.style.color  = '#3b82f6';
    }, delay);

    delay += 380;

    setTimeout(function() {
      el.classList.remove('running');
      el.classList.add('done-s');
      info.textContent = '✓ تم';
      info.style.color = '#16a34a';
    }, delay);

    delay += 120;
  });

  // إرسال النموذج بعد انتهاء التحريك
  setTimeout(function() {
    document.getElementById('installForm').submit();
  }, delay + 200);
})();
<?php endif; ?>
</script>
</body>
</html>
