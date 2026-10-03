<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireAdmin();
$page_title = 'الموديولات الإضافية';

/* ── إنشاء الجداول ذاتياً ── */
$conn->query("CREATE TABLE IF NOT EXISTS modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    icon VARCHAR(50) DEFAULT 'puzzle-piece',
    base_price DECIMAL(10,2) DEFAULT 0,
    is_active TINYINT DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS office_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    module_key VARCHAR(60) NOT NULL,
    is_enabled TINYINT DEFAULT 1,
    price DECIMAL(10,2) DEFAULT NULL,
    enabled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    enabled_by INT DEFAULT NULL,
    package_id INT DEFAULT NULL,
    UNIQUE KEY uk_office_mod (office_id, module_key),
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $conn->query("ALTER TABLE office_modules ADD COLUMN package_id INT DEFAULT NULL"); } catch (\Throwable $e) {}

$conn->query("CREATE TABLE IF NOT EXISTS module_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    module_key VARCHAR(60) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    gateway VARCHAR(20) DEFAULT 'free',
    txn_ref VARCHAR(100) DEFAULT NULL,
    purchased_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS module_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    module_key VARCHAR(60) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_by INT DEFAULT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    resolved_by INT DEFAULT NULL,
    notes TEXT,
    amount DECIMAL(10,2) DEFAULT 0,
    gateway VARCHAR(20) DEFAULT 'free',
    txn_ref VARCHAR(100) DEFAULT NULL,
    INDEX idx_office (office_id), INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $conn->query("ALTER TABLE module_requests ADD COLUMN amount DECIMAL(10,2) DEFAULT 0"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE module_requests ADD COLUMN gateway VARCHAR(20) DEFAULT 'free'"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE module_requests ADD COLUMN txn_ref VARCHAR(100) DEFAULT NULL"); } catch (\Throwable $e) {}

$conn->query("CREATE TABLE IF NOT EXISTS deleted_module_keys (
    module_key VARCHAR(60) PRIMARY KEY,
    deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$_deletedKeys = [];
$dkr = $conn->query("SELECT module_key FROM deleted_module_keys");
if ($dkr) while ($dk = $dkr->fetch_assoc()) $_deletedKeys[$dk['module_key']] = true;

/* ── بذرة الموديولات المتاحة (تتخطّى أي مفتاح حذفه الأدمن عمداً — راجع deleted_module_keys) ── */
foreach ([
    ['marketing',     'إدارة التسويق',            'حملات تسويقية، محتوى إعلامي، قنوات تواصل، فرص وعملاء محتملون، تحليلات وقياس نتائج', 'bullhorn',            1],
    ['client_portal', 'بوابة العميل',              'بوابة مستقلة يدخلها عميلك بحسابه الخاص ليشوف قضاياه وفواتيره وعقوده ويتواصل مع مكتبك', 'door-open',           2],
    ['time_tracking', 'تتبّع الوقت والساعات',      'تسجيل ساعات العمل القابلة للفوترة على كل قضية وتحويلها لفواتير تلقائياً',              'stopwatch',           3],
    ['hr',            'الموارد البشرية',           'حضور وانصراف، طلبات إجازة، وتقييم أداء الموظفين',                                     'user-tie',            4],
    ['esignature',    'التوقيع الإلكتروني',        'توقيع العقود والوكالات إلكترونياً بدل الطباعة اليدوية',                                'signature',           5],
    ['ai_legal',      'المساعد القانوني الذكي المتقدم', 'تحليل عقود، بحث قانوني، وتلخيص قضايا بالذكاء الاصطناعي',                          'brain',               6],
    ['bi_dashboard',  'لوحة تحليلات تنفيذية',      'تحليلات أعمق من التقارير الأساسية، مخصّصة لمالك المكتب والشركاء',                     'chart-pie',           7],
    ['multi_branch',  'إدارة الفروع المتعددة',     'لو المكتب عنده أكثر من فرع أو مدينة',                                                 'code-branch',         8],
    ['support_tickets','نظام تذاكر الدعم الفني',   'تذاكر دعم منفصلة لطلبات العملاء ومتابعتها',                                            'headset',             9],
    ['legal_deadlines','المواعيد النظامية والتذكيرات', 'حاسبة مواعيد حرجة (طعن، تقادم) وتذكيرات تجديد العقود والوكالات قبل انتهائها',      'hourglass-half',      10],
    ['referral_network','شبكة الإحالات بين المكاتب', 'تسجيل القضايا المُحالة من ولمكاتب أخرى مع نسبة الأتعاب المتفق عليها',                 'people-arrows',       11],
    ['client_surveys', 'استطلاعات رضا العملاء',      'إرسال استبيان تقييم للعميل بعد إغلاق القضية أو الفاتورة وتتبّع النتائج',              'star-half-stroke',    12],
    ['recurring_billing','الفوترة المتكررة',         'فواتير اشتراك شهري/دوري تلقائية لعملاء الأتعاب الثابتة (Retainer)',                    'arrows-rotate',       13],
    ['calendar_sync',  'مزامنة التقويم',             'رابط تقويم شخصي يعرض جلساتك ومهامك مباشرة في Google Calendar أو Outlook',              'calendar-days',       14],
    ['expert_witness', 'قاعدة بيانات الخبراء والشهود', 'سجل بالخبراء الفنيين والشهود اللي يتعامل معهم المكتب مع ربطهم بالقضايا',            'user-doctor',         15],
    ['financial_approvals', 'سلسلة اعتماد المصروفات', 'مراحل اعتماد للمصروفات قبل صرفها وتسجيلها تلقائياً في الشؤون المالية',                'money-check-dollar',  17],
    ['meeting_rooms',  'حجز قاعات الاجتماعات',       'جدولة وحجز قاعات ومصادر المكتب مع منع تعارض الحجوزات',                                 'door-closed',         18],
    ['regulatory_alerts','تنبيهات التحديثات النظامية', 'لوحة إعلانات يديرها المكتب لمتابعة آخر التحديثات النظامية مع تتبع القراءة',           'triangle-exclamation',19],
    ['reputation_management','إدارة السمعة والتقييمات', 'تسجيل ومتابعة تقييمات العملاء والرد عليها لبناء سمعة أفضل للمكتب',                   'thumbs-up',           20],
    ['conflict_check', 'فحص تعارض المصالح', 'يفحص هل الطرف الآخر في قضية جديدة عميل حالي أو سابق للمكتب أو خصم في قضية سابقة قبل قبولها', 'user-shield', 26],
    ['case_enforcement', 'متابعة تنفيذ الأحكام', 'تتبّع مراحل تنفيذ الحكم بعد صدوره: القيد، الحجز، والتحصيل — بمعزل عن مراحل التقاضي', 'gavel', 27],
    ['client_doc_expiry', 'متتبّع انتهاء وثائق العميل', 'تنبيهات قبل انتهاء صلاحية سجل تجاري أو هوية أو تفويض لأي عميل', 'file-circle-exclamation', 28],
    ['session_prep', 'مذكرة تحضير الجلسة', 'ملخص سريع للقضية وآخر مستجداتها ونقاط الإثارة، جاهز للطباعة قبل كل جلسة', 'file-lines', 29],
    ['session_clash', 'كاشف تعارض المواعيد', 'يحذّرك إن كان نفس المحامي مُسنداً لجلستين متقاربتين في قضيتين مختلفتين', 'calendar-xmark', 30],
    ['case_closure_review', 'تقييم القضية بعد إغلاقها', 'وثّق نتيجة كل قضية مغلقة والدروس المستفادة — أرشيف مرجعي لقضايا مشابهة مستقبلاً', 'clipboard-check', 31],
    ['client_periodic_report', 'تقرير دوري للعميل', 'تقرير مُولَّد يلخّص كل قضايا العميل وحالتها خلال فترة محددة، برابط مباشر يُشارَك معه', 'file-contract', 32],
    ['absence_delegation', 'تفويض غياب مؤقت', 'أثناء الإجازة، تُنقل مهامك المُسندة لزميل تلقائياً خلال فترة محددة ثم ترجع لك', 'right-left', 33],
    ['external_collab', 'بوابة المحامي الاستشاري الخارجي', 'دعوة محامٍ من خارج المكتب للاطّلاع على قضية واحدة وإبداء ملاحظاته بصلاحية مؤقتة', 'user-tie', 34],
    ['data_export', 'مركز التصدير والنسخ الاحتياطي', 'صدّر بيانات مكتبك (عملاء، قضايا، عقود، فواتير) بصيغة CSV — نسخة احتياطية بمعزل عن السحابة', 'database', 35],
    ['fee_quote', 'عروض الأتعاب الإلكترونية', 'عرض سعر رسمي يُرسل كرابط للعميل المحتمل — عند موافقته يُنشأ عقد مبدئي تلقائياً', 'file-invoice', 36],
    ['case_checklists', 'قوائم تحقق القضايا', 'قائمة مستندات وإجراءات مطلوبة لكل قضية حسب نوعها، تُعلَّم بنودها فور إنجازها', 'list-check', 37],
    ['consultation_booking', 'حجز استشارة أولية أونلاين', 'رابط عام يحجز منه عميل محتمل موعد استشارة، تراجعه وتؤكده قبل إضافته للتقويم', 'calendar-plus', 38],
    ['workload_balancer', 'موازن الحمل الوظيفي', 'لوحة تعرض عدد القضايا والمهام المفتوحة لكل محامٍ لتوزيع القضايا الجديدة بتوازن', 'scale-balanced', 39],
    ['fee_shortfall', 'متابعة الأتعاب الناقصة', 'يقارن الأتعاب المتفق عليها بالمحصَّل فعلياً ويفرز القضايا التي فيها عجز تحصيل', 'money-bill-trend-up', 40],
    ['pro_bono_tracker', 'سجل المحاماة المجانية', 'تتبّع منفصل للقضايا المجانية أو المخفَّضة للمسؤولية الاجتماعية أو الحالات الإنسانية', 'hand-holding-heart', 41],
    ['global_search', 'البحث الشامل الموحّد', 'شريط بحث واحد يدوّر في العملاء والقضايا والعقود والفواتير بنتيجة موحّدة', 'magnifying-glass', 42],
    ['client_silence_alert', 'تنبيه العميل الصامت', 'تنبيه للقضايا النشطة التي لم يحدث لها أي تواصل أو تحديث منذ فترة طويلة', 'comment-slash', 43],
    ['lawyer_license_expiry', 'تراخيص المحامين المهنية', 'تتبّع صلاحية عضوية الهيئة ورخصة المزاولة لمحامي المكتب مع تنبيه قبل الانتهاء', 'id-card', 44],
    ['client_source_attribution', 'تتبّع مصدر العميل', 'يسجّل من أين جاء كل عميل ويبني تقرير يوضح أي قناة تسويقية فعلاً تجيب عملاء', 'route', 45],
    ['petition_wizard', 'مولّد صحيفة الدعوى', 'أسئلة موجَّهة عن الأطراف والوقائع والطلبات تُصاغ منها مسودة صحيفة دعوى جاهزة للطباعة', 'scroll', 46],
    ['lawyer_daily_journal', 'دفتر يوميات المحامي', 'سطران يومياً عن أهم ما أنجزه كل محامٍ، يتجمّع بتقرير أسبوعي لصاحب المكتب', 'book-journal-whills', 47],
    ['staff_performance_review', 'تقييم أداء الموظفين الدوري', 'تقييم ربع سنوي لكل موظف بمعايير محددة — أرشيف يساعد بالقرارات الإدارية', 'chart-simple', 48],
    ['case_workflows', 'أتمتة إجراءات القضية', 'خطة مراحل ومهام ومواعيد نظامية تُولَّد تلقائياً لكل نوع قضية، ومهام الاعتراض والتنفيذ عند صدور الحكم', 'diagram-project', 22],
    ['court_sms_import', 'الاستيراد الذكي من رسائل المحكمة', 'الصق رسالة الجلسة أو إشعار ناجز فتُنشأ الجلسة تلقائياً في القضية المطابقة', 'message', 23],
    ['client_action_link', 'رابط إنجاز العميل', 'رابط واحد للعميل بدون حساب: يوقّع، ويطّلع على فاتورته، ويرفع مستنداته، ويوافق', 'link', 24],
    ['ai_case_intake', 'إنشاء القضية من صحيفة الدعوى', 'استخراج الأطراف والمحكمة والمبلغ والتواريخ من نص صحيفة الدعوى أو الحكم وإنشاء القضية بعد مراجعتك', 'file-import', 25],
    ['trust_accounts', 'حسابات الأمانات',             'سجل أموال العملاء المودعة لدى المكتب: إيداعات وسحوبات بأرصدة دقيقة وكشف حساب، والسحب يحتاج اعتماد المدير', 'vault',                21],
] as $seed) {
    if (isset($_deletedKeys[$seed[0]])) continue;
    $k = $conn->real_escape_string($seed[0]);
    $n = $conn->real_escape_string($seed[1]);
    $d = $conn->real_escape_string($seed[2]);
    $ic = $conn->real_escape_string($seed[3]);
    $so = (int)$seed[4];
    $conn->query("INSERT IGNORE INTO modules (module_key,name,description,icon,base_price,sort_order) VALUES ('$k','$n','$d','$ic',0,$so)");
}

/* ── اعتماد / رفض طلب موديول من مكتب ── */
if (isset($_GET['approve_request'])) {
    $rid = (int)$_GET['approve_request'];
    $req = $conn->query("SELECT * FROM module_requests WHERE id=$rid AND status='pending' LIMIT 1")->fetch_assoc();
    if ($req) {
        $mk = $conn->real_escape_string($req['module_key']);
        $oidr = (int)$req['office_id'];
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $amt = (float)($req['amount'] ?? 0);
        // لا نجمّد سعر المكتب عند التفعيل المجاني — نترك السعر بلا تخصيص ليتبع السعر الأساسي للموديول تلقائياً حتى لو رُفِع لاحقاً
        $isFree = ($req['gateway'] ?? 'free') === 'free';
        $priceSql = $isFree ? 'NULL' : $amt;
        $conn->query("INSERT INTO office_modules (office_id,module_key,is_enabled,price,enabled_by) VALUES ($oidr,'$mk',1,$priceSql,".($uid ?: 'NULL').")
            ON DUPLICATE KEY UPDATE is_enabled=1, package_id=NULL, price=$priceSql, enabled_by=".($uid ?: 'NULL'));
        $conn->query("UPDATE module_requests SET status='approved', resolved_at=NOW(), resolved_by=".($uid ?: 'NULL')." WHERE id=$rid");
        // العمليات المدفوعة عبر Paymob سُجِّلت في module_purchases لحظة الدفع — نسجّل هنا فقط التفعيلات المجانية
        if ($isFree) {
            $conn->query("INSERT INTO module_purchases (office_id,module_key,amount,gateway,purchased_by) VALUES ($oidr,'$mk',0,'free',".($uid ?: 'NULL').")");
        }
    }
    header("Location: office_modules.php?msg=saved"); exit;
}
if (isset($_GET['reject_request'])) {
    $rid = (int)$_GET['reject_request'];
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $conn->query("UPDATE module_requests SET status='rejected', resolved_at=NOW(), resolved_by=".($uid ?: 'NULL')." WHERE id=$rid AND status='pending'");
    header("Location: office_modules.php?msg=saved"); exit;
}

/* ── حفظ / تعديل تعريف موديول ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'module') {
    $mid   = (int)($_POST['id'] ?? 0);
    $name  = $conn->real_escape_string(trim($_POST['name'] ?? ''));
    $desc  = $conn->real_escape_string(trim($_POST['description'] ?? ''));
    $icon  = $conn->real_escape_string(trim($_POST['icon'] ?? '') ?: 'puzzle-piece');
    $price = max(0, (float)($_POST['base_price'] ?? 0));
    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($mid) {
        $conn->query("UPDATE modules SET name='$name', description='$desc', icon='$icon', base_price=$price, is_active=$active WHERE id=$mid");
    } else {
        $key = $conn->real_escape_string(trim($_POST['module_key'] ?? ''));
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower($key));
        if ($key !== '') {
            $so = (int)$conn->query("SELECT IFNULL(MAX(sort_order),0)+1 s FROM modules")->fetch_assoc()['s'];
            $conn->query("INSERT IGNORE INTO modules (module_key,name,description,icon,base_price,is_active,sort_order)
                VALUES ('$key','$name','$desc','$icon',$price,$active,$so)");
            $conn->query("DELETE FROM deleted_module_keys WHERE module_key='$key'");
        }
    }
    header("Location: office_modules.php?msg=saved"); exit;
}

/* ── حذف تعريف موديول نهائياً: يعطّله أولاً عند كل المكاتب التي فعّلته، ثم يحذف تعريفه وطلباته المعلّقة ── */
if (isset($_GET['delete_module'])) {
    $mid = (int)$_GET['delete_module'];
    $mk  = $conn->query("SELECT module_key FROM modules WHERE id=$mid")->fetch_assoc()['module_key'] ?? '';
    if ($mk !== '') {
        $mke = $conn->real_escape_string($mk);
        $conn->query("UPDATE office_modules SET is_enabled=0 WHERE module_key='$mke'");
        $conn->query("UPDATE module_requests SET status='rejected', resolved_at=NOW() WHERE module_key='$mke' AND status='pending'");
        $conn->query("INSERT IGNORE INTO deleted_module_keys (module_key) VALUES ('$mke')");
    }
    $conn->query("DELETE FROM modules WHERE id=$mid");
    header("Location: office_modules.php?msg=deleted"); exit;
}

/* ── تبديل تفعيل موديول لمكتب معيّن (AJAX) ── */
if (isset($_POST['toggle_office_module'])) {
    header('Content-Type: application/json; charset=utf-8');
    $oid = (int)($_POST['office_id'] ?? 0);
    $mk  = $conn->real_escape_string($_POST['toggle_office_module']);
    $val = ($_POST['active_val'] ?? '') === '1' ? 1 : 0;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!$oid || $mk === '') { echo json_encode(['ok'=>false]); exit; }
    $conn->query("INSERT INTO office_modules (office_id,module_key,is_enabled,enabled_by)
        VALUES ($oid,'$mk',$val,".($uid ?: 'NULL').")
        ON DUPLICATE KEY UPDATE is_enabled=$val" . ($val ? ", package_id=NULL" : "") . ", enabled_by=".($uid ?: 'NULL'));
    echo json_encode(['ok'=>true,'enabled'=>$val]); exit;
}

/* ── حفظ سعر مخصّص لموديول عند مكتب معيّن (AJAX) ── */
if (isset($_POST['save_office_price'])) {
    header('Content-Type: application/json; charset=utf-8');
    $oid = (int)($_POST['office_id'] ?? 0);
    $mk  = $conn->real_escape_string($_POST['save_office_price']);
    $price = $_POST['price'] === '' ? 'NULL' : max(0, (float)$_POST['price']);
    if (!$oid || $mk === '') { echo json_encode(['ok'=>false]); exit; }
    $conn->query("INSERT INTO office_modules (office_id,module_key,is_enabled,price)
        VALUES ($oid,'$mk',1,$price)
        ON DUPLICATE KEY UPDATE price=$price");
    echo json_encode(['ok'=>true]); exit;
}

/* ── تحميل البيانات ── */
require_once '../includes/module_helper.php';
$_featLbl = store_feature_label_map($conn);
$modules_res = $conn->query("SELECT * FROM modules ORDER BY sort_order, id");
$modules = [];
while ($m = $modules_res->fetch_assoc()) $modules[] = $m;

$where = "1=1";
if (!empty($_GET['q'])) {
    $q = $conn->real_escape_string($_GET['q']);
    $where .= " AND (o.name LIKE '%$q%' OR o.city LIKE '%$q%' OR o.owner_name LIKE '%$q%')";
}
$offices = $conn->query("SELECT o.id, o.name, o.city, o.owner_name, o.status, p.name pkg_name
    FROM offices o LEFT JOIN packages p ON o.package_id=p.id
    WHERE $where ORDER BY o.name LIMIT 300");

$om_res = $conn->query("SELECT om.office_id, om.module_key, (om.is_enabled=1 AND (om.package_id IS NULL OR om.package_id=o.package_id)) AS is_enabled, om.price
    FROM office_modules om LEFT JOIN offices o ON o.id=om.office_id");
$om_map = [];
while ($r = $om_res->fetch_assoc()) $om_map[$r['office_id']][$r['module_key']] = ['enabled'=>(int)$r['is_enabled'], 'price'=>$r['price']];
// موديولات مضمّنة في باقة المكتب (mod_<key> = 1) — تُحتسب مفعّلة ما لم تكن مفعّلة أصلاً بشكل مستقل
$pkgq = $conn->query("SELECT o.id office_id, SUBSTRING(pf.feature_key,5) module_key
    FROM offices o JOIN package_features pf ON pf.package_id=o.package_id
    WHERE pf.feature_key LIKE 'mod\_%' AND pf.feature_value='1'");
if ($pkgq) while ($r = $pkgq->fetch_assoc()) {
    if (empty($om_map[$r['office_id']][$r['module_key']]['enabled'])) {
        $om_map[$r['office_id']][$r['module_key']] = ['enabled'=>1, 'price'=>null, 'via_pkg'=>1];
    }
}

$purchases = [];
$puq = $conn->query("SELECT mp.*, o.name office_name, m.name module_name
    FROM module_purchases mp
    LEFT JOIN offices o ON mp.office_id=o.id
    LEFT JOIN modules m ON mp.module_key=m.module_key
    ORDER BY mp.created_at DESC LIMIT 100");
if ($puq) while ($r = $puq->fetch_assoc()) $purchases[] = $r;
$total_revenue = 0; foreach ($purchases as $pu) $total_revenue += (float)$pu['amount'];

$pending_requests = [];
$prq = $conn->query("SELECT mr.*, o.name office_name, m.name module_name, m.icon module_icon
    FROM module_requests mr
    LEFT JOIN offices o ON mr.office_id=o.id
    LEFT JOIN modules m ON mr.module_key=m.module_key
    WHERE mr.status='pending' ORDER BY mr.requested_at ASC");
if ($prq) while ($r = $prq->fetch_assoc()) $pending_requests[] = $r;

$edit_m = null;
if (isset($_GET['edit_module'])) $edit_m = $conn->query("SELECT * FROM modules WHERE id=".(int)$_GET['edit_module'])->fetch_assoc();

include '../includes/admin_header.php';
?>

<style>
.om-toggle { width:40px; height:22px; border-radius:50px; cursor:pointer; position:relative; transition:background .2s; flex-shrink:0; }
.om-toggle-knob { width:16px; height:16px; background:#fff; border-radius:50%; position:absolute; top:3px; transition:left .2s; box-shadow:0 1px 3px rgba(0,0,0,.25); }
.om-mod-row { display:flex; align-items:center; gap:12px; padding:12px 4px; border-bottom:1px solid #f1f5f9; }
.om-mod-row:last-child { border-bottom:none; }
.om-mod-ico { width:36px; height:36px; border-radius:9px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
</style>

<div class="mk-page-hdr mb-4">
  <div>
    <div class="mk-page-title"><i class="fas fa-puzzle-piece"></i> الموديولات الإضافية</div>
    <div class="mk-page-sub">موديولات تُفعَّل يدوياً لمكاتب معيّنة — إضافة فوق باقتهم الحالية، بغضّ النظر عن نوعها</div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modDefModal" onclick="newModuleDef()">
    <i class="fas fa-plus me-1"></i>موديول جديد
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center text-success" style="width:44px;height:44px;font-size:18px;background:#f0fdf4"><i class="fas fa-coins"></i></div>
      <div><div class="fw-bold" style="font-size:18px"><?= number_format($total_revenue,0) ?> ر.س</div><div class="text-muted" style="font-size:11px">إجمالي مبيعات الموديولات</div></div>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center text-primary" style="width:44px;height:44px;font-size:18px;background:#eff6ff"><i class="fas fa-receipt"></i></div>
      <div><div class="fw-bold" style="font-size:18px"><?= count($purchases) ?></div><div class="text-muted" style="font-size:11px">عملية تفعيل/شراء</div></div>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center text-warning" style="width:44px;height:44px;font-size:18px;background:#fffbeb"><i class="fas fa-inbox"></i></div>
      <div><div class="fw-bold" style="font-size:18px"><?= count($pending_requests) ?></div><div class="text-muted" style="font-size:11px">طلبات معلّقة</div></div>
    </div></div>
  </div>
</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($pending_requests): ?>
<div class="card mb-4" style="border-color:#fde68a">
  <div class="card-header" style="background:#fffbeb"><i class="fas fa-inbox me-2 text-warning"></i>طلبات موديولات من المكاتب (<?= count($pending_requests) ?>)</div>
  <div class="card-body p-0">
    <?php foreach ($pending_requests as $r): ?>
    <div class="om-mod-row px-3">
      <div class="om-mod-ico"><i class="fas fa-<?= e($r['module_icon'] ?: 'puzzle-piece') ?>"></i></div>
      <div class="flex-grow-1">
        <div class="fw-semibold"><?= e($r['module_name'] ?: ($_featLbl[$r['module_key']] ?? $r['module_key'])) ?></div>
        <div class="text-muted" style="font-size:12px">طلبه مكتب: <b><?= e($r['office_name'] ?: '—') ?></b> — <?= dDate($r['requested_at'], true) ?></div>
        <?php if ((float)($r['amount'] ?? 0) > 0): ?>
        <div style="font-size:12px"><span class="badge bg-success bg-opacity-10 text-success"><i class="fas fa-check me-1"></i>مدفوع مسبقاً: <?= number_format((float)$r['amount'],2) ?> ر.س عبر <?= e($r['gateway']) ?></span></div>
        <?php else: ?>
        <div style="font-size:12px"><span class="badge bg-secondary bg-opacity-10 text-secondary">طلب تفعيل مجاني</span></div>
        <?php endif; ?>
      </div>
      <a href="office_modules.php?approve_request=<?= $r['id'] ?>" class="btn btn-sm btn-success" onclick="return confirm('تفعيل هذا الموديول لهذا المكتب؟')"><i class="fas fa-check me-1"></i>اعتماد</a>
      <a href="office_modules.php?reject_request=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('رفض هذا الطلب؟')"><i class="fas fa-xmark me-1"></i>رفض</a>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($purchases): ?>
<div class="card mb-4">
  <div class="card-header"><i class="fas fa-receipt me-2 text-success"></i>سجل الشراء والتفعيل (آخر <?= count($purchases) ?>)</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>المكتب</th><th>الموديول</th><th>المبلغ</th><th>طريقة الدفع</th><th>التاريخ</th></tr></thead>
        <tbody>
        <?php foreach ($purchases as $pu): ?>
        <tr>
          <td class="fw-semibold"><?= e($pu['office_name'] ?: '—') ?></td>
          <td><?= e($pu['module_name'] ?: ($_featLbl[$pu['module_key']] ?? $pu['module_key'])) ?></td>
          <td><?= $pu['amount'] > 0 ? number_format((float)$pu['amount'],2).' ر.س' : '<span class="text-muted">مجاني</span>' ?></td>
          <td><?= $pu['gateway']==='free' ? '<span class="badge bg-secondary">تفعيل مجاني</span>' : '<span class="badge bg-primary">'.e($pu['gateway']).'</span>' ?></td>
          <td style="font-size:12px"><?= dDate($pu['created_at'], true) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- الموديولات المسجّلة -->
<div class="card mb-4">
  <div class="card-header"><i class="fas fa-list-ul me-2 text-primary"></i>الموديولات المسجّلة (<?= count($modules) ?>)</div>
  <div class="card-body p-0">
    <?php if (!$modules): ?>
    <div class="text-center text-muted py-4">لا توجد موديولات بعد</div>
    <?php else: foreach ($modules as $m): ?>
    <div class="om-mod-row px-3">
      <div class="om-mod-ico"><i class="fas fa-<?= e($m['icon']) ?>"></i></div>
      <div class="flex-grow-1">
        <div class="fw-semibold">
          <?= e($m['name']) ?>
          <span class="badge bg-light text-muted font-monospace" style="font-size:10px"><?= e($m['module_key']) ?></span>
          <?php if (!$m['is_active']): ?><span class="badge bg-secondary" style="font-size:10px">غير متاح للتفعيل</span><?php endif; ?>
        </div>
        <?php if ($m['description']): ?><div class="text-muted" style="font-size:12px"><?= e($m['description']) ?></div><?php endif; ?>
      </div>
      <div class="text-muted" style="font-size:12px;white-space:nowrap">
        السعر الأساسي: <b><?= number_format((float)$m['base_price'],2) ?></b> ر.س/سنة
      </div>
      <button type="button" class="btn btn-sm btn-outline-primary" onclick='editModuleDef(<?= json_encode($m, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
      <a href="office_modules.php?delete_module=<?= $m['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا الموديول نهائياً؟ سيُعطَّل تلقائياً عند كل المكاتب المفعَّل لديها.')"><i class="fas fa-trash"></i></a>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- تخصيص الموديولات للمكاتب -->
<div class="card">
  <div class="card-header border-bottom-0 pb-0">
    <div class="fw-semibold mb-3"><i class="fas fa-building me-2 text-primary"></i>تخصيص الموديولات لكل مكتب</div>
    <form method="GET" class="d-flex gap-2 flex-wrap mb-3">
      <input type="text" name="q" class="form-control form-control-sm" style="max-width:280px" placeholder="بحث بالاسم أو المدينة أو المالك..." value="<?= e($_GET['q'] ?? '') ?>">
      <button class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
      <a href="office_modules.php" class="btn btn-outline-secondary btn-sm">إعادة</a>
    </form>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>المكتب</th><th>الباقة</th><th>المدينة</th><th>الحالة</th><th>الموديولات المفعّلة</th><th></th></tr></thead>
        <tbody>
        <?php if (!$offices || $offices->num_rows === 0): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">لا توجد مكاتب مطابقة</td></tr>
        <?php else: while ($o = $offices->fetch_assoc()):
          $enabled_here = [];
          foreach ($modules as $m) {
              if (!empty($om_map[$o['id']][$m['module_key']]['enabled'])) $enabled_here[] = $m['name'];
          }
        ?>
        <tr>
          <td class="fw-semibold"><?= e($o['name']) ?><br><small class="text-muted"><?= e($o['owner_name']) ?></small></td>
          <td><?= e($o['pkg_name'] ?: '—') ?></td>
          <td><?= e($o['city']) ?></td>
          <td><?= statusBadge($o['status']) ?></td>
          <td style="font-size:12px">
            <?php if ($enabled_here): ?>
              <?php foreach ($enabled_here as $n): ?><span class="badge bg-success bg-opacity-10 text-success me-1"><?= e($n) ?></span><?php endforeach; ?>
            <?php else: ?><span class="text-muted">لا يوجد</span><?php endif; ?>
          </td>
          <td>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#officeModModal"
                    onclick='openOfficeMod(<?= (int)$o["id"] ?>, <?= json_encode($o["name"]) ?>)'>
              <i class="fas fa-puzzle-piece me-1"></i>الموديولات
            </button>
          </td>
        </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: تعريف/تعديل موديول -->
<div class="modal fade" id="modDefModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modDefTitle"><i class="fas fa-puzzle-piece me-2"></i>موديول جديد</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="form_type" value="module">
        <input type="hidden" name="id" id="mod_id" value="">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">مفتاح الموديول (رمز فريد بالإنجليزية) *</label>
            <input type="text" name="module_key" id="mod_key" class="form-control font-monospace" placeholder="marketing" required pattern="[a-z0-9_]+">
            <div class="form-text">لا يمكن تعديله بعد الإنشاء</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">الاسم المعروض *</label>
            <input type="text" name="name" id="mod_name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">وصف مختصر</label>
            <input type="text" name="description" id="mod_desc" class="form-control">
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">أيقونة (Font Awesome بدون fa-)</label>
              <input type="text" name="icon" id="mod_icon" class="form-control" placeholder="bullhorn">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">السعر الأساسي (ر.س/سنة)</label>
              <input type="number" name="base_price" id="mod_price" class="form-control" min="0" step="0.01" value="0">
              <div class="form-text">مرجعي فقط الآن — يمكن تخصيصه لكل مكتب لاحقاً. صار سعراً سنوياً (كان شهرياً) — راجع القيم الحالية.</div>
            </div>
          </div>
          <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" name="is_active" id="mod_active" checked>
            <label class="form-check-label" for="mod_active">متاح للتفعيل عند المكاتب</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: موديولات مكتب معيّن -->
<div class="modal fade" id="officeModModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-building me-2"></i>موديولات — <span id="omOfficeName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="omBody">
        <!-- تُملأ عبر JS -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إغلاق</button>
      </div>
    </div>
  </div>
</div>

<script>
var MODULES_LIST = <?= json_encode($modules, JSON_UNESCAPED_UNICODE) ?>;
var OM_MAP = <?= json_encode($om_map, JSON_UNESCAPED_UNICODE) ?>;

function newModuleDef() {
  document.getElementById('modDefTitle').innerHTML = '<i class="fas fa-puzzle-piece me-2"></i>موديول جديد';
  document.getElementById('mod_id').value = '';
  document.getElementById('mod_key').value = '';
  document.getElementById('mod_key').disabled = false;
  document.getElementById('mod_name').value = '';
  document.getElementById('mod_desc').value = '';
  document.getElementById('mod_icon').value = '';
  document.getElementById('mod_price').value = '0';
  document.getElementById('mod_active').checked = true;
}
function editModuleDef(m) {
  document.getElementById('modDefTitle').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل موديول';
  document.getElementById('mod_id').value = m.id;
  document.getElementById('mod_key').value = m.module_key;
  document.getElementById('mod_key').disabled = true;
  document.getElementById('mod_name').value = m.name;
  document.getElementById('mod_desc').value = m.description || '';
  document.getElementById('mod_icon').value = m.icon || '';
  document.getElementById('mod_price').value = m.base_price;
  document.getElementById('mod_active').checked = m.is_active == 1;
  new bootstrap.Modal(document.getElementById('modDefModal')).show();
}

function openOfficeMod(officeId, officeName) {
  document.getElementById('omOfficeName').textContent = officeName;
  var body = document.getElementById('omBody');
  body.innerHTML = '';
  if (!MODULES_LIST.length) { body.innerHTML = '<div class="text-muted text-center py-3">لا توجد موديولات مسجّلة بعد</div>'; return; }
  MODULES_LIST.forEach(function (m) {
    var st = (OM_MAP[officeId] && OM_MAP[officeId][m.module_key]) || { enabled: 0, price: null };
    var row = document.createElement('div');
    row.className = 'd-flex align-items-center gap-2 py-2 border-bottom';
    if (st.via_pkg) { // مضمّن في باقة المكتب: يُدار من صفحة الباقات لا من هنا
      row.innerHTML =
        '<div class="flex-grow-1"><div class="fw-semibold" style="font-size:13px"><i class="fas fa-' + m.icon + ' me-1 text-primary"></i>' + m.name + '</div></div>' +
        '<span class="badge bg-success bg-opacity-10 text-success"><i class="fas fa-box-open me-1"></i>مضمّن في الباقة</span>';
      body.appendChild(row);
      return;
    }
    row.innerHTML =
      '<div class="flex-grow-1">' +
        '<div class="fw-semibold" style="font-size:13px"><i class="fas fa-' + m.icon + ' me-1 text-primary"></i>' + m.name + '</div>' +
      '</div>' +
      '<input type="number" class="form-control form-control-sm om-price-inp" style="width:90px" placeholder="' + (m.base_price > 0 ? m.base_price : 'مجاني') + '" value="' + (st.price !== null ? st.price : '') + '" min="0" step="0.01">' +
      '<div class="om-toggle" data-enabled="' + st.enabled + '" style="background:' + (st.enabled ? '#16a34a' : '#cbd5e1') + '">' +
        '<div class="om-toggle-knob" style="left:' + (st.enabled ? '21px' : '3px') + '"></div>' +
      '</div>';
    var toggleEl = row.querySelector('.om-toggle');
    var priceInp = row.querySelector('.om-price-inp');
    toggleEl.addEventListener('click', function () {
      var was = toggleEl.dataset.enabled === '1';
      var now = was ? 0 : 1;
      toggleEl.dataset.enabled = now;
      toggleEl.style.background = now ? '#16a34a' : '#cbd5e1';
      toggleEl.querySelector('.om-toggle-knob').style.left = now ? '21px' : '3px';
      OM_MAP[officeId] = OM_MAP[officeId] || {};
      OM_MAP[officeId][m.module_key] = OM_MAP[officeId][m.module_key] || {};
      OM_MAP[officeId][m.module_key].enabled = now;
      var fd = new FormData();
      fd.append('toggle_office_module', m.module_key);
      fd.append('office_id', officeId);
      fd.append('active_val', now ? '1' : '0');
      fetch('office_modules.php', { method: 'POST', body: fd }).catch(function(){});
    });
    priceInp.addEventListener('change', function () {
      OM_MAP[officeId] = OM_MAP[officeId] || {};
      OM_MAP[officeId][m.module_key] = OM_MAP[officeId][m.module_key] || {};
      OM_MAP[officeId][m.module_key].price = priceInp.value;
      var fd = new FormData();
      fd.append('save_office_price', m.module_key);
      fd.append('office_id', officeId);
      fd.append('price', priceInp.value);
      fetch('office_modules.php', { method: 'POST', body: fd }).catch(function(){});
    });
    body.appendChild(row);
  });
}
</script>

<?php include '../includes/admin_footer.php'; ?>
