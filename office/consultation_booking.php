<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('consultation_booking','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'consultation_booking')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'حجز استشارة أولية أونلاين';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canDel = can('consultation_booking','delete');

$conn->query("CREATE TABLE IF NOT EXISTS booking_requests (
    id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL,
    name VARCHAR(150) NOT NULL, phone VARCHAR(30) DEFAULT NULL, email VARCHAR(150) DEFAULT NULL,
    preferred_date DATE NOT NULL, preferred_time VARCHAR(10) DEFAULT NULL, message VARCHAR(500) DEFAULT NULL,
    status ENUM('pending','confirmed','declined') DEFAULT 'pending', appointment_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── تأكيد الحجز → موعد فعلي ── */
if (isset($_GET['confirm'])) {
    requirePerm('consultation_booking', 'edit', 'consultation_booking.php?msg=denied');
    $bid = (int)$_GET['confirm'];
    $b = $conn->query("SELECT * FROM booking_requests WHERE id=$bid AND office_id=$oid AND status='pending'")->fetch_assoc();
    if ($b) {
        $dt = $b['preferred_date'] . ' ' . ($b['preferred_time'] ?: '10:00') . ':00';
        $title = $conn->real_escape_string('استشارة أولية — ' . $b['name']);
        $conn->query("INSERT INTO appointments (office_id,title,appointment_date,type,status) VALUES ($oid,'$title','$dt','consultation','scheduled')");
        $aid = (int)$conn->insert_id;
        $conn->query("UPDATE booking_requests SET status='confirmed', appointment_id=$aid WHERE id=$bid");
    }
    header('Location: consultation_booking.php?msg=saved'); exit;
}
if (isset($_GET['decline'])) {
    requirePerm('consultation_booking', 'edit', 'consultation_booking.php?msg=denied');
    $conn->query("UPDATE booking_requests SET status='declined' WHERE id=".(int)$_GET['decline']." AND office_id=$oid AND status='pending'");
    header('Location: consultation_booking.php?msg=saved'); exit;
}
if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM booking_requests WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header('Location: consultation_booking.php?msg=deleted'); exit;
}

/* ── طلب حجز عام (من الموقع) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'public_book') {
    $name = trim($_POST['name'] ?? ''); $date = $_POST['preferred_date'] ?? '';
    if ($name !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $ne = $conn->real_escape_string($name);
        $phone = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
        $email = $conn->real_escape_string(trim($_POST['email'] ?? ''));
        $time = $conn->real_escape_string(trim($_POST['preferred_time'] ?? ''));
        $msg = $conn->real_escape_string(trim($_POST['message'] ?? ''));
        $conn->query("INSERT INTO booking_requests (office_id,name,phone,email,preferred_date,preferred_time,message) VALUES ($oid,'$ne','$phone','$email','$date','$time','$msg')");
    }
    header('Location: consultation_booking.php?msg=saved'); exit;
}

$list = [];
$lr = $conn->query("SELECT * FROM booking_requests WHERE office_id=$oid ORDER BY status='pending' DESC, id DESC LIMIT 100");
if ($lr) while ($x = $lr->fetch_assoc()) $list[] = $x;
$_stMap = ['pending'=>['جديد','warning'],'confirmed'=>['مؤكَّد','success'],'declined'=>['مرفوض','secondary']];
$_bookUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/public/book.php?o=' . $oid;

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-calendar-plus"></i> حجز استشارة أولية أونلاين</div>
<div class="mk-page-sub mb-3">شارك رابط الحجز مع عملائك المحتملين — أي طلب يصل هنا للمراجعة قبل التأكيد</div>

<?php if (isset($_GET['msg'])): ?><div class="alert alert-success alert-dismissible fade show">تم بنجاح<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card mb-4"><div class="card-body d-flex align-items-center gap-2">
  <i class="fas fa-link text-primary"></i><b>رابط الحجز العام:</b>
  <input type="text" class="form-control form-control-sm" readonly value="<?= e($_bookUrl) ?>" onclick="this.select()" style="max-width:400px">
  <button class="btn btn-sm btn-outline-primary" onclick="navigator.clipboard.writeText('<?= e($_bookUrl) ?>')"><i class="fas fa-copy"></i></button>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>الاسم</th><th>التاريخ المفضَّل</th><th>التواصل</th><th>الرسالة</th><th>الحالة</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$list): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد طلبات بعد</td></tr>
<?php else: foreach ($list as $b): $st = $_stMap[$b['status']]; ?>
<tr>
  <td class="fw-semibold"><?= e($b['name']) ?></td>
  <td><?= e($b['preferred_date']) ?> <?= e($b['preferred_time'] ?: '') ?></td>
  <td style="font-size:12px"><?= e($b['phone'] ?: '') ?> <?= e($b['email'] ?: '') ?></td>
  <td style="font-size:12px"><?= e(mb_substr($b['message'] ?: '', 0, 60)) ?></td>
  <td><span class="badge bg-<?= $st[1] ?> bg-opacity-10 text-<?= $st[1] ?>"><?= $st[0] ?></span></td>
  <td class="d-flex gap-1">
    <?php if ($b['status']==='pending'): ?>
    <a href="consultation_booking.php?confirm=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-success" title="تأكيد وإنشاء موعد"><i class="fas fa-check"></i></a>
    <a href="consultation_booking.php?decline=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('رفض الطلب؟')"><i class="fas fa-xmark"></i></a>
    <?php endif; ?>
    <?php if ($_canDel): ?><a href="consultation_booking.php?delete=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-secondary" onclick="return confirm('حذف الطلب؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div>

<?php include '../includes/office_footer.php'; ?>
