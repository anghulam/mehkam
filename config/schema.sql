-- =============================================
-- LawSaaS - نظام إدارة مكاتب المحاماة
-- =============================================

CREATE DATABASE IF NOT EXISTS lawsaas_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lawsaas_db;

-- المستخدمون
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','office_owner','lawyer','secretary') DEFAULT 'lawyer',
    office_id INT NULL,
    full_name VARCHAR(200),
    email VARCHAR(200),
    phone VARCHAR(50),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- الباقات
CREATE TABLE packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    price_monthly DECIMAL(10,2),
    price_yearly DECIMAL(10,2),
    max_users INT DEFAULT 5,
    max_cases INT DEFAULT 100,
    features TEXT,
    is_active TINYINT(1) DEFAULT 1
);

-- المكاتب
CREATE TABLE offices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200),
    license_number VARCHAR(100),
    owner_name VARCHAR(200),
    package_id INT,
    city VARCHAR(100),
    phone VARCHAR(50),
    email VARCHAR(200),
    subscription_end DATE,
    status ENUM('active','expired','suspended','trial') DEFAULT 'trial',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (package_id) REFERENCES packages(id)
);

-- العملاء
CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT,
    full_name VARCHAR(200),
    id_number VARCHAR(50),
    phone VARCHAR(50),
    email VARCHAR(200),
    city VARCHAR(100),
    client_type ENUM('individual','company') DEFAULT 'individual',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (office_id) REFERENCES offices(id)
);

-- القضايا
CREATE TABLE cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT,
    case_number VARCHAR(100),
    client_id INT,
    title VARCHAR(500),
    case_type VARCHAR(100),
    court_name VARCHAR(200),
    status ENUM('active','closed','suspended','won','lost','settled') DEFAULT 'active',
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    next_session DATE,
    fees DECIMAL(10,2) DEFAULT 0,
    paid_amount DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (office_id) REFERENCES offices(id),
    FOREIGN KEY (client_id) REFERENCES clients(id)
);

-- الجلسات
CREATE TABLE sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT,
    office_id INT,
    session_date DATETIME,
    description TEXT,
    result TEXT,
    next_session_date DATETIME,
    status ENUM('scheduled','held','postponed','cancelled') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id)
);

-- العقود
CREATE TABLE contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT,
    client_id INT,
    contract_number VARCHAR(100),
    title VARCHAR(500),
    contract_type VARCHAR(100),
    start_date DATE,
    end_date DATE,
    value DECIMAL(10,2),
    status ENUM('draft','active','expired','cancelled') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (office_id) REFERENCES offices(id),
    FOREIGN KEY (client_id) REFERENCES clients(id)
);

-- الوكالات
CREATE TABLE poa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT,
    client_id INT,
    poa_number VARCHAR(100),
    title VARCHAR(500),
    granted_to VARCHAR(200),
    issue_date DATE,
    expiry_date DATE,
    status ENUM('active','expired','revoked') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (office_id) REFERENCES offices(id)
);

-- المهام
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT,
    case_id INT NULL,
    title VARCHAR(500),
    assigned_to VARCHAR(200),
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    status ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending',
    due_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- المواعيد
CREATE TABLE appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT,
    client_id INT NULL,
    title VARCHAR(500),
    appointment_date DATETIME,
    location VARCHAR(300),
    type ENUM('meeting','court','consultation','other') DEFAULT 'meeting',
    status ENUM('scheduled','completed','cancelled') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- الصادر والوارد
CREATE TABLE correspondence (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT,
    reference_number VARCHAR(100),
    subject VARCHAR(500),
    type ENUM('incoming','outgoing'),
    from_entity VARCHAR(300),
    to_entity VARCHAR(300),
    correspondence_date DATE,
    status ENUM('new','read','replied','archived') DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- المعاملات المالية
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT,
    case_id INT NULL,
    client_id INT NULL,
    type ENUM('income','expense'),
    category VARCHAR(100),
    amount DECIMAL(10,2),
    description TEXT,
    payment_method ENUM('cash','bank_transfer','check','card') DEFAULT 'cash',
    transaction_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- المكتبة القانونية
CREATE TABLE library (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NULL,
    title VARCHAR(500),
    category VARCHAR(100),
    content LONGTEXT,
    is_public TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- الأرشيف
CREATE TABLE archive (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT,
    title VARCHAR(500),
    category VARCHAR(100),
    file_name VARCHAR(255),
    file_size VARCHAR(50),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- الإشعارات
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    office_id INT NULL,
    title VARCHAR(300),
    message TEXT,
    type ENUM('info','warning','success','danger') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- إيرادات المنصة
CREATE TABLE platform_revenue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT,
    package_id INT,
    amount DECIMAL(10,2),
    type ENUM('subscription','renewal','upgrade') DEFAULT 'subscription',
    payment_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ====== بيانات تجريبية ======

INSERT INTO users (username, password, role, full_name, email) VALUES
('admin', '$2y$10$YJPBqEwCd.U2mJwYGVHLQ.vX8lSGR3Y9kNtpQ5TzZB5n1qWoRDTJu', 'admin', 'مدير النظام', 'admin@lawsaas.com'),
('user',  '$2y$10$YJPBqEwCd.U2mJwYGVHLQ.vX8lSGR3Y9kNtpQ5TzZB5n1qWoRDTJu', 'office_owner', 'أحمد الشمري', 'ahmed@law.com');
-- password for both: MedoLo@@123321%%

INSERT INTO packages (name, price_monthly, price_yearly, max_users, max_cases, features) VALUES
('الأساسية',   199, 1990,  3,  50,  'إدارة القضايا,إدارة العملاء,التقارير الأساسية'),
('الاحترافية', 499, 4990,  10, 300, 'كل مزايا الأساسية,المكتبة القانونية,الشؤون المالية,الأرشيف'),
('المؤسسية',   999, 9990,  50, 999, 'كل المزايا,دعم أولوية,تقارير متقدمة,API');

INSERT INTO offices (name, license_number, owner_name, package_id, city, phone, email, subscription_end, status) VALUES
('مكتب الشمري للمحاماة',     'LIC-2024-001', 'أحمد الشمري',  2, 'الرياض', '0501234567', 'ahmed@law.com',   '2024-12-31', 'active'),
('مكتب النجدي والشركاء',     'LIC-2024-002', 'فهد النجدي',   3, 'جدة',    '0512345678', 'info@najdi.com',  '2025-01-31', 'active'),
('مكتب الزهراني للاستشارات', 'LIC-2024-003', 'خالد الزهراني',1, 'الدمام', '0523456789', 'info@zahrani.com','2024-03-31', 'trial'),
('مكتب العتيبي القانوني',    'LIC-2024-004', 'سعد العتيبي',  2, 'الرياض', '0534567890', 'info@otibi.com',  '2024-11-30', 'active'),
('شركة الحربي للمحاماة',     'LIC-2024-005', 'محمد الحربي',  3, 'المدينة','0545678901', 'info@harbi.com',  '2025-03-31', 'active'),
('مكتب الدوسري القانوني',    'LIC-2024-006', 'علي الدوسري',  1, 'الرياض', '0556789012', 'info@dosri.com',  '2024-02-28', 'expired');

UPDATE users SET office_id = 1 WHERE id = 2;

INSERT INTO clients (office_id, full_name, id_number, phone, email, city, client_type) VALUES
(1,'أحمد محمد السالم',  '1052341234','0501234567','ahmed@mail.com',  'الرياض','individual'),
(1,'شركة النخيل التجارية','4030123456','0112345678','info@nakheel.com','جدة',   'company'),
(1,'فاطمة عبدالله',     '1098765432','0551234567','fatima@mail.com', 'الرياض','individual'),
(1,'سلطان المطيري',     '1076543210','0521234567','sultan@mail.com', 'الدمام','individual'),
(1,'مؤسسة الرياض للتجارة','4010654321','0114567890','info@rtrade.com','الرياض','company');

INSERT INTO cases (office_id, case_number, client_id, title, case_type, court_name, status, priority, next_session, fees, paid_amount) VALUES
(1,'2024/1547',1,'نزاع عمالي - شركة الخليج',     'عمالية',       'المحكمة العمالية بالرياض',     'active','high',  '2024-04-15',5000,3500),
(1,'2024/0892',2,'نزاع تجاري - عقود',            'تجارية',       'المحكمة التجارية',             'active','medium','2024-04-20',8000,8000),
(1,'2024/2103',3,'دعوى نفقة وحضانة',             'أحوال شخصية', 'محكمة الأحوال الشخصية',       'active','urgent','2024-04-10',3000,1500),
(1,'2023/4521',4,'نزاع عقاري',                   'عقارية',       'المحكمة العامة',               'closed','low',  NULL,         12000,12000),
(1,'2024/0234',5,'تسوية تجارية',                 'تجارية',       'المحكمة التجارية',             'settled','medium',NULL,        6500,6500);

INSERT INTO sessions (case_id, office_id, session_date, description, status) VALUES
(1,1,'2024-04-15 10:00:00','جلسة مرافعة','scheduled'),
(2,1,'2024-04-20 09:30:00','جلسة حكم','scheduled'),
(3,1,'2024-04-10 11:00:00','جلسة تحقيق','scheduled'),
(1,1,'2024-03-10 10:00:00','جلسة إجراءات','held');

INSERT INTO tasks (office_id, title, assigned_to, priority, status, due_date) VALUES
(1,'إعداد مذكرة دفاعية لقضية النزاع العمالي','محمد الشمري','urgent','in_progress','2024-04-10'),
(1,'مراجعة عقد الإيجار التجاري','سارة العمري','medium','pending','2024-04-15'),
(1,'تقديم طلب استئناف','فهد الحارثي','high','pending','2024-04-12'),
(1,'متابعة صرف الحكم التنفيذي','محمد الشمري','medium','completed','2024-03-30'),
(1,'إعداد وكالة شاملة للعميل','سارة العمري','low','pending','2024-04-20');

INSERT INTO transactions (office_id, case_id, client_id, type, category, amount, description, payment_method, transaction_date) VALUES
(1,1,1,'income','أتعاب قضائية',5000,'أتعاب قضية النزاع العمالي','bank_transfer','2024-03-15'),
(1,NULL,2,'income','استشارة قانونية',1500,'استشارة قانونية','cash','2024-03-14'),
(1,2,NULL,'expense','رسوم قضائية',450,'رسوم تسجيل دعوى','cash','2024-03-13'),
(1,NULL,5,'income','أتعاب عقود',3200,'مراجعة عقود تجارية','check','2024-03-12'),
(1,NULL,NULL,'expense','مصاريف إدارية',800,'مصاريف مكتبية','cash','2024-03-10');

INSERT INTO contracts (office_id, client_id, contract_number, title, contract_type, start_date, end_date, value, status) VALUES
(1,1,'CNT-2024-001','عقد اتفاقية أتعاب محاماة','اتفاقية أتعاب','2024-01-01','2024-12-31',5000,'active'),
(1,2,'CNT-2024-002','عقد تمثيل قانوني','تمثيل قانوني','2024-02-01','2024-12-31',12000,'active'),
(1,5,'CNT-2024-003','عقد استشارات قانونية دورية','استشارات','2024-03-01','2025-02-28',8400,'active');

INSERT INTO poa (office_id, client_id, poa_number, title, granted_to, issue_date, expiry_date, status) VALUES
(1,1,'POA-2024-001','وكالة خصومة وتقاضي','أحمد الشمري المحامي','2024-01-15','2025-01-15','active'),
(1,2,'POA-2024-002','وكالة عامة شاملة','أحمد الشمري المحامي','2024-02-01','2025-02-01','active');

INSERT INTO correspondence (office_id, reference_number, subject, type, from_entity, to_entity, correspondence_date, status) VALUES
(1,'IN-2024-001','طلب الحضور لجلسة 2024/1547','incoming','المحكمة العمالية بالرياض','مكتب الشمري للمحاماة','2024-03-20','read'),
(1,'OUT-2024-001','مذكرة دفاعية - قضية 2024/1547','outgoing','مكتب الشمري للمحاماة','المحكمة العمالية بالرياض','2024-03-25','replied'),
(1,'IN-2024-002','إشعار بموعد الجلسة','incoming','المحكمة التجارية','مكتب الشمري للمحاماة','2024-03-28','new');

INSERT INTO platform_revenue (office_id, package_id, amount, type, payment_date) VALUES
(1,2,499,'subscription','2024-03-01'),
(2,3,999,'subscription','2024-03-01'),
(4,2,499,'subscription','2024-03-01'),
(5,3,999,'subscription','2024-03-01'),
(1,2,499,'renewal','2024-02-01'),
(2,3,999,'renewal','2024-02-01');

INSERT INTO library (office_id, title, category, content, is_public) VALUES
(1,'نظام الإجراءات الجزائية','قوانين','محتوى النظام...', 1),
(1,'نظام العمل ولوائحه التنفيذية','قوانين','محتوى النظام...', 1),
(1,'نموذج لائحة دعوى عمالية','نماذج','محتوى النموذج...', 0),
(NULL,'نظام المحاكم التجارية','قوانين','محتوى النظام...', 1);

INSERT INTO archive (office_id, title, category, file_name, file_size, description) VALUES
(1,'حكم قضية 2023/4521','أحكام','hukm_2023_4521.pdf','1.2 MB','نسخة الحكم النهائي'),
(1,'عقد CNT-2024-001 موقع','عقود','contract_001_signed.pdf','850 KB','نسخة العقد الموقعة'),
(1,'محضر جلسة 2024-03-10','محاضر','session_2024_03_10.pdf','420 KB','محضر جلسة المرافعة');

INSERT INTO notifications (user_id, office_id, title, message, type) VALUES
(2,1,'جلسة غداً','جلسة قضية 2024/2103 غداً الساعة 11 صباحاً','warning'),
(2,1,'مهمة متأخرة','مهمة إعداد المذكرة الدفاعية تجاوزت موعدها','danger'),
(2,1,'عميل جديد','تم إضافة عميل جديد بنجاح','success'),
(1,NULL,'مكتب جديد','مكتب الزهراني للاستشارات اشترك في النسخة التجريبية','info');
