<?php
require_once '../config/db.php';
require_once '../includes/content_helper.php';

// إنشاء جدول التحقق إن لم يكن موجوداً
$conn->query("CREATE TABLE IF NOT EXISTS email_verifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      VARCHAR(128) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used       TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (token), INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// عمود تأكيد البريد في جدول المستخدمين (توافق مع قواعد بيانات قديمة)
try { $conn->query("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

$token  = trim($_GET['token'] ?? '');
$status = 'invalid'; // invalid | expired | already | ok
$name   = '';

if ($token) {
    $te = $conn->real_escape_string($token);
    $row = $conn->query("SELECT ev.*, u.full_name, u.email FROM email_verifications ev
        LEFT JOIN users u ON ev.user_id = u.id
        WHERE ev.token='$te' LIMIT 1")->fetch_assoc();

    if (!$row) {
        $status = 'invalid';
    } elseif ($row['used']) {
        $status = 'already';
        $name   = $row['full_name'];
    } elseif (strtotime($row['expires_at']) < time()) {
        $status = 'expired';
    } else {
        $uid = (int)$row['user_id'];
        $conn->query("UPDATE email_verifications SET used=1 WHERE token='$te'");
        $conn->query("UPDATE users SET email_verified=1 WHERE id=$uid");
        $status = 'ok';
        $name   = $row['full_name'];
    }
}

$site = sc($conn,'site_name','مِحكام');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تأكيد البريد الإلكتروني | <?= e($site) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{font-family:'Tajawal',sans-serif;box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:linear-gradient(135deg,#0a1628,#1a3a6e);display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:20px;padding:52px 40px;max-width:460px;width:100%;text-align:center;box-shadow:0 24px 64px rgba(0,0,0,.3)}
.icon{width:80px;height:80px;border-radius:20px;margin:0 auto 24px;display:flex;align-items:center;justify-content:center;font-size:32px}
.icon-ok{background:#f0fdf4;color:#16a34a}
.icon-err{background:#fef2f2;color:#dc2626}
.icon-warn{background:#fef3c7;color:#d97706}
h2{font-size:24px;font-weight:900;color:#0a1628;margin-bottom:10px}
p{font-size:14px;color:#6b7280;line-height:1.8;margin-bottom:20px}
.btn{display:inline-block;background:linear-gradient(135deg,#0a1628,#1a3a6e);color:#fff;text-decoration:none;padding:13px 32px;border-radius:10px;font-size:14px;font-weight:700}
</style>
</head>
<body>
<div class="card">
<?php if ($status === 'ok'): ?>
  <div class="icon icon-ok"><i class="fas fa-check-circle"></i></div>
  <h2>تم تأكيد بريدك الإلكتروني!</h2>
  <p>مرحباً <strong><?= e($name) ?></strong>، تم تأكيد بريدك الإلكتروني بنجاح. حسابك الآن في انتظار مراجعة فريق <?= e($site) ?> وتفعيله.</p>
  <a href="../login.php" class="btn"><i class="fas fa-sign-in-alt me-2"></i>تسجيل الدخول</a>

<?php elseif ($status === 'already'): ?>
  <div class="icon icon-ok"><i class="fas fa-circle-check"></i></div>
  <h2>بريدك مؤكد مسبقاً</h2>
  <p>مرحباً <strong><?= e($name) ?></strong>، بريدك الإلكتروني مؤكد بالفعل.</p>
  <a href="../login.php" class="btn"><i class="fas fa-sign-in-alt me-2"></i>تسجيل الدخول</a>

<?php elseif ($status === 'expired'): ?>
  <div class="icon icon-warn"><i class="fas fa-clock"></i></div>
  <h2>انتهت صلاحية الرابط</h2>
  <p>هذا الرابط انتهت صلاحيته (24 ساعة). يرجى التواصل مع الدعم للحصول على رابط جديد.</p>
  <a href="../public/contact.php" class="btn">تواصل مع الدعم</a>

<?php else: ?>
  <div class="icon icon-err"><i class="fas fa-times-circle"></i></div>
  <h2>رابط غير صالح</h2>
  <p>هذا الرابط غير صالح أو تم استخدامه. إذا كنت قد سجّلت مؤخراً، تحقق من بريدك أو تواصل مع الدعم.</p>
  <a href="../login.php" class="btn">الصفحة الرئيسية</a>
<?php endif; ?>
</div>
</body>
</html>
