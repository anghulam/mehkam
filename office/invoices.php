<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/zatca_helper.php';
requireOffice();
// الفواتير مستند مالي — تتطلب صلاحية الشؤون المالية بالإضافة لصلاحية الفواتير نفسها،
// حتى لا يراها موظّف مُسند لقضية عنده صلاحية "الفواتير" فقط بدون "الشؤون المالية"
if (!can('invoices','view') || !can('finance','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'الفواتير';
$oid = (int)$_SESSION['office_id'];
// فتح نافذة «فاتورة جديدة» مباشرة مع تحديد العميل مسبقاً — من ملف العميل (client_file.php)
$_new_for_client = (int)($_GET['client_id'] ?? 0);

// تشغيل ترقيات ZATCA (آمن)
zatca_migrate($conn);

// ربط الفواتير بالشؤون المالية — إضافة أعمدة الربط
try { $conn->query("ALTER TABLE transactions ADD COLUMN invoice_id INT DEFAULT NULL"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE transactions ADD COLUMN source ENUM('manual','invoice') DEFAULT 'manual'"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE transactions ADD COLUMN case_id INT DEFAULT NULL"); } catch (\Exception $e) {}
// اتجاه الفاتورة: إيراد أو مصروف + نوع الخصم
foreach ([
    "ALTER TABLE invoices ADD COLUMN direction ENUM('income','expense') DEFAULT 'income'",
    "ALTER TABLE invoices ADD COLUMN discount_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed'",
    "ALTER TABLE invoices ADD COLUMN discount_value DECIMAL(12,2) NOT NULL DEFAULT 0",
    "ALTER TABLE invoices ADD COLUMN paid_date DATE DEFAULT NULL",
    "ALTER TABLE invoices ADD COLUMN public_token VARCHAR(40) DEFAULT NULL",
    // إضافة الوقت لتاريخ استحقاق الفاتورة (كان تاريخاً بلا وقت) — issue_date/supply_date تبقى تاريخاً فقط لمتطلبات ZATCA
    "ALTER TABLE invoices MODIFY COLUMN due_date DATETIME DEFAULT NULL",
] as $_c) { try { $conn->query($_c); } catch (\Exception $e) {} }
// توليد رمز عام للفواتير القديمة (لرابط نسخة العميل)
try {
    $mk = $conn->query("SELECT id FROM invoices WHERE office_id=$oid AND (public_token IS NULL OR public_token='')");
    if ($mk) while ($mr = $mk->fetch_assoc()) {
        $tk = bin2hex(random_bytes(16));
        $conn->query("UPDATE invoices SET public_token='$tk' WHERE id=" . (int)$mr['id']);
    }
} catch (\Throwable $e) {}

/**
 * مزامنة قيد مالي مرتبط بالفاتورة (إنشاء أو تحديث)
 * يُنفَّذ فقط عندما تكون حالة الفاتورة "مدفوعة".
 * $direction: 'income' أو 'expense' — يحدد نوع القيد.
 */
function sync_invoice_transaction($conn, $oid, $invoice_id, $client_id, $total, $title, $inv_num, $paid_date = null, $case_id = 0, $direction = 'income') {
    $amount    = (float)$total;
    $cid_sql   = $client_id ? (int)$client_id : 'NULL';
    $case_sql  = $case_id   ? (int)$case_id   : 'NULL';
    $type      = $direction === 'expense' ? 'expense' : 'income';
    $category  = $direction === 'expense' ? 'مصروفات وفواتير' : 'أتعاب قانونية';
    $cat_esc   = $conn->real_escape_string($category);
    $desc_esc  = $conn->real_escape_string('فاتورة رقم ' . $inv_num . ' — ' . mb_substr($title, 0, 80));
    $date_sql  = $paid_date ? "'" . $conn->real_escape_string($paid_date) . "'" : 'CURDATE()';

    $exists = $conn->query("SELECT id FROM transactions WHERE invoice_id=$invoice_id AND office_id=$oid LIMIT 1");
    if ($exists && $exists->num_rows > 0) {
        $tid = (int)$exists->fetch_assoc()['id'];
        $conn->query("UPDATE transactions SET type='$type', category='$cat_esc', amount=$amount, description='$desc_esc', client_id=$cid_sql, case_id=$case_sql, transaction_date=$date_sql WHERE id=$tid");
    } else {
        $conn->query("INSERT INTO transactions (office_id,type,category,amount,description,payment_method,transaction_date,client_id,case_id,invoice_id,source)
            VALUES ($oid,'$type','$cat_esc',$amount,'$desc_esc','cash',$date_sql,$cid_sql,$case_sql,$invoice_id,'invoice')");
    }
}

// إعادة توجيه الطباعة
if (isset($_GET['print'])) {
    header("Location: invoice_print.php?id=".(int)$_GET['print']); exit;
}

// التحقق من إذن الفواتير
if (!hasFeature($conn, $oid, 'has_invoices')) {
    header("Location: dashboard.php?msg=feature_locked"); exit;
}

/* ── معالجة الطلبات ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // حفظ / تعديل فاتورة
    if (isset($_POST['form_type']) && $_POST['form_type'] === 'invoice') {
        $title      = $conn->real_escape_string(trim($_POST['title'] ?? ''));
        $client_id  = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : 'NULL';
        $case_id    = !empty($_POST['case_id'])   ? (int)$_POST['case_id']   : 'NULL';
        $status     = $conn->real_escape_string($_POST['status'] ?? 'draft');
        $notes      = $conn->real_escape_string($_POST['notes'] ?? '');
        $inv_num    = $conn->real_escape_string($_POST['invoice_number'] ?? '');
        $direction  = ($_POST['direction'] ?? '') === 'expense' ? 'expense' : 'income';

        // ── التواريخ: فراغ أو صيغة خاطئة → قيمة آمنة (يمنع خطأ MySQL strict) ──
        $_dval = function ($k) {
            $v = trim($_POST[$k] ?? '');
            $v = str_replace('/', '-', $v);
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return '';
            if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000) return '';
            return $v;
        };
        $issue_date  = $_dval('issue_date')  ?: date('Y-m-d');
        $supply_date = $_dval('supply_date') ?: $issue_date;
        // due_date تحمل وقتاً أيضاً الآن (datetime-local)، بخلاف issue_date/supply_date المطلوبة لـ ZATCA كتاريخ فقط
        $due_raw     = str_replace('T', ' ', trim($_POST['due_date'] ?? ''));
        $due_date    = $due_raw !== '' ? "'" . $conn->real_escape_string($due_raw) . "'" : 'NULL';

        // ── بنود الفاتورة (تُقرأ أولاً لحساب المجموع الفرعي منها) ──
        $items = [];
        $items_subtotal = 0.0;
        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                $desc = trim($item['description'] ?? '');
                if ($desc === '') continue;
                $qty   = (float)($item['qty'] ?? 1);
                $price = (float)($item['price'] ?? 0);
                $items[] = ['description' => $desc, 'qty' => $qty, 'price' => $price];
                $items_subtotal += $qty * $price;
            }
        }
        $items_json = $conn->real_escape_string(json_encode($items, JSON_UNESCAPED_UNICODE));

        // ── المجموع / الخصم (نسبة أو ريال) / الضريبة / الإجمالي — كلها من الخادم ──
        $subtotal = $items_subtotal > 0 ? round($items_subtotal, 2) : round((float)($_POST['subtotal'] ?? 0), 2);
        $discount_type  = ($_POST['discount_type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
        $discount_value = max(0, (float)($_POST['discount_value'] ?? 0));
        $discount = $discount_type === 'percent'
            ? round($subtotal * $discount_value / 100, 2)
            : round($discount_value, 2);
        $discount   = max(0, min($discount, $subtotal));
        $tax_rate   = max(0, (float)($_POST['tax_rate'] ?? 15));
        $taxable    = max(0, $subtotal - $discount);
        $tax_amount = round($taxable * $tax_rate / 100, 2);
        $total      = round($taxable + $tax_amount, 2);

        // حقول ZATCA
        $inv_type    = in_array($_POST['invoice_type'] ?? '', ['simplified','standard']) ? $_POST['invoice_type'] : 'simplified';
        $issue_date  = $conn->real_escape_string($issue_date);
        $supply_date = $conn->real_escape_string($supply_date);
        $buyer_vat   = $conn->real_escape_string(trim($_POST['buyer_vat'] ?? ''));

        // جلب الرقم الضريبي للبائع
        $s = $conn->query("SELECT tax_number FROM office_settings WHERE office_id=$oid")->fetch_assoc();
        $seller_vat = $conn->real_escape_string($s['tax_number'] ?? '');

        $edit_id = (int)($_POST['id'] ?? 0);
        // للفاتورة المبسطة B2C لا يُحتسب رقم ضريبي للمشتري
        $seller_name_row = $conn->query("SELECT name FROM offices WHERE id=$oid")->fetch_assoc();
        $seller_name = $seller_name_row['name'] ?? 'مِحكام';
        // QR يُولّد لفواتير الإيراد فقط (البائع = المكتب)
        $qr = ($seller_vat && $direction !== 'expense')
            ? $conn->real_escape_string(zatca_qr($seller_name, $seller_vat, $total, $tax_amount, $issue_date . 'T00:00:00Z'))
            : '';
        if ($edit_id) {
            $conn->query("UPDATE invoices SET
                title='$title', client_id=$client_id, case_id=$case_id,
                invoice_number='$inv_num', items='$items_json',
                subtotal=$subtotal, discount=$discount, discount_type='$discount_type', discount_value=$discount_value,
                tax_rate=$tax_rate, tax_amount=$tax_amount, total=$total, status='$status',
                due_date=$due_date, notes='$notes', direction='$direction',
                invoice_type='$inv_type', issue_date='$issue_date', supply_date='$supply_date',
                buyer_vat='$buyer_vat', seller_vat='$seller_vat',
                qr_data='$qr', zatca_status='" . ($qr ? 'valid' : 'draft') . "'
                WHERE id=$edit_id AND office_id=$oid");
            if ($status === 'paid' && hasFeature($conn, $oid, 'has_finance')) {
                sync_invoice_transaction($conn, $oid, $edit_id, (int)$client_id, $total, $title, $inv_num, date('Y-m-d'), (int)$case_id, $direction);
            }
        } else {
            $uuid  = $conn->real_escape_string(zatca_uuid());
            $ptok  = bin2hex(random_bytes(16));
            $conn->query("INSERT INTO invoices
                (office_id,client_id,case_id,invoice_number,title,items,
                 subtotal,discount,discount_type,discount_value,tax_rate,tax_amount,total,status,due_date,notes,direction,
                 uuid,public_token,invoice_type,issue_date,supply_date,buyer_vat,seller_vat,qr_data,zatca_status)
                VALUES ($oid,$client_id,$case_id,'$inv_num','$title','$items_json',
                        $subtotal,$discount,'$discount_type',$discount_value,$tax_rate,$tax_amount,$total,'$status',$due_date,'$notes','$direction',
                        '$uuid','$ptok','$inv_type','$issue_date','$supply_date','$buyer_vat','$seller_vat','$qr'," .
                        ($qr ? "'valid'" : "'draft'") . ")");
            if ($status === 'paid' && hasFeature($conn, $oid, 'has_finance')) {
                $new_id = (int)$conn->insert_id;
                sync_invoice_transaction($conn, $oid, $new_id, (int)$client_id, $total, $title, $inv_num, date('Y-m-d'), (int)$case_id, $direction);
            }
        }
        $_retTo = trim($_POST['return_to'] ?? '');
        if (!preg_match('/^[a-z_]+\.php(\?[a-z0-9_=&%.\-]*)?$/i', $_retTo)) $_retTo = '';
        header("Location: " . ($_retTo !== '' ? $_retTo . (str_contains($_retTo,'?')?'&':'?') . 'msg=saved' : 'invoices.php?msg=saved')); exit;
    }

    // تغيير الحالة
    if (isset($_POST['change_status'])) {
        $inv_id     = (int)$_POST['inv_id'];
        $new_status = $conn->real_escape_string($_POST['new_status']);
        $paid_date  = ($new_status === 'paid') ? "paid_date=CURDATE()," : '';
        $conn->query("UPDATE invoices SET {$paid_date}status='$new_status' WHERE id=$inv_id AND office_id=$oid");
        // إنشاء قيد مالي تلقائياً عند تأكيد الدفع
        if ($new_status === 'paid' && hasFeature($conn, $oid, 'has_finance')) {
            $inv = $conn->query("SELECT * FROM invoices WHERE id=$inv_id AND office_id=$oid")->fetch_assoc();
            if ($inv) {
                sync_invoice_transaction($conn, $oid, $inv_id, (int)($inv['client_id'] ?? 0), $inv['total'], $inv['title'], $inv['invoice_number'], date('Y-m-d'), (int)($inv['case_id'] ?? 0), $inv['direction'] ?? 'income');
            }
        }
        header("Location: invoices.php?msg=updated"); exit;
    }
}

// حذف
if (isset($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    // حذف القيد المالي المرتبط إن وجد
    $conn->query("DELETE FROM transactions WHERE invoice_id=$did AND office_id=$oid AND source='invoice'");
    $conn->query("DELETE FROM invoices WHERE id=$did AND office_id=$oid");
    header("Location: invoices.php?msg=deleted"); exit;
}

/* ── جلب البيانات ── */
$status_f = $conn->real_escape_string($_GET['status_f'] ?? '');
$where = "inv.office_id=$oid";
if ($status_f) $where .= " AND inv.status='$status_f'";
if (!empty($_GET['q'])) {
    $q = $conn->real_escape_string($_GET['q']);
    $where .= " AND (inv.title LIKE '%$q%' OR inv.invoice_number LIKE '%$q%')";
}

$invoices = $conn->query("
    SELECT inv.*, cl.full_name client_name, ca.case_number
    FROM invoices inv
    LEFT JOIN clients cl ON inv.client_id = cl.id
    LEFT JOIN cases   ca ON inv.case_id   = ca.id
    WHERE $where ORDER BY inv.created_at DESC
");

$clients_list = $conn->query("SELECT id,full_name,vat_number FROM clients WHERE office_id=$oid ORDER BY full_name");
$cases_list   = $conn->query("SELECT id,case_number,title FROM cases WHERE office_id=$oid ORDER BY case_number");
$clients_arr  = $cases_arr = [];
while ($c = $clients_list->fetch_assoc()) $clients_arr[$c['id']] = $c['full_name'];
while ($c = $cases_list->fetch_assoc())   $cases_arr[$c['id']]   = $c['case_number'].' - '.mb_substr($c['title'],0,40);

// تعديل
$edit = null;
if (isset($_GET['edit'])) {
    $edit = $conn->query("SELECT * FROM invoices WHERE id=".(int)$_GET['edit']." AND office_id=$oid")->fetch_assoc();
    if ($edit && $edit['items']) $edit['items_decoded'] = json_decode($edit['items'], true) ?? [];
}

// إحصائيات
$stats = [
    'all'      => (int)dbVal($conn,"SELECT COUNT(*) FROM invoices WHERE office_id=$oid"),
    'draft'    => (int)dbVal($conn,"SELECT COUNT(*) FROM invoices WHERE office_id=$oid AND status='draft'"),
    'sent'     => (int)dbVal($conn,"SELECT COUNT(*) FROM invoices WHERE office_id=$oid AND status='sent'"),
    'paid'     => (int)dbVal($conn,"SELECT COUNT(*) FROM invoices WHERE office_id=$oid AND status='paid'"),
    'overdue'  => (int)dbVal($conn,"SELECT COUNT(*) FROM invoices WHERE office_id=$oid AND status='overdue'"),
];
$total_paid    = (float)dbVal($conn,"SELECT IFNULL(SUM(total),0) FROM invoices WHERE office_id=$oid AND status='paid'");
$total_pending = (float)dbVal($conn,"SELECT IFNULL(SUM(total),0) FROM invoices WHERE office_id=$oid AND status IN('sent','overdue')");

// توليد رقم فاتورة تلقائي (ZATCA sequential)
$settings_inv = $conn->query("SELECT invoice_prefix FROM office_settings WHERE office_id=$oid")->fetch_assoc();
$inv_prefix   = $settings_inv['invoice_prefix'] ?? 'INV';
$auto_number  = next_invoice_num($conn, $oid, $inv_prefix);

include '../includes/office_header.php';
?>

<!-- Page Header -->
<div class="mk-page-hdr">
  <div>
    <div class="mk-page-title"><i class="fas fa-file-invoice-dollar"></i> الفواتير</div>
    <div class="mk-page-sub">إنشاء وإدارة فواتير العملاء</div>
  </div>
  <div class="d-flex gap-2">
    <a href="zatca_report.php" class="btn btn-outline-warning">
      <i class="fas fa-landmark me-1"></i>تقرير ZATCA
    </a>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#invModal">
      <i class="fas fa-plus"></i> فاتورة جديدة
    </button>
  </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="mk-stat gold">
      <div class="mk-stat-icon gold"><i class="fas fa-file-invoice"></i></div>
      <div class="mk-stat-body">
        <div class="mk-stat-val"><?= $stats['all'] ?></div>
        <div class="mk-stat-lbl">إجمالي الفواتير</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="mk-stat success">
      <div class="mk-stat-icon success"><i class="fas fa-check-circle"></i></div>
      <div class="mk-stat-body">
        <div class="mk-stat-val"><?= number_format($total_paid) ?> <small style="font-size:13px">ر.س</small></div>
        <div class="mk-stat-lbl">مُحصَّل (<?= $stats['paid'] ?> فاتورة)</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="mk-stat warning">
      <div class="mk-stat-icon warning"><i class="fas fa-clock"></i></div>
      <div class="mk-stat-body">
        <div class="mk-stat-val"><?= $stats['sent'] ?></div>
        <div class="mk-stat-lbl">بانتظار الدفع</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="mk-stat danger">
      <div class="mk-stat-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="mk-stat-body">
        <div class="mk-stat-val"><?= number_format($total_pending) ?> <small style="font-size:13px">ر.س</small></div>
        <div class="mk-stat-lbl">متأخرة أو معلقة</div>
      </div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="GET">
      <div class="col-auto flex-grow-1">
        <input type="text" name="q" class="form-control" placeholder="بحث برقم أو عنوان الفاتورة..."
               value="<?= e($_GET['q'] ?? '') ?>">
      </div>
      <div class="col-auto">
        <select name="status_f" class="form-select">
          <option value="">جميع الحالات <?= "({$stats['all']})" ?></option>
          <?php foreach(['draft'=>'مسودة','sent'=>'مُرسلة','paid'=>'مدفوعة','overdue'=>'متأخرة','cancelled'=>'ملغاة'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= $status_f===$v?'selected':'' ?>>
            <?= $l ?> <?= isset($stats[$v])?"({$stats[$v]})":'' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary"><i class="fas fa-search"></i></button>
        <a href="invoices.php" class="btn btn-outline-secondary ms-1"><i class="fas fa-undo"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Invoices Table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>رقم الفاتورة</th><th>النوع</th><th>العنوان</th><th>العميل/الجهة</th><th>القضية</th>
          <th>المبلغ</th><th>تاريخ الاستحقاق</th><th>الحالة</th><th>إجراءات</th>
        </tr></thead>
        <tbody>
        <?php if ($invoices->num_rows === 0): ?>
        <tr><td colspan="8">
          <div class="mk-empty">
            <div class="mk-empty-icon"><i class="fas fa-file-invoice"></i></div>
            <div class="mk-empty-title">لا توجد فواتير</div>
            <div class="mk-empty-desc">ابدأ بإنشاء فاتورة جديدة</div>
          </div>
        </td></tr>
        <?php else: ?>
        <?php while ($inv = $invoices->fetch_assoc()):
          $overdue = $inv['status']==='sent' && $inv['due_date'] && strtotime($inv['due_date']) < time();
        ?>
        <tr>
          <td class="fw-bold text-primary"><?= e($inv['invoice_number']) ?></td>
          <td>
            <?php if (($inv['direction'] ?? 'income') === 'expense'): ?>
            <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fas fa-arrow-circle-up me-1"></i>مصروف</span>
            <?php else: ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-arrow-circle-down me-1"></i>إيراد</span>
            <?php endif; ?>
          </td>
          <td style="max-width:180px">
            <div class="text-truncate" title="<?= e($inv['title']) ?>"><?= e($inv['title']) ?></div>
          </td>
          <td><?= e($inv['client_name'] ?? '—') ?></td>
          <td><?= $inv['case_number'] ? '<span class="badge bg-secondary-subtle text-secondary">'.e($inv['case_number']).'</span>' : '—' ?></td>
          <td class="fw-bold"><?= number_format($inv['total']) ?> ر.س</td>
          <td>
            <?php if ($inv['due_date']): ?>
            <span class="<?= $overdue?'text-danger fw-semibold':'' ?>">
              <?= dDate($inv['due_date'], true) ?>
            </span>
            <?php else: ?> — <?php endif; ?>
          </td>
          <td>
            <?= statusBadge($overdue ? 'overdue' : $inv['status']) ?>
            <?php
            // مؤشر الربط بالشؤون المالية
            $has_tx = $conn->query("SELECT id FROM transactions WHERE invoice_id={$inv['id']} AND office_id=$oid AND source='invoice' LIMIT 1");
            if ($has_tx && $has_tx->num_rows > 0):
            ?>
            <a href="finance.php" class="badge bg-success-subtle text-success border border-success-subtle text-decoration-none ms-1" title="مُسجَّل في الشؤون المالية">
              <i class="fas fa-check-circle"></i> مالية
            </a>
            <?php endif; ?>
          </td>
          <td>
            <div class="d-flex gap-1 flex-wrap">
              <a href="invoices.php?edit=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-primary" title="تعديل">
                <i class="fas fa-edit"></i>
              </a>
              <a href="invoice_pdf.php?id=<?= $inv['id'] ?>&view=1" target="_blank" class="btn btn-sm btn-outline-dark" title="معاينة PDF">
                <i class="fas fa-file-pdf"></i>
              </a>
              <a href="invoice_pdf.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-secondary" title="تحميل PDF">
                <i class="fas fa-download"></i>
              </a>
              <?php if (!empty($inv['public_token'])): ?>
              <button type="button" class="btn btn-sm btn-outline-info" title="نسخ رابط نسخة العميل (بدون تسجيل دخول)"
                      onclick="navigator.clipboard&&navigator.clipboard.writeText(location.origin+'/public/invoice_view.php?t=<?= e($inv['public_token']) ?>');this.innerHTML='<i class=\'fas fa-check\'></i>';">
                <i class="fas fa-share-nodes"></i>
              </button>
              <?php endif; ?>
              <!-- تغيير الحالة -->
              <?php if ($inv['status'] !== 'paid' && $inv['status'] !== 'cancelled'): ?>
              <form method="POST" class="d-inline">
                <input type="hidden" name="change_status" value="1">
                <input type="hidden" name="inv_id" value="<?= $inv['id'] ?>">
                <input type="hidden" name="new_status" value="<?= $inv['status']==='draft'?'sent':'paid' ?>">
                <button type="submit" class="btn btn-sm btn-outline-success" title="<?= $inv['status']==='draft'?'إرسال':'تأكيد الدفع' ?>">
                  <i class="fas fa-<?= $inv['status']==='draft'?'paper-plane':'check' ?>"></i>
                </button>
              </form>
              <?php endif; ?>
              <a href="invoices.php?delete=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-danger"
                 data-confirm="هل تريد حذف هذه الفاتورة؟" title="حذف">
                <i class="fas fa-trash"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══ Modal الفاتورة ═══ -->
<div class="modal fade" id="invModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-file-invoice-dollar me-2"></i>
          <?= $edit ? 'تعديل الفاتورة' : 'فاتورة جديدة' ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="form_type" value="invoice">
        <input type="hidden" name="return_to" value="<?= e($_GET['return_to'] ?? '') ?>">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
        <input type="hidden" name="subtotal" id="invSubtotalHidden" value="<?= $edit['subtotal'] ?? 0 ?>">
        <input type="hidden" name="discount" id="invDiscountHidden" value="<?= $edit['discount'] ?? 0 ?>">
        <input type="hidden" name="tax_amount" id="invTaxAmountHidden" value="<?= $edit['tax_amount'] ?? 0 ?>">
        <input type="hidden" name="total" id="invTotalHidden" value="<?= $edit['total'] ?? 0 ?>">

        <div class="modal-body">
          <div class="row g-3">

            <!-- ═══ نوع القيد المالي ═══ -->
            <div class="col-12">
              <label class="form-label fw-semibold">نوع القيد المالي *</label>
              <input type="hidden" name="direction" id="directionInput" value="<?= ($edit['direction'] ?? 'income') === 'expense' ? 'expense' : 'income' ?>">
              <div class="row g-2">
                <div class="col-6">
                  <div class="dir-card" id="card_income" onclick="pickDir('income')"
                       style="cursor:pointer;border:2px solid;border-radius:8px;padding:12px;text-align:center;transition:.15s;
                              <?= ($edit['direction'] ?? 'income') !== 'expense' ? 'border-color:#16a34a;background:#f0fdf4;' : 'border-color:#dee2e6;background:#fff;' ?>">
                    <i class="fas fa-arrow-circle-down mb-1" style="font-size:22px;color:#16a34a"></i>
                    <div class="fw-bold" style="color:#16a34a">إيراد</div>
                    <small class="text-muted" style="font-size:11px">مبلغ مستحق من عميل</small>
                  </div>
                </div>
                <div class="col-6">
                  <div class="dir-card" id="card_expense" onclick="pickDir('expense')"
                       style="cursor:pointer;border:2px solid;border-radius:8px;padding:12px;text-align:center;transition:.15s;
                              <?= ($edit['direction'] ?? '') === 'expense' ? 'border-color:#dc2626;background:#fff5f5;' : 'border-color:#dee2e6;background:#fff;' ?>">
                    <i class="fas fa-arrow-circle-up mb-1" style="font-size:22px;color:#dc2626"></i>
                    <div class="fw-bold" style="color:#dc2626">مصروف</div>
                    <small class="text-muted" style="font-size:11px">رسوم قضائية / تكاليف</small>
                  </div>
                </div>
              </div>
            </div>

            <!-- ═══ حقول ZATCA ═══ -->
            <div class="col-12">
              <div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:4px">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <span style="font-size:11px;font-weight:700;color:#92400e">متطلبات ZATCA — هيئة الزكاة والضريبة والجمارك</span>
                </div>
                <div class="row g-2">
                  <div class="col-md-3">
                    <label class="form-label" style="font-size:11px;font-weight:700">نوع الفاتورة *</label>
                    <select name="invoice_type" class="form-select form-select-sm">
                      <option value="simplified" <?= ($edit['invoice_type']??'simplified')==='simplified'?'selected':'' ?>>مبسّطة — B2C</option>
                      <option value="standard"   <?= ($edit['invoice_type']??'')==='standard'?'selected':'' ?>>ضريبية — B2B</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label" style="font-size:11px;font-weight:700">تاريخ الإصدار *</label>
                    <input type="date" name="issue_date" class="form-control form-control-sm"
                           value="<?= e($edit['issue_date'] ?? date('Y-m-d')) ?>" required>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label" style="font-size:11px;font-weight:700">تاريخ التوريد *</label>
                    <input type="date" name="supply_date" class="form-control form-control-sm"
                           value="<?= e($edit['supply_date'] ?? date('Y-m-d')) ?>" required>
                  </div>
                  <div class="col-md-3" id="buyer_vat_col">
                    <label class="form-label" style="font-size:11px;font-weight:700">الرقم الضريبي للعميل</label>
                    <input type="text" name="buyer_vat" class="form-control form-control-sm"
                           placeholder="3XXXXXXXXXXXXXXXXXXX3"
                           value="<?= e($edit['buyer_vat'] ?? '') ?>">
                    <div style="font-size:9px;color:#92400e">مطلوب للفاتورة الضريبية B2B</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- المعلومات الأساسية -->
            <div class="col-md-4">
              <label class="form-label">رقم الفاتورة</label>
              <input type="text" name="invoice_number" class="form-control"
                     value="<?= e($edit['invoice_number'] ?? $auto_number) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" id="inv_client_lbl">العميل</label>
              <select name="client_id" class="form-select" id="inv_client_sel">
                <option value="">— اختر العميل —</option>
                <?php
                $clients_list->data_seek(0);
                while ($cl2 = $clients_list->fetch_assoc()):
                ?>
                <option value="<?= $cl2['id'] ?>"
                        data-vat="<?= e($cl2['vat_number'] ?? '') ?>"
                        <?= ($edit ? ($edit['client_id']??'')==$cl2['id'] : $_new_for_client===(int)$cl2['id']) ? 'selected':'' ?>>
                  <?= e($cl2['full_name']) ?>
                </option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">القضية المرتبطة</label>
              <select name="case_id" class="form-select">
                <option value="">— اختياري —</option>
                <?php foreach ($cases_arr as $cid => $ctitle): ?>
                <option value="<?= $cid ?>" <?= ($edit['case_id']??'')==$cid?'selected':'' ?>>
                  <?= e($ctitle) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">عنوان الفاتورة *</label>
              <input type="text" name="title" class="form-control" required
                     value="<?= e($edit['title'] ?? '') ?>" placeholder="مثال: أتعاب تمثيل قانوني — قضية 2024/1547">
            </div>

            <!-- بنود الفاتورة -->
            <div class="col-12">
              <label class="form-label fw-bold">بنود الفاتورة</label>
              <div class="table-responsive">
                <table class="table table-bordered" style="font-size:13px">
                  <thead>
                    <tr style="background:#f8fafc">
                      <th>البيان</th>
                      <th style="width:80px">الكمية</th>
                      <th style="width:110px">سعر الوحدة</th>
                      <th style="width:110px">الإجمالي</th>
                      <th style="width:46px"></th>
                    </tr>
                  </thead>
                  <tbody id="invItemsBody">
                    <?php
                    $items_to_render = $edit['items_decoded'] ?? [['description'=>'','qty'=>1,'price'=>0]];
                    foreach ($items_to_render as $idx => $item):
                    ?>
                    <tr>
                      <td><input type="text" name="items[<?=$idx?>][description]" class="form-control form-control-sm"
                                 value="<?= e($item['description']) ?>" placeholder="وصف البند" required></td>
                      <td><input type="number" name="items[<?=$idx?>][qty]" class="form-control form-control-sm inv-qty"
                                 value="<?= e($item['qty'] ?? 1) ?>" min="1" step="1"></td>
                      <td><input type="number" name="items[<?=$idx?>][price]" class="form-control form-control-sm inv-price mk-currency"
                                 value="<?= e($item['price'] ?? 0) ?>" step="0.01"></td>
                      <td class="fw-semibold inv-total"><?= number_format(($item['qty']??1)*($item['price']??0), 2) ?></td>
                      <td>
                        <button type="button" class="btn btn-sm btn-outline-danger remove-row">
                          <i class="fas fa-times"></i>
                        </button>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <button type="button" id="addInvRow" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-plus me-1"></i>إضافة بند
              </button>
            </div>

            <!-- الملخص المالي -->
            <div class="col-md-6 offset-md-6">
              <div class="card" style="background:#f8fafc">
                <div class="card-body py-3 px-4">
                  <div class="d-flex justify-content-between mb-2" style="font-size:13.5px">
                    <span class="text-muted">المجموع الفرعي:</span>
                    <span class="fw-bold"><span id="invSubtotal"><?= number_format($edit['subtotal'] ?? 0,2) ?></span> ر.س</span>
                  </div>
                  <div class="d-flex align-items-center gap-2 mb-1" style="font-size:13.5px">
                    <span class="text-muted flex-shrink-0">الخصم:</span>
                    <input type="number" id="invDiscount" name="discount_value" class="form-control form-control-sm"
                           style="width:80px;text-align:left" value="<?= $edit['discount_value'] ?? ($edit['discount'] ?? 0) ?>" step="0.01" min="0">
                    <select id="invDiscountType" name="discount_type" class="form-select form-select-sm" style="width:90px">
                      <option value="fixed"   <?= ($edit['discount_type'] ?? 'fixed')==='fixed'?'selected':'' ?>>ر.س</option>
                      <option value="percent" <?= ($edit['discount_type'] ?? '')==='percent'?'selected':'' ?>>%</option>
                    </select>
                  </div>
                  <div class="text-muted mb-2" style="font-size:11.5px;text-align:left"><span id="invDiscountResolved"></span></div>
                  <div class="d-flex align-items-center gap-2 mb-2" style="font-size:13.5px">
                    <span class="text-muted flex-shrink-0">ضريبة القيمة المضافة (%):</span>
                    <input type="number" name="tax_rate" id="invTaxRate" class="form-control form-control-sm"
                           style="width:70px;text-align:left" value="<?= $edit['tax_rate'] ?? 15 ?>" step="0.01" min="0">
                  </div>
                  <div class="d-flex justify-content-between mb-2" style="font-size:13.5px">
                    <span class="text-muted">ضريبة القيمة المضافة:</span>
                    <span><span id="invTaxAmount"><?= number_format($edit['tax_amount'] ?? 0,2) ?></span> ر.س</span>
                  </div>
                  <hr class="my-2">
                  <div class="d-flex justify-content-between">
                    <span class="fw-bold fs-6">الإجمالي:</span>
                    <span class="fw-bold fs-5 text-primary"><span id="invTotal"><?= number_format($edit['total'] ?? 0,2) ?></span> ر.س</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- الحالة والاستحقاق -->
            <div class="col-md-4">
              <label class="form-label">الحالة</label>
              <select name="status" class="form-select">
                <?php foreach(['draft'=>'مسودة','sent'=>'مُرسلة','paid'=>'مدفوعة','cancelled'=>'ملغاة'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= ($edit['status']??'draft')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">تاريخ الاستحقاق</label>
              <input type="datetime-local" name="due_date" class="form-control"
                     value="<?= $edit && $edit['due_date'] ? date('Y-m-d\TH:i', strtotime($edit['due_date'])) : '' ?>">
            </div>
            <div class="col-12">
              <label class="form-label">ملاحظات</label>
              <textarea name="notes" class="form-control" rows="2"
                        placeholder="ملاحظات تظهر في الفاتورة..."><?= e($edit['notes'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ الفاتورة</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// اختيار نوع القيد المالي
function pickDir(val) {
  document.getElementById('directionInput').value = val;
  var lbl = document.getElementById('inv_client_lbl');
  if (lbl) lbl.textContent = (val === 'expense') ? 'الجهة / المورد' : 'العميل';
  var income  = document.getElementById('card_income');
  var expense = document.getElementById('card_expense');
  if (val === 'income') {
    income.style.borderColor  = '#16a34a';
    income.style.background   = '#f0fdf4';
    expense.style.borderColor = '#dee2e6';
    expense.style.background  = '#fff';
  } else {
    expense.style.borderColor = '#dc2626';
    expense.style.background  = '#fff5f5';
    income.style.borderColor  = '#dee2e6';
    income.style.background   = '#fff';
  }
}

// تعبئة الرقم الضريبي تلقائياً عند اختيار العميل
document.addEventListener('DOMContentLoaded', function() {
  var sel = document.getElementById('inv_client_sel');
  if (sel) {
    sel.addEventListener('change', function() {
      var opt = this.options[this.selectedIndex];
      var vat = opt.getAttribute('data-vat') || '';
      var vatField = document.querySelector('input[name="buyer_vat"]');
      if (vatField && !vatField.value) vatField.value = vat;
    });
  }
});

/* ── احتساب الفاتورة (مستقل — يعمل حتى لو mehkam.js قديم) ── */
(function () {
  function num(el){ return el ? (parseFloat(el.value) || 0) : 0; }
  function put(id, v){
    var e = document.getElementById(id);
    if (!e) return;
    if (e.tagName === 'INPUT') e.value = v.toFixed(2);
    else e.textContent = v.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
  }
  function recalc(){
    var body = document.getElementById('invItemsBody');
    if (!body) return;
    var sub = 0;
    body.querySelectorAll('tr').forEach(function(tr){
      var line = num(tr.querySelector('.inv-qty')) * num(tr.querySelector('.inv-price'));
      var c = tr.querySelector('.inv-total');
      if (c) c.textContent = line.toLocaleString('en-US', {minimumFractionDigits:2});
      sub += line;
    });
    var dv = num(document.getElementById('invDiscount'));
    var dt = (document.getElementById('invDiscountType') || {}).value || 'fixed';
    var disc = dt === 'percent' ? (sub * dv / 100) : dv;
    disc = Math.max(0, Math.min(disc, sub));
    var rate = num(document.getElementById('invTaxRate'));
    var taxable = Math.max(0, sub - disc);
    var tax = taxable * rate / 100;
    var total = taxable + tax;
    put('invSubtotal', sub); put('invSubtotalHidden', sub);
    put('invDiscountHidden', disc);
    put('invTaxAmount', tax); put('invTaxAmountHidden', tax);
    put('invTotal', total); put('invTotalHidden', total);
    var ds = document.getElementById('invDiscountResolved');
    if (ds) ds.textContent = dt === 'percent' ? ('= ' + disc.toFixed(2) + ' ر.س خصم') : '';
  }
  var form = document.querySelector('#invModal form');
  if (form) { form.addEventListener('input', recalc); form.addEventListener('change', recalc); }
  var m = document.getElementById('invModal');
  if (m) m.addEventListener('shown.bs.modal', recalc);
  document.addEventListener('DOMContentLoaded', recalc);
  setTimeout(recalc, 400);
})();
</script>
<?php if ($edit): ?>
<script>document.addEventListener('DOMContentLoaded',function(){
  new bootstrap.Modal(document.getElementById('invModal')).show();
  pickDir(<?= json_encode(($edit['direction'] ?? 'income') === 'expense' ? 'expense' : 'income') ?>);
});</script>
<?php elseif ($_new_for_client): ?>
<script>document.addEventListener('DOMContentLoaded',function(){
  new bootstrap.Modal(document.getElementById('invModal')).show();
  pickDir('income');
});</script>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
