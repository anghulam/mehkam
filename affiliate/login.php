<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/affiliate_helper.php';
affiliate_migrate($conn);

if (!empty($_SESSION['affiliate_id'])) { header('Location: dashboard.php'); exit; }

$error = '';
if (($_GET['err'] ?? '') === 'disabled') $error = 'حسابك معطّل حالياً — تواصل مع إدارة المنصة';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $userE = $conn->real_escape_string($username);
    $r = $conn->query("SELECT * FROM affiliates WHERE username='$userE' LIMIT 1");
    $aff = $r ? $r->fetch_assoc() : null;
    if ($aff && password_verify($pass, $aff['password'])) {
        if (!$aff['is_active']) {
            $error = 'حسابك معطّل حالياً — تواصل مع إدارة المنصة';
        } else {
            session_regenerate_id(true);
            $_SESSION['affiliate_id'] = (int)$aff['id'];
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
<title>دخول الأفلييت — مِحكام</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<style>
html{font-size:14px}
body{font-family:'Tajawal',sans-serif;background:linear-gradient(135deg,#0c1b36,#1a3a6e);min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;font-size:.9rem}
.af-card{background:#fff;border-radius:18px;padding:36px 32px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.af-icon{width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#0c1b36,#1a3a6e);display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
.af-icon i{color:#e8c040;font-size:22px}
</style>
</head>
<body>
<div class="af-card">
  <div class="af-icon"><i class="fas fa-handshake"></i></div>
  <h5 class="text-center fw-bold mb-1" style="color:#0c1b36">بوابة الأفلييت</h5>
  <p class="text-center text-muted mb-4" style="font-size:13px">منصة مِحكام</p>
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
</div>
</body>
</html>
