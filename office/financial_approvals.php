<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('financial_approvals','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'financial_approvals')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'سلسلة اعتماد المصروفات';
$uid = (int)($_SESSION['user_id'] ?? 0);
$uname = $_SESSION['full_name'] ?? '';
$_canAdd = can('financial_approvals','add'); $_canDel = can('financial_approvals','delete');
$_canApprove = can('financial_approvals','approve'); // مستوى المدير: يعتمد أو يرفض طلبات الصرف
$_canFin = hasFeature($conn, $oid, 'has_finance') && can('finance','add');

$conn->query("CREATE TABLE IF NOT EXISTS expense_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    category VARCHAR(100) DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    description TEXT,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    requested_by INT DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    transaction_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حذف (طلب معلّق فقط، ومن قدّمه أو المدير) ── */
if (isset($_GET['delete']) && $_canDel) {
    $rid = (int)$_GET['delete'];
    $own = $_canApprove ? "" : " AND requested_by=$uid";
    $conn->query("DELETE FROM expense_approvals WHERE id=$rid AND office_id=$oid AND status='pending'$own");
    header("Location: financial_approvals.php?msg=deleted"); exit;
}

/* ── تقديم طلب صرف ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'request') {
    requirePerm('financial_approvals', 'add', 'financial_approvals.php?msg=denied');
    $title = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $category = $conn->real_escape_string(trim($_POST['category'] ?? ''));
    $amount = max(0, (float)($_POST['amount'] ?? 0));
    $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
    $conn->query("INSERT INTO expense_approvals (office_id,title,category,amount,description,requested_by) VALUES ($oid,'$title','$category',$amount,'$desc',".($uid ?: 'NULL').")");
    header("Location: financial_approvals.php?msg=saved"); exit;
}

/* ── اعتماد ── */
if (isset($_GET['approve']) && $_canApprove) {
    $rid = (int)$_GET['approve'];
    $req = $conn->query("SELECT * FROM expense_approvals WHERE id=$rid AND office_id=$oid AND status='pending'")->fetch_assoc();
    if ($req) {
        $txnId = 'NULL';
        if ($_canFin) {
            $descEsc = $conn->real_escape_string($req['title'] . ($req['description'] ? ' — ' . mb_substr($req['description'],0,150) : ''));
            $catEsc = $conn->real_escape_string($req['category'] ?: 'مصروف عام');
            $conn->query("INSERT INTO transactions (office_id,type,category,amount,description,payment_method,transaction_date,source)
                VALUES ($oid,'expense','$catEsc',".(float)$req['amount'].",'$descEsc','cash',CURDATE(),'manual')");
            $txnId = (int)$conn->insert_id;
        }
        $conn->query("UPDATE expense_approvals SET status='approved', reviewed_by=$uid, reviewed_at=NOW(), transaction_id=$txnId WHERE id=$rid");
    }
    header("Location: financial_approvals.php?msg=saved"); exit;
}
/* ── رفض ── */
if (isset($_GET['reject']) && $_canApprove) {
    $conn->query("UPDATE expense_approvals SET status='rejected', reviewed_by=$uid, reviewed_at=NOW() WHERE id=".(int)$_GET['reject']." AND office_id=$oid AND status='pending'");
    header("Location: financial_approvals.php?msg=saved"); exit;
}

/* ── بيانات — موظف عادي يشوف فقط طلباته؛ المدير يشوف الكل ── */
$_ownScope = !$_canApprove ? " AND e.requested_by=$uid" : "";
$requests = [];
$rr = $conn->query("SELECT e.*, u.full_name requester_name, r.full_name reviewer_name FROM expense_approvals e
    LEFT JOIN users u ON e.requested_by=u.id LEFT JOIN users r ON e.reviewed_by=r.id
    WHERE e.office_id=$oid$_ownScope ORDER BY e.created_at DESC LIMIT 300");
if ($rr) while ($r = $rr->fetch_assoc()) $requests[] = $r;

$pending_total = 0; $approved_total = 0;
foreach ($requests as $r) {
    if ($r['status']==='pending') $pending_total += (float)$r['amount'];
    if ($r['status']==='approved') $approved_total += (float)$r['amount'];
}
$_statusMap = ['pending'=>['قيد المراجعة','warning'],'approved'=>['معتمد','success'],'rejected'=>['مرفوض','danger']];

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-money-check-dollar"></i> سلسلة اعتماد المصروفات</div>
<div class="mk-page-sub mb-3">قدّم طلب صرف واتركه ينتظر اعتماد المدير قبل تسجيله كمصروف فعلي</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#fef3c7;color:#d97706"><i class="fas fa-hourglass-half"></i></div>
      <div><div class="fw-bold"><?= number_format($pending_total,0) ?> ر.س</div><div class="text-muted" style="font-size:11px">بانتظار الاعتماد</div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#f0fdf4;color:#16a34a"><i class="fas fa-check-circle"></i></div>
      <div><div class="fw-bold"><?= number_format($approved_total,0) ?> ر.س</div><div class="text-muted" style="font-size:11px">مُعتمَد</div></div>
    </div></div>
  </div>
</div>

<div class="d-flex justify-content-end mb-3">
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#reqModal"><i class="fas fa-plus me-1"></i>طلب صرف جديد</button>
  <?php endif; ?>
</div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>الطلب</th><th>الفئة</th><th>المبلغ</th><?php if ($_canApprove): ?><th>مقدّم الطلب</th><?php endif; ?><th>الحالة</th><th>المراجع</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$requests): ?><tr><td colspan="7" class="text-center text-muted py-4">لا توجد طلبات صرف بعد</td></tr>
<?php else: foreach ($requests as $r): $sm = $_statusMap[$r['status']] ?? ['—','secondary']; ?>
<tr>
  <td class="fw-semibold"><?= e($r['title']) ?><?php if ($r['description']): ?><br><small class="text-muted"><?= e(mb_substr($r['description'],0,60)) ?></small><?php endif; ?></td>
  <td style="font-size:12px"><?= e($r['category'] ?: '—') ?></td>
  <td class="fw-bold"><?= number_format((float)$r['amount'],2) ?> ر.س</td>
  <?php if ($_canApprove): ?><td style="font-size:12px"><?= e($r['requester_name'] ?: '—') ?></td><?php endif; ?>
  <td><span class="badge bg-<?= $sm[1] ?> bg-opacity-10 text-<?= $sm[1] ?>"><?= $sm[0] ?></span></td>
  <td style="font-size:12px"><?= e($r['reviewer_name'] ?: '—') ?></td>
  <td class="d-flex gap-1">
    <?php if ($r['status']==='pending' && $_canApprove): ?>
    <a href="financial_approvals.php?approve=<?= $r['id'] ?>" class="btn btn-sm btn-outline-success" onclick="return confirm('اعتماد هذا الطلب؟')"><i class="fas fa-check"></i></a>
    <a href="financial_approvals.php?reject=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('رفض هذا الطلب؟')"><i class="fas fa-xmark"></i></a>
    <?php endif; ?>
    <?php if ($r['status']==='pending' && $_canDel && ($_canApprove || $r['requested_by']==$uid)): ?>
    <a href="financial_approvals.php?delete=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary" onclick="return confirm('حذف هذا الطلب؟')"><i class="fas fa-trash"></i></a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php if (!$_canFin): ?>
<div class="alert alert-info mt-3 mb-0" style="font-size:12px"><i class="fas fa-circle-info me-1"></i>لتسجيل المصروفات المعتمدة تلقائياً في الشؤون المالية، تحتاج صلاحية «إضافة» في قسم الشؤون المالية.</div>
<?php endif; ?>

<!-- Modal -->
<div class="modal fade" id="reqModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-money-check-dollar me-2"></i>طلب صرف جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="request">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">عنوان المصروف *</label><input type="text" name="title" class="form-control" required placeholder="مثال: رسوم قيد قضية"></div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">الفئة</label><input type="text" name="category" class="form-control" placeholder="مثال: رسوم حكومية"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">المبلغ (ر.س) *</label><input type="number" name="amount" class="form-control" min="0.01" step="0.01" required></div>
      </div>
      <div class="mb-1"><label class="form-label fw-semibold">تفاصيل إضافية</label><textarea name="description" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>إرسال للاعتماد</button></div>
  </form>
</div></div></div>

<?php include '../includes/office_footer.php'; ?>
