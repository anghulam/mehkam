<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('fee_quote','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'fee_quote')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'عروض الأتعاب الإلكترونية';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('fee_quote','add'); $_canDel = can('fee_quote','delete');

$conn->query("CREATE TABLE IF NOT EXISTS fee_quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    client_name VARCHAR(200) NOT NULL,
    client_phone VARCHAR(30) DEFAULT NULL,
    description TEXT,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('sent','accepted','declined') DEFAULT 'sent',
    resulting_contract_id INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM fee_quotes WHERE id=".(int)$_GET['delete']." AND office_id=$oid AND status='sent'");
    header('Location: fee_quote.php?msg=deleted'); exit;
}

/* ── إنشاء عرض ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'quote') {
    requirePerm('fee_quote', 'add', 'fee_quote.php?msg=denied');
    $name = trim($_POST['client_name'] ?? '');
    $amount = max(0, (float)($_POST['amount'] ?? 0));
    if ($name === '' || $amount <= 0) { header('Location: fee_quote.php?msg=invalid'); exit; }
    $ne = $conn->real_escape_string($name);
    $phone = $conn->real_escape_string(trim($_POST['client_phone'] ?? ''));
    $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
    $token = bin2hex(random_bytes(24));
    $conn->query("INSERT INTO fee_quotes (office_id,client_name,client_phone,description,amount,token,created_by) VALUES ($oid,'$ne','$phone','$desc',$amount,'$token',".($uid ?: 'NULL').")");
    header('Location: fee_quote.php?msg=saved'); exit;
}

$quotes = [];
$qr = $conn->query("SELECT * FROM fee_quotes WHERE office_id=$oid ORDER BY id DESC LIMIT 100");
if ($qr) while ($x = $qr->fetch_assoc()) $quotes[] = $x;
$_stMap = ['sent'=>['بانتظار الرد','warning'],'accepted'=>['مقبول','success'],'declined'=>['مرفوض','danger']];
$_base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/') . '/public/quote.php?t=';

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-file-invoice"></i> عروض الأتعاب الإلكترونية</div>
<div class="mk-page-sub mb-3">أرسل عرض سعر رسمي للعميل المحتمل برابط — عند موافقته يُنشأ عقد مبدئي تلقائياً تكمله من العقود</div>

<?php if (isset($_GET['msg'])):
  $mm = ['saved'=>['success','تم إنشاء العرض'],'deleted'=>['success','تم الحذف'],'invalid'=>['danger','أكمل اسم العميل والمبلغ'],'denied'=>['danger','ليست لديك صلاحية']];
  $m = $mm[$_GET['msg']] ?? ['success','تم']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($_canAdd): ?>
<div class="card mb-4"><div class="card-header fw-bold"><i class="fas fa-plus me-1"></i>عرض جديد</div><div class="card-body">
  <form method="POST"><input type="hidden" name="form_type" value="quote">
    <div class="row g-2">
      <div class="col-md-4"><label class="form-label fw-semibold">اسم العميل المحتمل *</label><input name="client_name" class="form-control" required></div>
      <div class="col-md-3"><label class="form-label fw-semibold">جواله</label><input name="client_phone" class="form-control"></div>
      <div class="col-md-3"><label class="form-label fw-semibold">المبلغ (ر.س) *</label><input type="number" step="0.01" name="amount" class="form-control" required></div>
      <div class="col-md-2"><button class="btn btn-primary w-100"><i class="fas fa-paper-plane me-1"></i>إنشاء</button></div>
      <div class="col-12"><label class="form-label fw-semibold">وصف الخدمة/البنود</label><textarea name="description" class="form-control" rows="3" placeholder="مثال: متابعة قضية عمالية أمام المحكمة العمالية شاملة الجلسات حتى صدور الحكم الابتدائي"></textarea></div>
    </div>
  </form>
</div></div>
<?php endif; ?>

<div class="row g-3">
<?php if (!$quotes): ?><div class="col-12"><div class="text-muted text-center py-5">لا توجد عروض بعد</div></div>
<?php else: foreach ($quotes as $q): $st = $_stMap[$q['status']]; $url = $_base . $q['token']; ?>
<div class="col-lg-6"><div class="card h-100"><div class="card-body">
  <div class="d-flex justify-content-between align-items-start">
    <div><b><?= e($q['client_name']) ?></b><div class="text-muted" style="font-size:12px"><?= e($q['client_phone'] ?: '') ?></div></div>
    <span class="badge bg-<?= $st[1] ?> bg-opacity-10 text-<?= $st[1] ?>"><?= $st[0] ?></span>
  </div>
  <div class="fw-bold text-primary mt-1"><?= number_format((float)$q['amount'],2) ?> ر.س</div>
  <?php if ($q['description']): ?><div class="text-muted mt-1" style="font-size:12.5px"><?= e(mb_substr($q['description'],0,120)) ?></div><?php endif; ?>
  <?php if ($q['status']==='sent'): ?>
  <div class="input-group input-group-sm mt-2"><input type="text" class="form-control" readonly value="<?= e($url) ?>" onclick="this.select()">
    <button class="btn btn-outline-primary" onclick="navigator.clipboard.writeText('<?= e($url) ?>')"><i class="fas fa-copy"></i></button>
    <a class="btn btn-outline-success" target="_blank" href="https://wa.me/?text=<?= urlencode($url) ?>"><i class="fab fa-whatsapp"></i></a></div>
  <?php elseif ($q['status']==='accepted' && $q['resulting_contract_id']): ?>
  <a href="contracts.php?edit=<?= (int)$q['resulting_contract_id'] ?>" class="btn btn-sm btn-outline-success mt-2"><i class="fas fa-file-signature me-1"></i>فتح العقد المبدئي</a>
  <?php endif; ?>
  <?php if ($q['status']==='sent' && $_canDel): ?><a href="fee_quote.php?delete=<?= (int)$q['id'] ?>" class="btn btn-sm btn-outline-danger mt-2" onclick="return confirm('حذف هذا العرض؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
</div></div></div>
<?php endforeach; endif; ?>
</div>

<?php include '../includes/office_footer.php'; ?>
