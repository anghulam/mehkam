<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

try { $conn->query("ALTER TABLE clients ADD COLUMN portal_username VARCHAR(60) DEFAULT NULL UNIQUE"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE clients ADD COLUMN portal_password_hash VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE clients ADD COLUMN portal_enabled TINYINT(1) DEFAULT 0"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE clients ADD COLUMN portal_created_at TIMESTAMP NULL DEFAULT NULL"); } catch (\Throwable $e) {}

if (!empty($_SESSION['portal_client_id'])) { header('Location: dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $userE = $conn->real_escape_string($username);
    $r = $conn->query("SELECT * FROM clients WHERE portal_username='$userE' LIMIT 1");
    $cl = $r ? $r->fetch_assoc() : null;
    if ($cl && $cl['portal_password_hash'] && password_verify($pass, $cl['portal_password_hash'])) {
        if (!$cl['portal_enabled']) {
            $error = 'بوابتك معطّلة حالياً — تواصل مع مكتبك';
        } else {
            session_regenerate_id(true);
            $_SESSION['portal_client_id'] = (int)$cl['id'];
            $_SESSION['portal_office_id'] = (int)$cl['office_id'];
            header('Location: dashboard.php'); exit;
        }
    } else {
        $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>بوابة العميل — مِحكام</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<style>
html{font-size:14px}
body{font-family:'Tajawal',sans-serif;background:linear-gradient(135deg,#0c1b36,#1a3a6e);min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;font-size:.9rem}
.cp-card{background:#fff;border-radius:18px;padding:36px 32px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.cp-icon{width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#0c1b36,#1a3a6e);display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
.cp-icon i{color:#e8c040;font-size:22px}
</style>
</head>
<body>
<div class="cp-card">
  <div class="cp-icon"><i class="fas fa-door-open"></i></div>
  <h5 class="text-center fw-bold mb-1" style="color:#0c1b36">بوابة العميل</h5>
  <p class="text-center text-muted mb-4" style="font-size:13px">تابع قضاياك وفواتيرك في مكان واحد</p>
  <?php if ($error): ?>
  <div class="alert alert-danger py-2" style="font-size:13px"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <form method="POST">
    <div class="mb-3">
      <label class="form-label fw-semibold">اسم المستخدم</label>
      <input type="text" name="username" class="form-control" required autofocus>
    </div>
    <div class="mb-4">
      <label class="form-label fw-semibold">كلمة المرور</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <button type="submit" class="btn w-100 fw-bold" style="background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#fff">
      <i class="fas fa-right-to-bracket me-1"></i>دخول
    </button>
  </form>
  <p class="text-center text-muted mt-3 mb-0" style="font-size:11.5px">بيانات الدخول تُسلَّمك من مكتبك مباشرة</p>
</div>
</body>
</html>
