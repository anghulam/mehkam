<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
// تصدير بيانات المكتب بالكامل عملية حساسة — تبقى محصورة بمالك المكتب دائماً، بمعزل عن مصفوفة الصلاحيات العادية
if (currentRole() !== 'office_owner') { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'data_export')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'مركز التصدير والنسخ الاحتياطي';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_hasFin = hasFeature($conn, $oid, 'has_finance');

$conn->query("CREATE TABLE IF NOT EXISTS data_exports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    export_type VARCHAR(50) NOT NULL,
    row_count INT DEFAULT 0,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$EXPORTS = [
    'clients'      => ['label' => 'العملاء',          'icon' => 'address-book',        'sql' => "SELECT id,full_name,id_number,phone,email,city,client_type,vat_number,cr_number,created_at FROM clients WHERE office_id=$oid ORDER BY id"],
    'cases'        => ['label' => 'القضايا',           'icon' => 'gavel',               'sql' => "SELECT id,case_number,title,case_type,court_name,status,priority,next_session,fees,paid_amount,created_at FROM cases WHERE office_id=$oid ORDER BY id"],
    'contracts'    => ['label' => 'العقود والوكالات',  'icon' => 'file-signature',      'sql' => "SELECT id,contract_number,title,contract_type,start_date,end_date,value,status,created_at FROM contracts WHERE office_id=$oid ORDER BY id"],
    'invoices'     => ['label' => 'الفواتير',          'icon' => 'file-invoice-dollar', 'sql' => "SELECT id,invoice_number,title,subtotal,tax_amount,total,status,issue_date,due_date,created_at FROM invoices WHERE office_id=$oid ORDER BY id"],
    'transactions' => ['label' => 'المعاملات المالية', 'icon' => 'coins',               'sql' => "SELECT id,type,category,amount,description,payment_method,transaction_date,created_at FROM transactions WHERE office_id=$oid ORDER BY id"],
];
if (!$_hasFin) unset($EXPORTS['transactions']);

/* ── تنزيل CSV ── */
if (isset($_GET['export']) && isset($EXPORTS[$_GET['export']])) {
    $key = $_GET['export'];
    $res = $conn->query($EXPORTS[$key]['sql']);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $key . '_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM ليقرأ Excel العربي بشكل صحيح
    $out = fopen('php://output', 'w');
    $n = 0;
    if ($res && $row = $res->fetch_assoc()) {
        fputcsv($out, array_keys($row));
        do { fputcsv($out, $row); $n++; } while ($row = $res->fetch_assoc());
    }
    fclose($out);
    $conn->query("INSERT INTO data_exports (office_id,export_type,row_count,created_by) VALUES ($oid,'$key',$n,".($uid ?: 'NULL').")");
    exit;
}

$history = [];
$hr = $conn->query("SELECT * FROM data_exports WHERE office_id=$oid ORDER BY id DESC LIMIT 20");
if ($hr) while ($x = $hr->fetch_assoc()) $history[] = $x;

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-database"></i> مركز التصدير والنسخ الاحتياطي</div>
<div class="mk-page-sub mb-3">صدّر بيانات مكتبك بصيغة CSV تفتح مباشرة في Excel — نسخة احتياطية لديك بمعزل عن السحابة</div>

<div class="alert alert-warning" style="font-size:12.5px"><i class="fas fa-shield-halved me-1"></i>الملفات المُصدَّرة تحتوي بيانات عملائك ومكتبك الحساسة — احفظها في مكان آمن ولا تشاركها إلا عند الحاجة.</div>

<div class="row g-3 mb-4">
<?php foreach ($EXPORTS as $key => $ex): ?>
<div class="col-md-6 col-lg-4"><div class="card h-100"><div class="card-body text-center">
  <div class="mb-2" style="font-size:26px;color:#0c1b36"><i class="fas fa-<?= $ex['icon'] ?>"></i></div>
  <div class="fw-bold mb-2"><?= e($ex['label']) ?></div>
  <a href="data_export.php?export=<?= $key ?>" class="btn btn-primary btn-sm w-100"><i class="fas fa-download me-1"></i>تنزيل CSV</a>
</div></div></div>
<?php endforeach; ?>
</div>

<div class="card"><div class="card-header fw-bold"><i class="fas fa-clock-rotate-left me-1"></i>سجل عمليات التصدير</div>
  <div class="table-responsive"><table class="table table-sm mb-0" style="font-size:13px">
  <thead><tr><th>النوع</th><th>عدد السجلات</th><th>التاريخ</th></tr></thead><tbody>
  <?php if (!$history): ?><tr><td colspan="3" class="text-center text-muted py-4">لا توجد عمليات تصدير بعد</td></tr>
  <?php else: foreach ($history as $h): ?>
  <tr><td><?= e($EXPORTS[$h['export_type']]['label'] ?? $h['export_type']) ?></td><td><?= (int)$h['row_count'] ?></td><td><?= e(date('Y-m-d H:i', strtotime($h['created_at']))) ?></td></tr>
  <?php endforeach; endif; ?>
  </tbody></table></div>
</div>

<?php include '../includes/office_footer.php'; ?>
