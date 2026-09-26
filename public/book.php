<?php
/** public/book.php — نموذج حجز استشارة أولية عام (بدون تسجيل دخول) */
require_once '../config/db.php';
require_once '../includes/functions.php';

$oid = (int)($_GET['o'] ?? 0);
$office = $oid ? $conn->query("SELECT name FROM offices WHERE id=$oid AND status IN ('active','trial') LIMIT 1")->fetch_assoc() : null;
$moduleOn = $office && hasModule($conn, $oid, 'consultation_booking');
$sent = false;

if ($moduleOn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? ''); $date = $_POST['preferred_date'] ?? '';
    if ($name !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date >= date('Y-m-d')) {
        $conn->query("CREATE TABLE IF NOT EXISTS booking_requests (
            id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL,
            name VARCHAR(150) NOT NULL, phone VARCHAR(30) DEFAULT NULL, email VARCHAR(150) DEFAULT NULL,
            preferred_date DATE NOT NULL, preferred_time VARCHAR(10) DEFAULT NULL, message VARCHAR(500) DEFAULT NULL,
            status ENUM('pending','confirmed','declined') DEFAULT 'pending', appointment_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_office (office_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ne = $conn->real_escape_string($name);
        $phone = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
        $email = $conn->real_escape_string(trim($_POST['email'] ?? ''));
        $time = $conn->real_escape_string(trim($_POST['preferred_time'] ?? ''));
        $msg = $conn->real_escape_string(mb_substr(trim($_POST['message'] ?? ''), 0, 500));
        $conn->query("INSERT INTO booking_requests (office_id,name,phone,email,preferred_date,preferred_time,message) VALUES ($oid,'$ne','$phone','$email','$date','$time','$msg')");
        $sent = true;
    }
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>حجز استشارة — <?= h($office['name'] ?? 'مكتب محاماة') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
body{margin:0;background:#f3f5f9;font-family:Tajawal,Tahoma,sans-serif;color:#0c1b36}
.w{max-width:480px;margin:0 auto;padding:16px}
.hd{background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#fff;border-radius:16px;padding:22px;text-align:center}
.hd small{color:#c9a227;font-weight:700}.hd h1{margin:8px 0 0;font-size:20px}
.card{background:#fff;border:1px solid #e6e9f0;border-radius:14px;padding:18px;margin-top:14px}
label{font-size:13px;font-weight:700;display:block;margin:10px 0 4px}
input,textarea{width:100%;border:1px solid #d8dee8;border-radius:10px;padding:10px;font-family:inherit;font-size:14px;box-sizing:border-box}
.btn{display:block;width:100%;background:#0c1b36;color:#fff;border:0;border-radius:10px;padding:12px;font-family:inherit;font-size:15px;font-weight:700;cursor:pointer;margin-top:14px}
.fl{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:16px;margin-top:14px;text-align:center}
</style></head><body><div class="w">
<div class="hd"><small><?= h($office['name'] ?? 'مكتب محاماة') ?></small><h1>حجز استشارة قانونية أولية</h1></div>
<?php if (!$office || !$moduleOn): ?>
<div class="card" style="text-align:center;color:#94a3b8">رابط الحجز غير متاح حالياً.</div>
<?php elseif ($sent): ?>
<div class="fl">✔ تم إرسال طلبك بنجاح — سيتواصل معك المكتب لتأكيد الموعد.</div>
<?php else: ?>
<div class="card">
<form method="POST">
  <label>الاسم *</label><input name="name" required>
  <label>الجوال</label><input name="phone">
  <label>البريد الإلكتروني</label><input type="email" name="email">
  <label>التاريخ المفضَّل *</label><input type="date" name="preferred_date" min="<?= date('Y-m-d') ?>" required>
  <label>الوقت المفضَّل</label><input type="time" name="preferred_time">
  <label>موضوع الاستشارة</label><textarea name="message" rows="3"></textarea>
  <button class="btn" type="submit">إرسال طلب الحجز</button>
</form>
</div>
<?php endif; ?>
</div></body></html>
