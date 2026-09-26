-- ================================================================
--  مِحكام — Schema v2 (إضافة جداول جديدة)
--  تُنفَّذ على قاعدة البيانات الموجودة بعد schema.sql
-- ================================================================

USE lawsaas_db;

-- ── جدول مزايا الباقات (التحكم الدقيق) ──────────────────────
CREATE TABLE IF NOT EXISTS package_features (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    package_id    INT NOT NULL,
    feature_key   VARCHAR(100) NOT NULL,
    feature_value VARCHAR(255) DEFAULT '1',
    UNIQUE KEY uk_pkg_feat (package_id, feature_key),
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── بيانات افتراضية لمزايا الباقات ──────────────────────────
-- باقة 1 — الأساسية
INSERT IGNORE INTO package_features (package_id, feature_key, feature_value) VALUES
(1,'max_cases',        '50'),
(1,'max_clients',      '100'),
(1,'max_users',        '3'),
(1,'storage_mb',       '0'),
(1,'has_finance',      '1'),
(1,'has_contracts',    '0'),
(1,'has_poa',          '0'),
(1,'has_library',      '0'),
(1,'has_correspondence','0'),
(1,'has_archive',      '0'),
(1,'has_ai',           '0'),
(1,'has_invoices',     '0'),
(1,'has_reports',      '0'),
(1,'has_api',          '0');

-- باقة 2 — الاحترافية
INSERT IGNORE INTO package_features (package_id, feature_key, feature_value) VALUES
(2,'max_cases',        '300'),
(2,'max_clients',      '500'),
(2,'max_users',        '10'),
(2,'storage_mb',       '1024'),
(2,'has_finance',      '1'),
(2,'has_contracts',    '1'),
(2,'has_poa',          '1'),
(2,'has_library',      '1'),
(2,'has_correspondence','1'),
(2,'has_archive',      '1'),
(2,'has_ai',           '1'),
(2,'has_invoices',     '1'),
(2,'has_reports',      '1'),
(2,'has_api',          '0');

-- باقة 3 — المؤسسية
INSERT IGNORE INTO package_features (package_id, feature_key, feature_value) VALUES
(3,'max_cases',        '0'),
(3,'max_clients',      '0'),
(3,'max_users',        '50'),
(3,'storage_mb',       '5120'),
(3,'has_finance',      '1'),
(3,'has_contracts',    '1'),
(3,'has_poa',          '1'),
(3,'has_library',      '1'),
(3,'has_correspondence','1'),
(3,'has_archive',      '1'),
(3,'has_ai',           '1'),
(3,'has_invoices',     '1'),
(3,'has_reports',      '1'),
(3,'has_api',          '1');

-- ── جدول المرفقات الموحّد ────────────────────────────────────
CREATE TABLE IF NOT EXISTS file_attachments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    office_id     INT NOT NULL,
    entity_type   ENUM('case','client','contract','correspondence','task','invoice','general') NOT NULL,
    entity_id     INT NOT NULL DEFAULT 0,
    original_name VARCHAR(255) NOT NULL,
    stored_name   VARCHAR(255) NOT NULL,
    file_path     VARCHAR(500) NOT NULL DEFAULT '',
    file_type     VARCHAR(100),
    file_size     BIGINT DEFAULT 0,
    driver        ENUM('local','gdrive') DEFAULT 'local',
    uploaded_by   INT,
    notes         TEXT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_office (office_id),
    FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── جدول الفواتير ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS invoices (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    office_id      INT NOT NULL,
    client_id      INT,
    case_id        INT,
    invoice_number VARCHAR(100),
    title          VARCHAR(500),
    items          JSON,
    subtotal       DECIMAL(10,2) DEFAULT 0,
    discount       DECIMAL(10,2) DEFAULT 0,
    tax_rate       DECIMAL(5,2)  DEFAULT 15.00,
    tax_amount     DECIMAL(10,2) DEFAULT 0,
    total          DECIMAL(10,2) DEFAULT 0,
    status         ENUM('draft','sent','paid','overdue','cancelled') DEFAULT 'draft',
    due_date       DATE,
    paid_date      DATE,
    notes          TEXT,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (office_id)  REFERENCES offices(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id)  REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── إضافة أعمدة للقضايا (المرفق المركزي) ────────────────────
ALTER TABLE cases
    ADD COLUMN IF NOT EXISTS description LONGTEXT AFTER title,
    ADD COLUMN IF NOT EXISTS notes       TEXT     AFTER description,
    ADD COLUMN IF NOT EXISTS file_name   VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS file_path   VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS file_driver VARCHAR(20)  DEFAULT 'local';

-- ── ملاحظات على الجلسات ──────────────────────────────────────
ALTER TABLE sessions
    ADD COLUMN IF NOT EXISTS notes TEXT AFTER result;

-- ── تتبع من أنشأ المهمة ──────────────────────────────────────
ALTER TABLE tasks
    ADD COLUMN IF NOT EXISTS created_by INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS description TEXT DEFAULT NULL;

-- ── حقل office_slug للرابط المختصر ──────────────────────────
ALTER TABLE offices
    ADD COLUMN IF NOT EXISTS slug VARCHAR(100) UNIQUE DEFAULT NULL;

-- ── تبيّن إذا كان العقد له مرفق ──────────────────────────────
ALTER TABLE contracts
    ADD COLUMN IF NOT EXISTS notes       TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS description TEXT DEFAULT NULL;

-- ── جدول إعدادات المكتب ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS office_settings (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    office_id  INT NOT NULL UNIQUE,
    logo_path  VARCHAR(500),
    address    TEXT,
    website    VARCHAR(300),
    tax_number VARCHAR(100),
    bank_name  VARCHAR(200),
    bank_iban  VARCHAR(100),
    invoice_prefix VARCHAR(20) DEFAULT 'INV',
    invoice_footer TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── بيانات مثال للفواتير ─────────────────────────────────────
INSERT IGNORE INTO invoices (office_id,client_id,case_id,invoice_number,title,items,subtotal,discount,tax_rate,tax_amount,total,status,due_date) VALUES
(1, 1, 1, 'INV-2024-001', 'فاتورة أتعاب قضية النزاع العمالي',
 '[{"description":"أتعاب تمثيل قانوني","qty":1,"price":5000}]',
 5000.00, 0, 15.00, 750.00, 5750.00, 'paid', '2024-04-01'),
(1, 2, 2, 'INV-2024-002', 'فاتورة استشارة قانونية',
 '[{"description":"استشارة قانونية تجارية","qty":2,"price":750}]',
 1500.00, 0, 15.00, 225.00, 1725.00, 'sent', '2024-04-20'),
(1, 3, 3, 'INV-2024-003', 'فاتورة أتعاب قضية الأحوال الشخصية',
 '[{"description":"أتعاب جلسات وتمثيل","qty":1,"price":3000}]',
 3000.00, 300, 15.00, 405.00, 3105.00, 'draft', '2024-05-01');
