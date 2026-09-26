<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('trust_accounts','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'trust_accounts')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'حسابات الأمانات';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('trust_accounts','add'); $_canDel = can('trust_accounts','delete');
$_canApprove = can('trust_accounts','approve'); // مستوى المدير: يرى كل الحركات ويعتمد السحوبات

$conn->query("CREATE TABLE IF NOT EXISTS trust_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    client_id INT NOT NULL,
    case_id INT DEFAULT NULL,
    entry_type ENUM('deposit','withdrawal') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    method ENUM('cash','bank_transfer','check','card') DEFAULT 'bank_transfer',
    reference VARCHAR(120) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    entry_date DATE NOT NULL,
    status ENUM('posted','pending','rejected') DEFAULT 'posted',
    created_by INT DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id), INDEX idx_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/** رصيد أمانات عميل = الإيداعات المرحّلة − السحوبات المرحّلة */
function trust_balance($conn, $oid, $client_id) {
    $r = $conn->query("SELECT COALESCE(SUM(CASE WHEN entry_type='deposit' THEN amount ELSE -amount END),0) b
        FROM trust_ledger WHERE office_id=$oid AND client_id=".(int)$client_id." AND status='posted'")->fetch_assoc();
    return (float)($r['b'] ?? 0);
}

$_ownScope = !$_canApprove ? " AND created_by=$uid" : "";

/* ── حذف (حركة معلّقة فقط؛ الحركات المرحّلة لا تُحذف حفاظاً على سلامة الحساب) ── */
if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM trust_ledger WHERE id=".(int)$_GET['delete']." AND office_id=$oid AND status='pending'$_ownScope");
    header("Location: trust_accounts.php?msg=deleted"); exit;
}

/* ── تسجيل حركة ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'entry') {
    requirePerm('trust_accounts', 'add', 'trust_accounts.php?msg=denied');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $chk = $conn->query("SELECT id FROM clients WHERE id=$client_id AND office_id=$oid")->fetch_assoc();
    $type = ($_POST['entry_type'] ?? '') === 'withdrawal' ? 'withdrawal' : 'deposit';
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    if (!$chk || $amount <= 0) { header("Location: trust_accounts.php?msg=invalid"); exit; }
    $case_id = !empty($_POST['case_id']) ? (int)$_POST['case_id'] : 'NULL';
    $method = in_array($_POST['method'] ?? '', ['cash','bank_transfer','check','card'], true) ? $_POST['method'] : 'bank_transfer';
    $ref = $conn->real_escape_string(trim($_POST['reference'] ?? ''));
    $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['entry_date'] ?? '') ? $_POST['entry_date'] : date('Y-m-d');

    if ($type === 'deposit') {
        $status = 'posted'; $appr = 'NULL';
    } elseif ($_canApprove) {
        if ($amount > trust_balance($conn, $oid, $client_id)) { header("Location: trust_accounts.php?msg=insufficient"); exit; }
        $status = 'posted'; $appr = $uid ?: 'NULL';
    } else {
        $status = 'pending'; $appr = 'NULL'; // السحب يحتاج اعتماد المدير
    }
    $conn->query("INSERT INTO trust_ledger (office_id,client_id,case_id,entry_type,amount,method,reference,description,entry_date,status,created_by,approved_by,approved_at)
        VALUES ($oid,$client_id,$case_id,'$type',$amount,'$method','$ref','$desc','$date','$status',".($uid ?: 'NULL').",$appr,".($status==='posted' && $type==='withdrawal' ? 'NOW()' : 'NULL').")");
    header("Location: trust_accounts.php?msg=" . ($status === 'pending' ? 'pending' : 'saved')); exit;
}

/* ── اعتماد / رفض سحب (مستوى المدير) ── */
if (isset($_GET['approve']) && $_canApprove) {
    $rid = (int)$_GET['approve'];
    $row = $conn->query("SELECT * FROM trust_ledger WHERE id=$rid AND office_id=$oid AND status='pending' AND entry_type='withdrawal'")->fetch_assoc();
    if ($row) {
        if ((float)$row['amount'] > trust_balance($conn, $oid, (int)$row['client_id'])) { header("Location: trust_accounts.php?msg=insufficient"); exit; }
        $conn->query("UPDATE trust_ledger SET status='posted', approved_by=$uid, approved_at=NOW() WHERE id=$rid");
    }
    header("Location: trust_accounts.php?msg=saved"); exit;
}
if (isset($_GET['reject']) && $_canApprove) {
    $conn->query("UPDATE trust_ledger SET status='rejected', approved_by=$uid, approved_at=NOW() WHERE id=".(int)$_GET['reject']." AND office_id=$oid AND status='pending'");
    header("Location: trust_accounts.php?msg=saved"); exit;
}

/* ── كشف حساب قابل للطباعة ── */
if (isset($_GET['statement'])) {
    $sid = (int)$_GET['statement'];
    $cl = $conn->query("SELECT full_name FROM clients WHERE id=$sid AND office_id=$oid")->fetch_assoc();
    $ents = [];
    if ($cl) {
        $er = $conn->query("SELECT * FROM trust_ledger WHERE office_id=$oid AND client_id=$sid AND status='posted'$_ownScope ORDER BY entry_date ASC, id ASC");
        if ($er) while ($x = $er->fetch_assoc()) $ents[] = $x;
    }
    if (!$cl || !$ents) { header("Location: trust_accounts.php?msg=invalid"); exit; }
    $officeName = $_SESSION['office_name'] ?? '';
    ?><!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>كشف أمانات — <?= e($cl['full_name']) ?></title>
<style>body{font-family:Tahoma,Arial,sans-serif;margin:30px;color:#111}h2{margin:0 0 4px}table{width:100%;border-collapse:collapse;margin-top:16px;font-size:13px}th,td{border:1px solid #999;padding:6px 8px;text-align:right}th{background:#eee}.r{text-align:left;direction:ltr}.tot{font-weight:bold;background:#f6f6f6}@media print{.np{display:none}}</style></head><body>
<button class="np" onclick="window.print()">طباعة</button>
<h2>كشف حساب أمانات</h2><div><?= e($officeName) ?></div><div>العميل: <b><?= e($cl['full_name']) ?></b> — بتاريخ <?= date('Y-m-d') ?></div>
<table><tr><th>التاريخ</th><th>البيان</th><th>النوع</th><th>إيداع</th><th>سحب</th><th>الرصيد</th></tr>
<?php $run = 0; foreach ($ents as $x): $dep = $x['entry_type']==='deposit'; $run += $dep ? (float)$x['amount'] : -(float)$x['amount']; ?>
<tr><td><?= e($x['entry_date']) ?></td><td><?= e($x['description'] ?: '—') ?><?= $x['reference'] ? ' ('.e($x['reference']).')' : '' ?></td><td><?= $dep ? 'إيداع' : 'سحب' ?></td>
<td class="r"><?= $dep ? number_format((float)$x['amount'],2) : '' ?></td><td class="r"><?= !$dep ? number_format((float)$x['amount'],2) : '' ?></td><td class="r"><?= number_format($run,2) ?></td></tr>
<?php endforeach; ?>
<tr class="tot"><td colspan="5">الرصيد الحالي (ر.س)</td><td class="r"><?= number_format($run,2) ?></td></tr></table></body></html><?php
    exit;
}

/* ── بيانات الصفحة ── */
$clients_arr = [];
$cr = $conn->query("SELECT id, full_name FROM clients WHERE office_id=$oid ORDER BY full_name LIMIT 500");
if ($cr) while ($r = $cr->fetch_assoc()) $clients_arr[$r['id']] = $r['full_name'];

$cases_arr = [];
$ccr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid" . caseScope('cases') . " ORDER BY id DESC LIMIT 300");
if ($ccr) while ($r = $ccr->fetch_assoc()) $cases_arr[$r['id']] = $r['case_number'] . ' — ' . mb_substr($r['title'], 0, 40);

$filter_client = (int)($_GET['client'] ?? 0);
$where = "t.office_id=$oid" . ($_canApprove ? "" : " AND t.created_by=$uid") . ($filter_client ? " AND t.client_id=$filter_client" : "");
$entries = [];
$er = $conn->query("SELECT t.*, c.full_name client_name, u.full_name creator_name FROM trust_ledger t
    LEFT JOIN clients c ON t.client_id=c.id LEFT JOIN users u ON t.created_by=u.id
    WHERE $where ORDER BY t.created_at DESC LIMIT 300");
if ($er) while ($r = $er->fetch_assoc()) $entries[] = $r;

// أرصدة العملاء (مرحّلة فقط) — الموظف العادي يرى أرصدة العملاء الذين سجّل لهم حركات
$balances = [];
$br = $conn->query("SELECT client_id, SUM(CASE WHEN entry_type='deposit' THEN amount ELSE -amount END) bal
    FROM trust_ledger WHERE office_id=$oid AND status='posted'" . ($_canApprove ? "" : " AND client_id IN (SELECT DISTINCT client_id FROM trust_ledger WHERE office_id=$oid AND created_by=$uid)") . "
    GROUP BY client_id HAVING bal<>0 ORDER BY bal DESC");
if ($br) while ($r = $br->fetch_assoc()) $balances[$r['client_id']] = (float)$r['bal'];
$total_held = array_sum($balances);
$pending_sum = 0; $pending_cnt = 0;
foreach ($entries as $x) if ($x['status']==='pending') { $pending_sum += (float)$x['amount']; $pending_cnt++; }

$_stMap = ['posted'=>['مُرحَّلة','success'],'pending'=>['بانتظار الاعتماد','warning'],'rejected'=>['مرفوضة','danger']];
$_mtMap = ['cash'=>'نقداً','bank_transfer'=>'تحويل بنكي','check'=>'شيك','card'=>'بطاقة'];

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-vault"></i> حسابات الأمانات</div>
<div class="mk-page-sub mb-3">سجل أموال العملاء المودعة لدى المكتب — إيداعات وسحوبات بأرصدة دقيقة، والسحب يحتاج اعتماد المدير</div>

<?php if (isset($_GET['msg'])):
  $mm = ['saved'=>['success','تم الحفظ بنجاح'],'deleted'=>['success','تم الحذف'],'pending'=>['warning','سُجّل طلب السحب وينتظر اعتماد المدير'],
         'insufficient'=>['danger','المبلغ أكبر من رصيد أمانات هذا العميل'],'invalid'=>['danger','بيانات غير صحيحة أو لا توجد حركات'],'denied'=>['danger','ليست لديك صلاحية لهذا الإجراء']];
  $m = $mm[$_GET['msg']] ?? ['success','تم بنجاح']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
    <div class="fw-bold" style="font-size:20px"><?= number_format($total_held,2) ?> <small>ر.س</small></div><div class="text-muted" style="font-size:12px">إجمالي الأمانات المحتفَظ بها</div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
    <div class="fw-bold" style="font-size:20px"><?= count($balances) ?></div><div class="text-muted" style="font-size:12px">عملاء لديهم رصيد</div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
    <div class="fw-bold text-warning" style="font-size:20px"><?= $pending_cnt ?> <small>(<?= number_format($pending_sum,2) ?>)</small></div><div class="text-muted" style="font-size:12px">سحوبات بانتظار الاعتماد</div>
  </div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card"><div class="card-header fw-bold">أرصدة العملاء</div>
      <div class="list-group list-group-flush">
      <?php if (!$balances): ?><div class="list-group-item text-muted text-center py-4">لا توجد أرصدة</div>
      <?php else: foreach ($balances as $cid => $bal): ?>
        <div class="list-group-item d-flex justify-content-between align-items-center">
          <div><a href="trust_accounts.php?client=<?= $cid ?>" class="fw-semibold text-decoration-none"><?= e($clients_arr[$cid] ?? ('#'.$cid)) ?></a></div>
          <div class="d-flex align-items-center gap-2"><span class="fw-bold <?= $bal<0?'text-danger':'' ?>"><?= number_format($bal,2) ?></span>
            <a href="trust_accounts.php?statement=<?= $cid ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="كشف حساب"><i class="fas fa-print"></i></a></div>
        </div>
      <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div class="fw-bold"><i class="fas fa-list me-1"></i>الحركات<?= $filter_client ? ' — '.e($clients_arr[$filter_client] ?? '') : '' ?>
        <?php if ($filter_client): ?><a href="trust_accounts.php" class="btn btn-sm btn-outline-secondary ms-2">إزالة الفلتر</a><?php endif; ?></div>
      <?php if ($_canAdd): ?><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#entryModal"><i class="fas fa-plus me-1"></i>حركة جديدة</button><?php endif; ?>
    </div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive">
      <table class="table table-hover mb-0" style="font-size:13px">
        <thead><tr><th>التاريخ</th><th>العميل</th><th>النوع</th><th>المبلغ</th><th>الحالة</th><?php if ($_canApprove): ?><th>سجّلها</th><?php endif; ?><th></th></tr></thead>
        <tbody>
        <?php if (!$entries): ?><tr><td colspan="7" class="text-center text-muted py-4">لا توجد حركات</td></tr>
        <?php else: foreach ($entries as $x): $st = $_stMap[$x['status']]; $dep = $x['entry_type']==='deposit'; ?>
        <tr>
          <td><?= e($x['entry_date']) ?></td>
          <td class="fw-semibold"><?= e($x['client_name'] ?: '—') ?><br><small class="text-muted"><?= e($_mtMap[$x['method']] ?? '') ?><?= $x['reference'] ? ' · '.e($x['reference']) : '' ?></small></td>
          <td><span class="badge bg-<?= $dep?'success':'secondary' ?> bg-opacity-10 text-<?= $dep?'success':'secondary' ?>"><?= $dep?'إيداع':'سحب' ?></span></td>
          <td class="fw-bold"><?= number_format((float)$x['amount'],2) ?></td>
          <td><span class="badge bg-<?= $st[1] ?> bg-opacity-10 text-<?= $st[1] ?>"><?= $st[0] ?></span></td>
          <?php if ($_canApprove): ?><td style="font-size:12px"><?= e($x['creator_name'] ?: '—') ?></td><?php endif; ?>
          <td class="d-flex gap-1">
            <?php if ($x['status']==='pending' && $_canApprove): ?>
              <a href="trust_accounts.php?approve=<?= $x['id'] ?>" class="btn btn-sm btn-outline-success" onclick="return confirm('اعتماد هذا السحب؟')"><i class="fas fa-check"></i></a>
              <a href="trust_accounts.php?reject=<?= $x['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('رفض هذا السحب؟')"><i class="fas fa-xmark"></i></a>
            <?php endif; ?>
            <?php if ($x['status']==='pending' && $_canDel && ($_canApprove || $x['created_by']==$uid)): ?>
              <a href="trust_accounts.php?delete=<?= $x['id'] ?>" class="btn btn-sm btn-outline-secondary" onclick="return confirm('حذف هذا الطلب؟')"><i class="fas fa-trash"></i></a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div></div></div>
  </div>
</div>

<div class="modal fade" id="entryModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-vault me-2"></i>حركة أمانات جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="entry">
    <div class="modal-body">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label fw-semibold">النوع *</label>
          <select name="entry_type" class="form-select"><option value="deposit">إيداع (استلام مبلغ من العميل)</option><option value="withdrawal">سحب (صرف من أمانات العميل)</option></select></div>
        <div class="col-md-6"><label class="form-label fw-semibold">المبلغ (ر.س) *</label><input type="number" name="amount" class="form-control" min="0.01" step="0.01" required></div>
        <div class="col-12"><label class="form-label fw-semibold">العميل *</label>
          <select name="client_id" class="form-select" required><option value="">— اختر —</option>
            <?php foreach ($clients_arr as $cid => $cn): ?><option value="<?= $cid ?>" <?= $filter_client==$cid?'selected':'' ?>><?= e($cn) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label fw-semibold">القضية (اختياري)</label>
          <select name="case_id" class="form-select"><option value="">— بدون —</option>
            <?php foreach ($cases_arr as $csid => $csn): ?><option value="<?= $csid ?>"><?= e($csn) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label fw-semibold">طريقة الدفع</label>
          <select name="method" class="form-select"><?php foreach ($_mtMap as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label fw-semibold">التاريخ</label><input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">رقم مرجع/إيصال</label><input type="text" name="reference" class="form-control"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">البيان</label><input type="text" name="description" class="form-control" placeholder="مثال: رسوم قيد دعوى"></div>
      </div>
      <?php if (!$_canApprove): ?><div class="form-text mt-2"><i class="fas fa-info-circle me-1"></i>طلبات السحب تُرفع للمدير للاعتماد قبل أن تُخصم من رصيد العميل.</div><?php endif; ?>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<?php include '../includes/office_footer.php'; ?>
