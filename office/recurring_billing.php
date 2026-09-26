<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/zatca_helper.php';
requireOffice();
if (!can('recurring_billing','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'recurring_billing')) { header('Location: dashboard.php?msg=denied'); exit; }
if (!hasFeature($conn, $oid, 'has_invoices')) { header('Location: dashboard.php?msg=feature_locked'); exit; }
$page_title = 'الفوترة المتكررة';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('recurring_billing','add'); $_canEdit = can('recurring_billing','edit'); $_canDel = can('recurring_billing','delete');

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

/** ينشئ فاتورة لخطة فوترة متكررة، بنفس أسلوب svc_make_invoice في digital_services.php */
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
            VALUES ($oid,$cid,'$invNum','$titleEsc','$itemsEsc',$amount,0,'fixed',0,15,$vat,$total,'sent',NULL,'اشتراك متكرر','income',
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

/** يحسب تاريخ الدورة القادمة */
function rb_next_date($date, $cycle) {
    $add = ['monthly'=>'+1 month','quarterly'=>'+3 months','yearly'=>'+1 year'][$cycle] ?? '+1 month';
    return date('Y-m-d', strtotime($date . ' ' . $add));
}

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM recurring_billing WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header("Location: recurring_billing.php?msg=deleted"); exit;
}
/* ── تفعيل/تعطيل ── */
if (isset($_GET['toggle']) && $_canEdit) {
    $conn->query("UPDATE recurring_billing SET is_active=1-is_active WHERE id=".(int)$_GET['toggle']." AND office_id=$oid");
    header("Location: recurring_billing.php?msg=saved"); exit;
}
/* ── تشغيل الآن (يدوي) لخطة واحدة ── */
if (isset($_GET['run_now']) && $_canAdd) {
    $pid = (int)$_GET['run_now'];
    $plan = $conn->query("SELECT * FROM recurring_billing WHERE id=$pid AND office_id=$oid AND is_active=1")->fetch_assoc();
    if ($plan) {
        $invId = rb_make_invoice($conn, $oid, $plan);
        if ($invId) {
            $nextDate = rb_next_date(date('Y-m-d'), $plan['billing_cycle']);
            $conn->query("UPDATE recurring_billing SET last_run_date=CURDATE(), next_run_date='$nextDate' WHERE id=$pid");
        }
    }
    header("Location: recurring_billing.php?msg=saved"); exit;
}

/* ── حفظ خطة ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'plan') {
    $pid = (int)($_POST['id'] ?? 0);
    requirePerm('recurring_billing', $pid ? 'edit' : 'add', 'recurring_billing.php?msg=denied');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $title = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $amount = max(0, (float)($_POST['amount'] ?? 0));
    $cycle = in_array($_POST['billing_cycle'] ?? '', ['monthly','quarterly','yearly'], true) ? $_POST['billing_cycle'] : 'monthly';
    $next_run = trim($_POST['next_run_date'] ?? '') !== '' ? $conn->real_escape_string($_POST['next_run_date']) : date('Y-m-d');
    $chk = $conn->query("SELECT id FROM clients WHERE id=$client_id AND office_id=$oid")->num_rows;
    if ($chk) {
        if ($pid) {
            $conn->query("UPDATE recurring_billing SET client_id=$client_id,title='$title',amount=$amount,billing_cycle='$cycle',next_run_date='$next_run' WHERE id=$pid AND office_id=$oid");
        } else {
            $conn->query("INSERT INTO recurring_billing (office_id,client_id,title,amount,billing_cycle,next_run_date,created_by) VALUES ($oid,$client_id,'$title',$amount,'$cycle','$next_run',".($uid ?: 'NULL').")");
        }
    }
    header("Location: recurring_billing.php?msg=saved"); exit;
}

/* ── بيانات ── */
$plans = [];
$pr = $conn->query("SELECT p.*, cl.full_name client_name FROM recurring_billing p LEFT JOIN clients cl ON p.client_id=cl.id WHERE p.office_id=$oid ORDER BY p.id DESC");
if ($pr) while ($r = $pr->fetch_assoc()) $plans[] = $r;

$clients_arr = [];
$clr = $conn->query("SELECT id, full_name FROM clients WHERE office_id=$oid ORDER BY full_name");
if ($clr) while ($r = $clr->fetch_assoc()) $clients_arr[$r['id']] = $r['full_name'];

$_cycleMap = ['monthly'=>'شهرياً','quarterly'=>'كل 3 أشهر','yearly'=>'سنوياً'];
$total_monthly_recurring = 0;
foreach ($plans as $p) {
    if (!$p['is_active']) continue;
    $factor = ['monthly'=>1,'quarterly'=>1/3,'yearly'=>1/12][$p['billing_cycle']] ?? 1;
    $total_monthly_recurring += (float)$p['amount'] * $factor;
}

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-arrows-rotate"></i> الفوترة المتكررة</div>
<div class="mk-page-sub mb-3">فواتير اشتراك دورية تلقائية لعملاء الأتعاب الثابتة (Retainer)</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#f0fdf4;color:#16a34a"><i class="fas fa-coins"></i></div>
      <div><div class="fw-bold"><?= number_format($total_monthly_recurring,0) ?> ر.س</div><div class="text-muted" style="font-size:11px">إيراد شهري متكرر متوقّع</div></div>
    </div></div>
  </div>
</div>

<div class="d-flex justify-content-end mb-3">
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#planModal" onclick="planNew()"><i class="fas fa-plus me-1"></i>خطة اشتراك جديدة</button>
  <?php endif; ?>
</div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>العميل</th><th>الوصف</th><th>المبلغ</th><th>الدورة</th><th>التشغيل القادم</th><th>آخر تشغيل</th><th>الحالة</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$plans): ?><tr><td colspan="8" class="text-center text-muted py-4">لا توجد خطط اشتراك بعد</td></tr>
<?php else: foreach ($plans as $p):
  $due = $p['is_active'] && strtotime($p['next_run_date']) <= time(); ?>
<tr>
  <td class="fw-semibold"><?= e($p['client_name'] ?: '—') ?></td>
  <td><?= e($p['title']) ?></td>
  <td class="fw-bold"><?= number_format((float)$p['amount'],2) ?> ر.س</td>
  <td><?= $_cycleMap[$p['billing_cycle']] ?? $p['billing_cycle'] ?></td>
  <td style="font-size:12px"><?= dDate($p['next_run_date'], false) ?> <?php if ($due): ?><span class="badge bg-warning bg-opacity-10 text-warning">مستحقّة</span><?php endif; ?></td>
  <td style="font-size:12px"><?= $p['last_run_date'] ? dDate($p['last_run_date'], false) : '—' ?></td>
  <td><?= $p['is_active'] ? '<span class="badge bg-success bg-opacity-10 text-success">مفعَّلة</span>' : '<span class="badge bg-secondary bg-opacity-10 text-secondary">متوقفة</span>' ?></td>
  <td class="d-flex gap-1">
    <?php if ($_canAdd && $p['is_active']): ?><a href="recurring_billing.php?run_now=<?= $p['id'] ?>" class="btn btn-sm btn-outline-success" title="تشغيل الآن" onclick="return confirm('إنشاء فاتورة الآن لهذا الاشتراك؟')"><i class="fas fa-play"></i></a><?php endif; ?>
    <?php if ($_canEdit): ?>
    <button class="btn btn-sm btn-outline-primary" onclick='planEdit(<?= json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
    <a href="recurring_billing.php?toggle=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= $p['is_active']?'تعطيل':'تفعيل' ?>"><i class="fas fa-power-off"></i></a>
    <?php endif; ?>
    <?php if ($_canDel): ?><a href="recurring_billing.php?delete=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذه الخطة؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<div class="alert alert-info mt-3 mb-0" style="font-size:12px"><i class="fas fa-circle-info me-1"></i>الفواتير المستحقة تُنشأ تلقائياً يومياً — أو اضغط «تشغيل الآن» لإنشائها فوراً لأي خطة.</div>

<!-- Modal -->
<div class="modal fade" id="planModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="planTitle"><i class="fas fa-arrows-rotate me-2"></i>خطة اشتراك جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="plan"><input type="hidden" name="id" id="pl_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">العميل *</label>
        <select name="client_id" id="pl_client" class="form-select" required><option value="">— اختر —</option>
          <?php foreach ($clients_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">وصف الاشتراك *</label><input type="text" name="title" id="pl_title" class="form-control" required placeholder="مثال: أتعاب استشارية شهرية"></div>
      <div class="row g-3 mb-3">
        <div class="col-md-4"><label class="form-label fw-semibold">المبلغ شامل الضريبة (ر.س)</label><input type="number" name="amount" id="pl_amount" class="form-control" min="0" step="0.01" required></div>
        <div class="col-md-4"><label class="form-label fw-semibold">الدورة</label>
          <select name="billing_cycle" id="pl_cycle" class="form-select">
            <option value="monthly">شهرياً</option><option value="quarterly">كل 3 أشهر</option><option value="yearly">سنوياً</option>
          </select>
        </div>
        <div class="col-md-4"><label class="form-label fw-semibold">أول تاريخ تشغيل</label><input type="date" name="next_run_date" id="pl_next" class="form-control" required></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<script>
function planNew(){document.getElementById('planTitle').innerHTML='<i class="fas fa-arrows-rotate me-2"></i>خطة اشتراك جديدة';document.getElementById('pl_id').value='';document.getElementById('pl_client').value='';document.getElementById('pl_title').value='';document.getElementById('pl_amount').value='';document.getElementById('pl_cycle').value='monthly';document.getElementById('pl_next').value=new Date().toISOString().slice(0,10);}
function planEdit(p){document.getElementById('planTitle').innerHTML='<i class="fas fa-edit me-2"></i>تعديل خطة';document.getElementById('pl_id').value=p.id;document.getElementById('pl_client').value=p.client_id;document.getElementById('pl_title').value=p.title;document.getElementById('pl_amount').value=p.amount;document.getElementById('pl_cycle').value=p.billing_cycle;document.getElementById('pl_next').value=p.next_run_date;new bootstrap.Modal(document.getElementById('planModal')).show();}
</script>

<?php include '../includes/office_footer.php'; ?>
