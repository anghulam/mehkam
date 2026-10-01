<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/zatca_helper.php';
requireOffice();
if (!can('services', 'view')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'الخدمات الرقمية';
$oid = (int) $_SESSION['office_id'];

if (!hasFeature($conn, $oid, 'has_digital_services')) {
    header("Location: profile.php?tab=upgrade&feature=digital_services"); exit;
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
$uname = $_SESSION['full_name'] ?? '';
// فتح نافذة «طلب خدمة جديد» مباشرة مع تحديد العميل مسبقاً — من ملف العميل (client_file.php)
$_new_for_client = (int)($_GET['client_id'] ?? 0);
$_new_client_row = $_new_for_client ? $conn->query("SELECT id,full_name,id_number,phone,city FROM clients WHERE id=$_new_for_client AND office_id=$oid LIMIT 1")->fetch_assoc() : null;
// صلاحية مستقلة لإدارة كتالوج الخدمات (إضافة/تعديل/حذف خدمة أو نوع) — منفصلة عن صلاحية
// «إضافة» طلب خدمة لعميل. مالك المكتب/الأدمن يملكها دائماً تلقائياً عبر can()، وأي موظف
// غيرهم يحتاج تفعيل «اعتماد» له صراحة تحت قسم «الخدمات الرقمية» من صفحة صلاحيات المستخدمين.
$_canManageCatalog = can('services', 'approve');

/* ── جداول (إنشاء آمن) ── */
try {
    $conn->query("CREATE TABLE IF NOT EXISTS office_services (
        id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL,
        name VARCHAR(300) NOT NULL, description TEXT DEFAULT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0, vat_rate DECIMAL(5,2) NOT NULL DEFAULT 15,
        is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_office (office_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS service_requests (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS office_service_types (
        id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL,
        name VARCHAR(150) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_office (office_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE office_services ADD COLUMN type_id INT DEFAULT NULL"); } catch (\Throwable $e) {}

// ── تأكيد أعمدة الفواتير/الحركات المطلوبة للفوترة التلقائية ──
if (function_exists('zatca_migrate')) { try { zatca_migrate($conn); } catch (\Throwable $e) {} }
foreach ([
    "ALTER TABLE transactions ADD COLUMN invoice_id INT DEFAULT NULL",
    "ALTER TABLE transactions ADD COLUMN source ENUM('manual','invoice') DEFAULT 'manual'",
    "ALTER TABLE transactions ADD COLUMN case_id INT DEFAULT NULL",
    "ALTER TABLE invoices ADD COLUMN direction ENUM('income','expense') DEFAULT 'income'",
    "ALTER TABLE invoices ADD COLUMN discount_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed'",
    "ALTER TABLE invoices ADD COLUMN discount_value DECIMAL(12,2) NOT NULL DEFAULT 0",
    "ALTER TABLE invoices ADD COLUMN public_token VARCHAR(40) DEFAULT NULL",
] as $_c) { try { $conn->query($_c); } catch (\Throwable $e) {} }

$VAT = 15.0;

/**
 * ينشئ فاتورة + قيد إيراد لطلب خدمة (إن لم يكن مربوطاً بفاتورة).
 * $collected=true → الفاتورة «مدفوعة» + قيد في الإيرادات فوراً.
 * $collected=false → الفاتورة «مُرسلة» (مستحق)، ويُنشأ القيد عند وضعها «مدفوعة» من الفواتير.
 * @return array [invoice_id, error]
 */
function svc_make_invoice($conn, $oid, int $reqId, bool $collected = true): array
{
    $r = $conn->query("SELECT * FROM service_requests WHERE id=$reqId AND office_id=$oid")->fetch_assoc();
    if (!$r) return [0, 'الطلب غير موجود'];
    if (!empty($r['invoice_id'])) return [(int) $r['invoice_id'], ''];

    $setg   = $conn->query("SELECT tax_number, invoice_prefix FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?: [];
    $office = $conn->query("SELECT name FROM offices WHERE id=$oid")->fetch_assoc();
    $prefix = $setg['invoice_prefix'] ?? 'INV';
    $invNum = next_invoice_num($conn, $oid, $prefix);
    $ptok   = bin2hex(random_bytes(16));
    $uuid   = $conn->real_escape_string(function_exists('zatca_uuid') ? zatca_uuid() : bin2hex(random_bytes(8)));

    $amount = round((float) $r['amount'], 2);
    $vat    = round((float) $r['vat_amount'], 2);
    $total  = round((float) $r['total'], 2);
    $rate   = $amount > 0 ? round($vat / $amount * 100, 2) : 15;
    $cid    = (int) $r['client_id'] ?: 'NULL';
    $case   = (int) $r['case_id'] ?: 'NULL';
    $tdy    = date('Y-m-d');
    $status = $collected ? 'paid' : 'sent';
    $paidSql = $collected ? "'$tdy'" : 'NULL';

    $qr = '';
    if (!empty($setg['tax_number']) && function_exists('zatca_qr')) {
        try { $qr = $conn->real_escape_string(zatca_qr($office['name'] ?? '', $setg['tax_number'], $total, $vat, $tdy . 'T00:00:00Z')); } catch (\Throwable $e) { $qr = ''; }
    }
    $itemsEsc = $conn->real_escape_string(json_encode([['description' => $r['service_name'], 'qty' => 1, 'price' => $amount]], JSON_UNESCAPED_UNICODE));
    $titleEsc = $conn->real_escape_string('خدمة رقمية: ' . $r['service_name']);
    $seller   = $conn->real_escape_string($setg['tax_number'] ?? '');

    $invId = 0; $error = '';
    try {
        $conn->query("INSERT INTO invoices
            (office_id,client_id,case_id,invoice_number,title,items,
             subtotal,discount,discount_type,discount_value,tax_rate,tax_amount,total,status,paid_date,due_date,notes,direction,
             uuid,public_token,invoice_type,issue_date,supply_date,buyer_vat,seller_vat,qr_data,zatca_status)
            VALUES ($oid,$cid,$case,'$invNum','$titleEsc','$itemsEsc',
                    $amount,0,'fixed',0,$rate,$vat,$total,'$status',$paidSql,NULL,'','income',
                    '$uuid','$ptok','simplified','$tdy','$tdy','','$seller','$qr','" . ($qr ? 'valid' : 'draft') . "')");
        $invId = (int) $conn->insert_id;
    } catch (\Throwable $e1) {
        try {
            $conn->query("INSERT INTO invoices
                (office_id,client_id,case_id,invoice_number,title,items,subtotal,tax_rate,tax_amount,total,status,direction,issue_date,public_token)
                VALUES ($oid,$cid,$case,'$invNum','$titleEsc','$itemsEsc',$amount,$rate,$vat,$total,'$status','income','$tdy','$ptok')");
            $invId = (int) $conn->insert_id;
        } catch (\Throwable $e2) { $error = $e2->getMessage(); }
    }

    if ($invId) {
        $conn->query("UPDATE service_requests SET invoice_id=$invId WHERE id=$reqId");
        if ($collected) {
            $descEsc = $conn->real_escape_string('خدمة رقمية: ' . mb_substr($r['service_name'], 0, 80) . ' — طلب #' . $reqId);
            try {
                $conn->query("INSERT INTO transactions
                    (office_id,type,category,amount,description,payment_method,transaction_date,client_id,case_id,invoice_id,source)
                    VALUES ($oid,'income','خدمات رقمية',$total,'$descEsc','cash',CURDATE(),$cid,$case,$invId,'invoice')");
            } catch (\Throwable $e) {}
        }
    }
    return [$invId, $error];
}

/* ════════ AJAX: استعلام عن عميل ════════ */
if (isset($_GET['lookup'])) {
    header('Content-Type: application/json; charset=utf-8');
    $num = preg_replace('/\s+/', '', trim((string) $_GET['lookup']));
    if ($num === '') { echo json_encode(['found' => false]); exit; }
    $q = $conn->real_escape_string($num);
    $row = $conn->query("SELECT id, full_name, client_type, id_number, phone, email, city
        FROM clients WHERE office_id=$oid AND (id_number='$q' OR cr_number='$q' OR unified_number='$q')
        LIMIT 1")->fetch_assoc();
    if (!$row) { echo json_encode(['found' => false]); exit; }
    $cid = (int) $row['id'];
    $cases = [];
    $cr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid AND client_id=$cid ORDER BY id DESC");
    if ($cr) while ($c = $cr->fetch_assoc()) $cases[] = $c;
    echo json_encode(['found' => true, 'client' => $row, 'cases' => $cases], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ════════ POST ════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ft = $_POST['form_type'] ?? '';

    /* ─── كتالوج الخدمات (يتطلب صلاحية إدارة كتالوج الخدمات) ─── */
    if ($ft === 'catalog_save') {
        if (!$_canManageCatalog) { header("Location: digital_services.php?tab=catalog&msg=denied"); exit; }
        $sid   = (int) ($_POST['id'] ?? 0);
        $name  = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $desc  = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $priceIncl = round((float) ($_POST['price'] ?? 0), 2); // السعر الذي يدخله المكتب — شامل الضريبة
        $tid   = (int) ($_POST['type_id'] ?? 0);
        if ($tid && !$conn->query("SELECT id FROM office_service_types WHERE id=$tid AND office_id=$oid")->num_rows) $tid = 0;
        $tidSql = $tid ?: 'NULL';
        if ($name === '' || $priceIncl < 0) {
            header("Location: digital_services.php?tab=catalog&msg=invalid"); exit;
        }
        // نخزّن داخلياً السعر الأساسي قبل الضريبة كما في بقية النظام (الفواتير وحساب ض.ق.م)
        $rate = $VAT;
        if ($sid) {
            $existingRate = $conn->query("SELECT vat_rate FROM office_services WHERE id=$sid AND office_id=$oid")->fetch_assoc();
            if ($existingRate && $existingRate['vat_rate']) $rate = (float) $existingRate['vat_rate'];
        }
        $price = round($priceIncl / (1 + $rate / 100), 2);
        if ($sid) {
            $ok = $conn->query("UPDATE office_services SET name='$name', description='$desc', price=$price, type_id=$tidSql
                WHERE id=$sid AND office_id=$oid");
        } else {
            $ok = $conn->query("INSERT INTO office_services (office_id,name,description,price,vat_rate,type_id)
                VALUES ($oid,'$name','$desc',$price,$VAT,$tidSql)");
        }
        if (!$ok) {
            header("Location: digital_services.php?tab=catalog&msg=dberror&detail=" . urlencode(mb_substr($conn->error, 0, 200))); exit;
        }
        header("Location: digital_services.php?tab=catalog&msg=saved"); exit;
    }

    /* ─── أنواع الخدمات — قائمة تُدار من إعدادات الخدمات الرقمية (نفس صلاحية إدارة الكتالوج) ─── */
    if ($ft === 'type_save') {
        if (!$_canManageCatalog) { header("Location: digital_services.php?tab=types&msg=denied"); exit; }
        $tid  = (int) ($_POST['id'] ?? 0);
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        if ($name === '') { header("Location: digital_services.php?tab=types&msg=invalid"); exit; }
        if ($tid) {
            $ok = $conn->query("UPDATE office_service_types SET name='$name' WHERE id=$tid AND office_id=$oid");
        } else {
            $ok = $conn->query("INSERT INTO office_service_types (office_id,name) VALUES ($oid,'$name')");
        }
        if (!$ok) { header("Location: digital_services.php?tab=types&msg=dberror&detail=" . urlencode(mb_substr($conn->error, 0, 200))); exit; }
        header("Location: digital_services.php?tab=types&msg=saved"); exit;
    }
    if ($ft === 'type_toggle') {
        if (!$_canManageCatalog) { header("Location: digital_services.php?tab=types&msg=denied"); exit; }
        $tid = (int) ($_POST['id'] ?? 0);
        $conn->query("UPDATE office_service_types SET is_active = 1 - is_active WHERE id=$tid AND office_id=$oid");
        header("Location: digital_services.php?tab=types"); exit;
    }
    if ($ft === 'type_delete') {
        if (!$_canManageCatalog) { header("Location: digital_services.php?tab=types&msg=denied"); exit; }
        $tid = (int) ($_POST['id'] ?? 0);
        // فكّ ارتباط أي خدمة بهذا النوع قبل حذفه بدل تركها بمرجع معلَّق
        $conn->query("UPDATE office_services SET type_id=NULL WHERE type_id=$tid AND office_id=$oid");
        $conn->query("DELETE FROM office_service_types WHERE id=$tid AND office_id=$oid");
        header("Location: digital_services.php?tab=types&msg=deleted"); exit;
    }
    if ($ft === 'catalog_toggle') {
        if (!$_canManageCatalog) { header("Location: digital_services.php?tab=catalog&msg=denied"); exit; }
        $sid = (int) ($_POST['id'] ?? 0);
        $ok = $conn->query("UPDATE office_services SET is_active = 1 - is_active WHERE id=$sid AND office_id=$oid");
        if (!$ok) { header("Location: digital_services.php?tab=catalog&msg=dberror&detail=" . urlencode(mb_substr($conn->error, 0, 200))); exit; }
        header("Location: digital_services.php?tab=catalog"); exit;
    }
    if ($ft === 'catalog_delete') {
        if (!$_canManageCatalog) { header("Location: digital_services.php?tab=catalog&msg=denied"); exit; }
        $sid = (int) ($_POST['id'] ?? 0);
        $ok = $conn->query("DELETE FROM office_services WHERE id=$sid AND office_id=$oid");
        if (!$ok) { header("Location: digital_services.php?tab=catalog&msg=dberror&detail=" . urlencode(mb_substr($conn->error, 0, 200))); exit; }
        header("Location: digital_services.php?tab=catalog&msg=deleted"); exit;
    }

    /* ─── تحديث حالة طلب ─── */
    if ($ft === 'req_status' && can('services', 'edit')) {
        $rid = (int) ($_POST['id'] ?? 0);
        $st  = in_array($_POST['status'] ?? '', ['new', 'in_progress', 'completed', 'cancelled'], true) ? $_POST['status'] : 'new';
        $conn->query("UPDATE service_requests SET status='$st' WHERE id=$rid AND office_id=$oid");
        $_retTo = trim($_POST['return_to'] ?? '');
        if (!preg_match('/^[a-z_]+\.php(\?[a-z0-9_=&%.\-]*)?$/i', $_retTo)) $_retTo = '';
        if ($_retTo !== '') { header("Location: " . $_retTo . (str_contains($_retTo,'?')?'&':'?') . "msg=status"); exit; }
        header("Location: digital_services.php?msg=status"); exit;
    }

    /* ─── طلب خدمة جديد ─── */
    if ($ft === 'request' && can('services', 'add')) {
        $err = '';
        $svcId = (int) ($_POST['service_id'] ?? 0);
        $svc = $conn->query("SELECT id,name,price,vat_rate FROM office_services
            WHERE id=$svcId AND office_id=$oid AND is_active=1")->fetch_assoc();
        if (!$svc) $err = 'اختر خدمة صحيحة.';

        // العميل: موجود أو جديد
        $clientId = (int) ($_POST['client_id'] ?? 0);
        if (!$err && !$clientId) {
            $cname = trim($_POST['new_full_name'] ?? '');
            $cidn  = trim($_POST['new_id_number'] ?? '');
            if ($cname === '' || $cidn === '') {
                $err = 'أدخل اسم العميل ورقم الهوية/السجل.';
            } elseif (!canAddMore($conn, $oid, 'clients')) {
                $err = 'وصلت للحد الأقصى من العملاء في باقتك.';
            } else {
                $ctype = ($_POST['new_client_type'] ?? 'individual') === 'company' ? 'company' : 'individual';
                $vals = [
                    'full_name'   => $conn->real_escape_string($cname),
                    'id_number'   => $conn->real_escape_string($cidn),
                    'phone'       => $conn->real_escape_string(trim($_POST['new_phone'] ?? '')),
                    'email'       => $conn->real_escape_string(trim($_POST['new_email'] ?? '')),
                    'city'        => $conn->real_escape_string(trim($_POST['new_city'] ?? '')),
                    'client_type' => $ctype,
                ];
                $conn->query("INSERT INTO clients (office_id,full_name,id_number,phone,email,city,client_type)
                    VALUES ($oid,'{$vals['full_name']}','{$vals['id_number']}','{$vals['phone']}','{$vals['email']}','{$vals['city']}','{$vals['client_type']}')");
                $clientId = (int) $conn->insert_id;
            }
        }
        if (!$err && !$clientId) $err = 'تعذّر تحديد العميل.';

        if (!$err) {
            $caseId = (int) ($_POST['case_id'] ?? 0);
            if ($caseId) {
                $chk = $conn->query("SELECT id FROM cases WHERE id=$caseId AND office_id=$oid AND client_id=$clientId")->num_rows;
                if (!$chk) $caseId = 0;
            }
            $notes  = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
            $sname  = $conn->real_escape_string($svc['name']);
            $amount = round((float) $svc['price'], 2);
            $rate   = (float) ($svc['vat_rate'] ?: $VAT);
            $vat    = round($amount * $rate / 100, 2);
            $total  = round($amount + $vat, 2);
            $caseSql = $caseId ? $caseId : 'NULL';
            $unameEsc = $conn->real_escape_string($uname);

            $conn->query("INSERT INTO service_requests
                (office_id,service_id,service_name,client_id,case_id,amount,vat_amount,total,status,notes,created_by,created_by_name)
                VALUES ($oid,{$svc['id']},'$sname',$clientId,$caseSql,$amount,$vat,$total,'new','$notes',$uid,'$unameEsc')");
            $reqId = (int) $conn->insert_id;

            $collected = !isset($_POST['collected']) || $_POST['collected'] === '1';
            [$invId, $invErr] = svc_make_invoice($conn, $oid, $reqId, $collected);

            $_retTo = trim($_POST['return_to'] ?? '');
            if (!preg_match('/^[a-z_]+\.php(\?[a-z0-9_=&%.\-]*)?$/i', $_retTo)) $_retTo = '';
            if ($_retTo !== '') {
                header("Location: " . $_retTo . (str_contains($_retTo,'?')?'&':'?') . "msg=created" . ($invErr ? '&iv=' . urlencode(mb_substr($invErr, 0, 160)) : '')); exit;
            }
            header("Location: digital_services.php?msg=created" . ($invErr ? '&iv=' . urlencode(mb_substr($invErr, 0, 160)) : '')); exit;
        }
        header("Location: digital_services.php?err=" . urlencode($err)); exit;
    }

    /* ─── إصدار فاتورة لطلب سابق بلا فاتورة ─── */
    if ($ft === 'gen_invoice' && can('services', 'add')) {
        $rid = (int) ($_POST['id'] ?? 0);
        $collected = !isset($_POST['collected']) || $_POST['collected'] === '1';
        [$iid, $ierr] = svc_make_invoice($conn, $oid, $rid, $collected);
        header("Location: digital_services.php?msg=" . ($iid ? 'invgen' : 'created') . ($ierr ? '&iv=' . urlencode(mb_substr($ierr, 0, 160)) : '')); exit;
    }
}

/* ════════ بيانات العرض ════════ */
$tab = $_GET['tab'] ?? 'requests';
if (!$_canManageCatalog || !in_array($tab, ['requests', 'catalog', 'types'], true)) $tab = 'requests';

$serviceTypes = [];
$tr = $conn->query("SELECT * FROM office_service_types WHERE office_id=$oid ORDER BY is_active DESC, sort_order, id");
if ($tr) while ($x = $tr->fetch_assoc()) $serviceTypes[] = $x;
$activeTypes = array_values(array_filter($serviceTypes, fn($t) => $t['is_active']));
$typeNameById = array_column($serviceTypes, 'name', 'id');

$services = [];
$sr = $conn->query("SELECT * FROM office_services WHERE office_id=$oid ORDER BY is_active DESC, sort_order, id");
if ($sr) while ($x = $sr->fetch_assoc()) $services[] = $x;
$activeServices = array_values(array_filter($services, fn($s) => $s['is_active']));

$fstat = $_GET['status_f'] ?? '';
$ftype = (int) ($_GET['type_f'] ?? 0);
$fq    = trim($_GET['q'] ?? '');
$w = "sr.office_id=$oid";
if (in_array($fstat, ['new', 'in_progress', 'completed', 'cancelled'], true)) $w .= " AND sr.status='$fstat'";
if ($ftype) $w .= " AND os.type_id=$ftype";
if ($fq !== '') { $qe = $conn->real_escape_string($fq); $w .= " AND (cl.full_name LIKE '%$qe%' OR cl.id_number LIKE '%$qe%' OR sr.service_name LIKE '%$qe%')"; }

$requests = $conn->query("SELECT sr.*, cl.full_name client_name, cl.id_number client_idn,
        c.case_number, inv.invoice_number, inv.public_token, ot.name type_name
    FROM service_requests sr
    LEFT JOIN clients cl  ON sr.client_id = cl.id
    LEFT JOIN cases c     ON sr.case_id  = c.id
    LEFT JOIN invoices inv ON sr.invoice_id = inv.id
    LEFT JOIN office_services os ON sr.service_id = os.id
    LEFT JOIN office_service_types ot ON os.type_id = ot.id
    WHERE $w ORDER BY sr.created_at DESC LIMIT 400");

$cnt = $conn->query("SELECT
    SUM(status='new') a, SUM(status='in_progress') b, SUM(status='completed') d,
    COUNT(*) t, SUM(DATE(created_at)=CURDATE()) today, IFNULL(SUM(total),0) sum_total
    FROM service_requests WHERE office_id=$oid")->fetch_assoc();

$edit_svc = null;
if ($_canManageCatalog && isset($_GET['edit_svc'])) {
    $edit_svc = $conn->query("SELECT * FROM office_services WHERE id=" . (int) $_GET['edit_svc'] . " AND office_id=$oid")->fetch_assoc();
}
$edit_type = null;
if ($_canManageCatalog && isset($_GET['edit_type'])) {
    $edit_type = $conn->query("SELECT * FROM office_service_types WHERE id=" . (int) $_GET['edit_type'] . " AND office_id=$oid")->fetch_assoc();
}

$nf = fn($v) => number_format((float) $v, 2);
include '../includes/office_header.php';
?>

<div class="mk-page-hdr">
  <div>
    <div class="mk-page-title"><i class="fas fa-hand-holding-dollar"></i> الخدمات الرقمية</div>
    <div class="mk-page-sub">
      <?= (int) $cnt['t'] ?> طلب · <?= (int) $cnt['today'] ?> اليوم · <?= (int) $cnt['a'] ?> جديد ·
      إجمالي <?= $nf($cnt['sum_total']) ?> ﷼
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (can('services', 'add')): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reqModal"
            <?= empty($activeServices) ? 'disabled title="أضِف خدمة في «خدمات المكتب» أولاً"' : '' ?>>
      <i class="fas fa-plus"></i> طلب خدمة جديد
    </button>
    <?php endif; ?>
    <?php if ($_canManageCatalog): ?>
    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#svcModal">
      <i class="fas fa-list-check"></i> إضافة خدمة
    </button>
    <?php endif; ?>
  </div>
</div>

<?php if (isset($_GET['msg'])):
  $M = ['created'=>'تم إنشاء الطلب.','invgen'=>'تم إصدار الفاتورة.','status'=>'حُدّثت الحالة.','saved'=>'حُفظت الخدمة.','deleted'=>'حُذفت الخدمة.'];
  $ME = ['denied'=>'ليست لديك صلاحية لهذا الإجراء — يتطلب صلاحية إدارة كتالوج الخدمات الرقمية.','invalid'=>'أدخل اسماً صحيحاً وسعراً صالحاً (0 أو أكثر).','dberror'=>'تعذّر تنفيذ العملية بسبب خطأ في قاعدة البيانات.'];
  $msgKey = $_GET['msg'];
?>
<?php if (isset($M[$msgKey])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i><?= e($M[$msgKey]) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php elseif (isset($ME[$msgKey])): ?>
<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i><?= e($ME[$msgKey]) ?>
  <?php if ($msgKey === 'dberror' && !empty($_GET['detail'])): ?><br><small class="text-muted font-monospace"><?= e($_GET['detail']) ?></small><?php endif; ?>
  <button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php else: ?>
<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i>تم<button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php endif; ?>
<?php if (isset($_GET['iv']) && $_GET['iv'] !== ''): ?>
<div class="alert alert-warning alert-dismissible fade show"><i class="fas fa-triangle-exclamation me-2"></i>
  تعذّر إنشاء الفاتورة تلقائياً — الطلب محفوظ. أصدِر الفاتورة من زر «إصدار فاتورة» في الجدول.
  <br><small class="text-muted font-monospace"><?= e($_GET['iv']) ?></small>
  <button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($_GET['err'])): ?>
<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i><?= e($_GET['err']) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='requests'?'active':'' ?>" href="digital_services.php?tab=requests">
    <i class="fas fa-inbox me-1"></i>الطلبات</a></li>
  <?php if ($_canManageCatalog): ?>
  <li class="nav-item"><a class="nav-link <?= $tab==='catalog'?'active':'' ?>" href="digital_services.php?tab=catalog">
    <i class="fas fa-tags me-1"></i>خدمات المكتب <span class="badge bg-secondary-subtle text-secondary ms-1"><?= count($services) ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='types'?'active':'' ?>" href="digital_services.php?tab=types">
    <i class="fas fa-layer-group me-1"></i>أنواع الخدمات <span class="badge bg-secondary-subtle text-secondary ms-1"><?= count($serviceTypes) ?></span></a></li>
  <?php endif; ?>
</ul>

<?php if ($tab === 'requests'): ?>
<div class="card mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="GET">
      <input type="hidden" name="tab" value="requests">
      <div class="col-auto flex-grow-1">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="بحث بالعميل أو الهوية أو الخدمة…" value="<?= e($fq) ?>">
      </div>
      <div class="col-auto">
        <select name="status_f" class="form-select form-select-sm">
          <option value="">كل الحالات</option>
          <option value="new" <?= $fstat==='new'?'selected':'' ?>>جديد</option>
          <option value="in_progress" <?= $fstat==='in_progress'?'selected':'' ?>>قيد التنفيذ</option>
          <option value="completed" <?= $fstat==='completed'?'selected':'' ?>>مكتمل</option>
          <option value="cancelled" <?= $fstat==='cancelled'?'selected':'' ?>>ملغى</option>
        </select>
      </div>
      <?php if ($serviceTypes): ?>
      <div class="col-auto">
        <select name="type_f" class="form-select form-select-sm">
          <option value="0">كل الأنواع</option>
          <?php foreach ($serviceTypes as $t): ?>
          <option value="<?= $t['id'] ?>" <?= $ftype===(int)$t['id']?'selected':'' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="fas fa-search"></i></button>
        <a href="digital_services.php" class="btn btn-sm btn-outline-secondary">إعادة</a></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr>
          <th>#</th><th>التاريخ</th><th>العميل</th><th>الخدمة</th>
          <th class="text-end">المبلغ</th><th class="text-end">الإجمالي</th>
          <th>الحالة</th><th>الموظف</th><th>الفاتورة</th>
        </tr></thead>
        <tbody>
        <?php if (!$requests || $requests->num_rows === 0): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">لا توجد طلبات</td></tr>
        <?php else: while ($r = $requests->fetch_assoc()): ?>
          <tr>
            <td class="text-muted"><?= $r['id'] ?></td>
            <td style="white-space:nowrap;font-size:12px"><?= e(dDate($r['created_at'], true)) ?></td>
            <td>
              <div class="fw-semibold"><?= e($r['client_name'] ?? '—') ?></div>
              <small class="text-muted font-monospace"><?= e($r['client_idn'] ?? '') ?></small>
              <?php if ($r['case_number']): ?><br><small class="text-primary"><i class="fas fa-gavel"></i> <?= e($r['case_number']) ?></small><?php endif; ?>
            </td>
            <td>
              <?= e($r['service_name']) ?>
              <?php if ($r['type_name']): ?><br><span class="badge bg-info-subtle text-info" style="font-size:10px"><?= e($r['type_name']) ?></span><?php endif; ?>
              <?php if ($r['notes']): ?><br><small class="text-muted"><?= e($r['notes']) ?></small><?php endif; ?>
            </td>
            <td class="text-end"><?= $nf($r['amount']) ?></td>
            <td class="text-end fw-bold"><?= $nf($r['total']) ?> ﷼</td>
            <td>
              <?php if (can('services', 'edit')): ?>
              <form method="POST" class="d-inline">
                <input type="hidden" name="form_type" value="req_status">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <select name="status" class="form-select form-select-sm" style="min-width:120px" onchange="this.form.submit()">
                  <option value="new" <?= $r['status']==='new'?'selected':'' ?>>جديد</option>
                  <option value="in_progress" <?= $r['status']==='in_progress'?'selected':'' ?>>قيد التنفيذ</option>
                  <option value="completed" <?= $r['status']==='completed'?'selected':'' ?>>مكتمل</option>
                  <option value="cancelled" <?= $r['status']==='cancelled'?'selected':'' ?>>ملغى</option>
                </select>
              </form>
              <?php else: echo statusBadge($r['status']); endif; ?>
            </td>
            <td style="font-size:12px"><?= e($r['created_by_name'] ?? '—') ?></td>
            <td>
              <?php if ($r['invoice_number']): ?>
                <a href="invoice_pdf.php?id=<?= (int)$r['invoice_id'] ?>&view=1" target="_blank" class="btn btn-sm btn-outline-primary" title="فاتورة PDF">
                  <i class="fas fa-file-invoice"></i>
                </a>
                <span class="d-block text-muted font-monospace" style="font-size:10px"><?= e($r['invoice_number']) ?></span>
              <?php elseif (can('services','add') && $r['status'] !== 'cancelled'): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('إصدار فاتورة بقيمة <?= $nf($r['total']) ?> ﷼ لهذا الطلب؟')">
                  <input type="hidden" name="form_type" value="gen_invoice">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <input type="hidden" name="collected" value="1">
                  <button class="btn btn-sm btn-outline-success" title="إصدار فاتورة"><i class="fas fa-file-circle-plus"></i> فاتورة</button>
                </form>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($tab === 'catalog'): ?>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr>
          <th>الخدمة</th><th>النوع</th><th class="text-end">السعر</th><th class="text-end">ض.ق.م 15%</th>
          <th class="text-end">الإجمالي</th><th>الحالة</th><th>إجراءات</th>
        </tr></thead>
        <tbody>
        <?php if (!$services): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">لا خدمات بعد — اضغط «إضافة خدمة»</td></tr>
        <?php else: foreach ($services as $sv):
          $vat = round($sv['price'] * ($sv['vat_rate'] ?: 15) / 100, 2); ?>
          <tr class="<?= $sv['is_active'] ? '' : 'opacity-50' ?>">
            <td>
              <div class="fw-semibold"><?= e($sv['name']) ?></div>
              <?php if ($sv['description']): ?><small class="text-muted"><?= e($sv['description']) ?></small><?php endif; ?>
            </td>
            <td><?php if (!empty($sv['type_id']) && isset($typeNameById[$sv['type_id']])): ?>
              <span class="badge bg-info-subtle text-info"><?= e($typeNameById[$sv['type_id']]) ?></span>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            <td class="text-end"><?= $nf($sv['price']) ?></td>
            <td class="text-end text-muted"><?= $nf($vat) ?></td>
            <td class="text-end fw-bold"><?= $nf($sv['price'] + $vat) ?> ﷼</td>
            <td><?= $sv['is_active'] ? '<span class="badge bg-success-subtle text-success">مُفعّلة</span>' : '<span class="badge bg-secondary-subtle text-secondary">مُعطّلة</span>' ?></td>
            <td>
              <div class="d-flex gap-1">
                <a href="digital_services.php?tab=catalog&edit_svc=<?= $sv['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                <form method="POST" class="d-inline"><input type="hidden" name="form_type" value="catalog_toggle"><input type="hidden" name="id" value="<?= $sv['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" title="<?= $sv['is_active']?'تعطيل':'تفعيل' ?>"><i class="fas fa-power-off"></i></button></form>
                <form method="POST" class="d-inline" onsubmit="return confirm('حذف هذه الخدمة؟')"><input type="hidden" name="form_type" value="catalog_delete"><input type="hidden" name="id" value="<?= $sv['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php else: /* ═══ tab: types ═══ */ ?>
<div class="alert alert-light border" style="font-size:12.5px">
  <i class="fas fa-circle-info me-1 text-primary"></i>
  أنواع الخدمات قائمة تُدار من هنا فقط — استخدمها لتصنيف خدمات المكتب (مثال: توثيق، استشارة، ترجمة)، وتظهر عند إضافة/تعديل أي خدمة في تبويب «خدمات المكتب».
</div>
<div class="d-flex justify-content-end mb-3">
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#typeModal"><i class="fas fa-plus"></i> نوع جديد</button>
</div>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>النوع</th><th>الحالة</th><th>إجراءات</th></tr></thead>
        <tbody>
        <?php if (!$serviceTypes): ?>
          <tr><td colspan="3" class="text-center text-muted py-4">لا أنواع بعد — اضغط «نوع جديد»</td></tr>
        <?php else: foreach ($serviceTypes as $t): ?>
          <tr class="<?= $t['is_active'] ? '' : 'opacity-50' ?>">
            <td class="fw-semibold"><?= e($t['name']) ?></td>
            <td><?= $t['is_active'] ? '<span class="badge bg-success-subtle text-success">مُفعّل</span>' : '<span class="badge bg-secondary-subtle text-secondary">مُعطّل</span>' ?></td>
            <td>
              <div class="d-flex gap-1">
                <a href="digital_services.php?tab=types&edit_type=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                <form method="POST" class="d-inline"><input type="hidden" name="form_type" value="type_toggle"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" title="<?= $t['is_active']?'تعطيل':'تفعيل' ?>"><i class="fas fa-power-off"></i></button></form>
                <form method="POST" class="d-inline" onsubmit="return confirm('حذف هذا النوع؟ ستبقى الخدمات المرتبطة به بلا نوع.')"><input type="hidden" name="form_type" value="type_delete"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ Modal: طلب خدمة جديد ═══ -->
<div class="modal fade" id="reqModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus me-2"></i>طلب خدمة جديد</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST" id="reqForm">
        <input type="hidden" name="form_type" value="request">
        <input type="hidden" name="return_to" value="<?= e($_GET['return_to'] ?? '') ?>">
        <input type="hidden" name="client_id" id="rq_client_id" value="">
        <div class="modal-body">

          <label class="form-label fw-semibold">استعلام عن العميل</label>
          <div class="input-group mb-2">
            <input type="text" id="rq_lookup" class="form-control font-monospace" placeholder="رقم الهوية / السجل / الرقم الموحّد / الإقامة" autocomplete="off">
            <button type="button" class="btn btn-outline-primary" id="rq_lookup_btn"><i class="fas fa-magnifying-glass"></i></button>
          </div>
          <div id="rq_lookup_res" class="small mb-3"></div>

          <!-- عميل موجود -->
          <div id="rq_found" class="d-none mb-3 p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0">
            <div class="fw-bold" id="rq_found_name"></div>
            <div class="text-muted small" id="rq_found_meta"></div>
            <div id="rq_case_wrap" class="mt-2 d-none">
              <label class="form-label form-label-sm mb-1">ربط بقضية (اختياري)</label>
              <select name="case_id" id="rq_case" class="form-select form-select-sm"><option value="0">— بدون —</option></select>
            </div>
          </div>

          <!-- عميل جديد -->
          <div id="rq_new" class="d-none mb-3 p-3 rounded" style="background:#fefce8;border:1px solid #fde68a">
            <div class="fw-bold mb-2" style="font-size:13px;color:#92400e"><i class="fas fa-user-plus me-1"></i>عميل غير مسجّل — أدخل بياناته</div>
            <div class="row g-2">
              <div class="col-md-6"><label class="form-label form-label-sm mb-1">الاسم الكامل *</label>
                <input type="text" name="new_full_name" class="form-control form-control-sm"></div>
              <div class="col-md-6"><label class="form-label form-label-sm mb-1">نوع العميل</label>
                <select name="new_client_type" class="form-select form-select-sm">
                  <option value="individual">فرد</option><option value="company">شركة / مؤسسة</option></select></div>
              <div class="col-md-6"><label class="form-label form-label-sm mb-1">رقم الهوية / السجل *</label>
                <input type="text" name="new_id_number" id="rq_new_idn" class="form-control form-control-sm font-monospace"></div>
              <div class="col-md-6"><label class="form-label form-label-sm mb-1">الجوال</label>
                <input type="text" name="new_phone" class="form-control form-control-sm"></div>
              <div class="col-md-6"><label class="form-label form-label-sm mb-1">البريد الإلكتروني</label>
                <input type="email" name="new_email" class="form-control form-control-sm"></div>
              <div class="col-md-6"><label class="form-label form-label-sm mb-1">المدينة</label>
                <input type="text" name="new_city" class="form-control form-control-sm"></div>
            </div>
          </div>

          <hr>
          <div class="mb-3">
            <label class="form-label fw-semibold">الخدمة المطلوبة *</label>
            <select name="service_id" id="rq_service" class="form-select" required>
              <option value="">— اختر —</option>
              <?php foreach ($activeServices as $sv):
                $vt = round($sv['price'] * ($sv['vat_rate'] ?: 15) / 100, 2); ?>
              <option value="<?= $sv['id'] ?>"><?= e($sv['name']) ?> — <?= $nf($sv['price'] + $vt) ?> ﷼ (شامل الضريبة)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">ملاحظات</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="collected" id="rq_collected" value="1" checked>
            <label class="form-check-label" for="rq_collected">تم تحصيل المبلغ الآن (الفاتورة «مدفوعة» ويُسجَّل الإيراد فوراً)</label>
          </div>
          <div class="alert alert-info small mb-0">
            <i class="fas fa-circle-info me-1"></i>
            سيُنشأ الطلب بتاريخ اليوم، وتُصدَر فاتورة بالإجمالي شامل الضريبة.
            عند التحصيل: تُسجَّل في <b>الإيرادات</b> فوراً. بدون تحصيل: تبقى فاتورة «مُرسلة» (مستحق)، ويُسجَّل الإيراد تلقائياً عند وضعها «مدفوعة» من صفحة الفواتير.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary" id="rq_submit"><i class="fas fa-save me-1"></i>إنشاء الطلب</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($_canManageCatalog): ?>
<!-- ═══ Modal: خدمة (كتالوج) ═══ -->
<div class="modal fade" id="svcModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-tag me-2"></i><?= $edit_svc ? 'تعديل خدمة' : 'إضافة خدمة' ?></h5>
        <button class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="form_type" value="catalog_save">
        <input type="hidden" name="id" value="<?= (int) ($edit_svc['id'] ?? 0) ?>">
        <div class="modal-body">
          <div class="mb-3"><label class="form-label fw-semibold">اسم الخدمة *</label>
            <input type="text" name="name" class="form-control" required value="<?= e($edit_svc['name'] ?? '') ?>"></div>
          <div class="mb-3"><label class="form-label fw-semibold">وصف مختصر</label>
            <input type="text" name="description" class="form-control" value="<?= e($edit_svc['description'] ?? '') ?>"></div>
          <div class="mb-3">
            <label class="form-label fw-semibold">نوع الخدمة</label>
            <select name="type_id" class="form-select">
              <option value="0">— بدون —</option>
              <?php foreach ($serviceTypes as $t): ?>
              <option value="<?= $t['id'] ?>" <?= (int)($edit_svc['type_id'] ?? 0)===(int)$t['id']?'selected':'' ?>>
                <?= e($t['name']) ?><?= $t['is_active'] ? '' : ' (معطّل)' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$serviceTypes): ?>
            <div class="form-text">لا توجد أنواع بعد — أضِفها من تبويب «أنواع الخدمات».</div>
            <?php endif; ?>
          </div>
          <div class="mb-1"><label class="form-label fw-semibold">السعر شامل ضريبة القيمة المضافة (15%) — ﷼</label>
            <input type="number" name="price" step="0.01" min="0" class="form-control" required
                   value="<?= $edit_svc ? e(number_format($edit_svc['price'] * (1 + (($edit_svc['vat_rate'] ?: 15)) / 100), 2, '.', '')) : '' ?>" id="svc_price"></div>
          <div class="form-text">هذا هو السعر الذي يراه العميل ويدفعه كاملاً. السعر الأساسي قبل الضريبة
            <span id="svc_preview" class="fw-bold text-dark"></span></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ Modal: نوع خدمة ═══ -->
<div class="modal fade" id="typeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-layer-group me-2"></i><?= $edit_type ? 'تعديل نوع' : 'نوع جديد' ?></h5>
        <button class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="form_type" value="type_save">
        <input type="hidden" name="id" value="<?= (int) ($edit_type['id'] ?? 0) ?>">
        <div class="modal-body">
          <label class="form-label fw-semibold">اسم النوع *</label>
          <input type="text" name="name" class="form-control" required autofocus
                 placeholder="مثال: توثيق، استشارة، ترجمة" value="<?= e($edit_type['name'] ?? '') ?>">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  // ── استعلام العميل ──
  var lk = document.getElementById('rq_lookup');
  var btn = document.getElementById('rq_lookup_btn');
  var res = document.getElementById('rq_lookup_res');
  var found = document.getElementById('rq_found');
  var newBox = document.getElementById('rq_new');
  var cid = document.getElementById('rq_client_id');
  var caseWrap = document.getElementById('rq_case_wrap');
  var caseSel = document.getElementById('rq_case');
  var t;

  function reset(){ cid.value=''; found.classList.add('d-none'); newBox.classList.add('d-none'); caseWrap.classList.add('d-none'); }

  function doLookup(){
    var v = (lk.value||'').trim();
    if(!v){ res.innerHTML=''; reset(); return; }
    res.innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>جارٍ البحث…</span>';
    fetch('digital_services.php?lookup=' + encodeURIComponent(v))
      .then(function(r){return r.json();})
      .then(function(d){
        reset();
        if(d.found){
          res.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>العميل موجود</span>';
          cid.value = d.client.id;
          document.getElementById('rq_found_name').textContent = d.client.full_name;
          document.getElementById('rq_found_meta').textContent =
            (d.client.id_number||'') + (d.client.phone? ' · '+d.client.phone : '') + (d.client.city? ' · '+d.client.city : '');
          found.classList.remove('d-none');
          caseSel.innerHTML = '<option value="0">— بدون —</option>';
          if(d.cases && d.cases.length){
            d.cases.forEach(function(c){
              var o=document.createElement('option'); o.value=c.id;
              o.textContent = c.case_number + (c.title? ' — '+c.title : '');
              caseSel.appendChild(o);
            });
            caseWrap.classList.remove('d-none');
          }
        } else {
          res.innerHTML = '<span class="text-warning"><i class="fas fa-user-plus me-1"></i>غير مسجّل — أدخل بياناته أدناه</span>';
          newBox.classList.remove('d-none');
          var idn = newBox.querySelector('#rq_new_idn'); if(idn && !idn.value) idn.value = v;
        }
      })
      .catch(function(){ res.innerHTML='<span class="text-danger">تعذّر الاستعلام</span>'; });
  }
  if(lk){
    lk.addEventListener('input', function(){ clearTimeout(t); t=setTimeout(doLookup, 500); });
    btn.addEventListener('click', doLookup);
  }
  var rf = document.getElementById('reqForm');
  if(rf) rf.addEventListener('submit', function(e){
    if(!cid.value && !newBox.querySelector('[name=new_full_name]').value.trim()){
      e.preventDefault(); alert('استعلم عن العميل أو أدخل بياناته.');
    }
  });

  // ── معاينة سعر الخدمة ──
  var pr = document.getElementById('svc_price');
  var pv = document.getElementById('svc_preview');
  function upd(){ if(!pr||!pv) return; var p=parseFloat(pr.value||0); pv.textContent = p? '= ' + (p/1.15).toFixed(2) + ' ﷼' : ''; }
  if(pr){ pr.addEventListener('input', upd); upd(); }

  <?php if ($edit_svc): ?>
  // bootstrap.bundle.min.js يُحمَّل لاحقاً في تذييل الصفحة — ننتظر اكتمال تحميل المستند
  // حتى لا يُنفَّذ new bootstrap.Modal() قبل تعريف bootstrap فيفشل بصمت ولا تُفتح النافذة
  document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('svcModal')).show();
  });
  <?php endif; ?>
  <?php if ($edit_type): ?>
  document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('typeModal')).show();
  });
  <?php endif; ?>
  <?php if ($_new_client_row): ?>
  document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('reqModal')).show();
    <?php $_lk_term = trim($_new_client_row['id_number'] ?: $_new_client_row['phone'] ?: ''); ?>
    <?php if ($_lk_term !== ''): ?>
    lk.value = <?= json_encode($_lk_term) ?>;
    doLookup();
    <?php endif; ?>
  });
  <?php endif; ?>
})();
</script>

<?php include '../includes/office_footer.php'; ?>
