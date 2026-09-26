<?php
// affiliate_header.php — رأس صفحات بوابة الأفلييت
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['affiliate_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/affiliate_helper.php';
affiliate_migrate($conn);

$_aff_id = (int)$_SESSION['affiliate_id'];
$_aff = $conn->query("SELECT * FROM affiliates WHERE id=$_aff_id LIMIT 1")->fetch_assoc();
if (!$_aff || !$_aff['is_active']) {
    session_destroy();
    header('Location: login.php?err=disabled'); exit;
}
$p = basename($_SERVER['PHP_SELF']);
if (!function_exists('e')) { function e($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); } }
$_aff_initial = e(mb_substr($_aff['full_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ?? 'بوابة الأفلييت') ?> — مِحكام</title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<style>
html{font-size:14px}
html,body{min-height:100%}
body{font-family:'Tajawal',sans-serif;background:#f4f6fb;margin:0;font-size:.9rem}
.af-wrap{display:flex;align-items:stretch;min-height:100vh}
.af-sidebar{width:230px;background:linear-gradient(180deg,#0c1b36,#0f2040);color:#fff;flex-shrink:0;display:flex;flex-direction:column}
.af-nav-inner{display:flex;flex-direction:column;flex:1}
.af-brand{padding:20px 18px;font-weight:900;font-size:1.05rem;border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:10px}
.af-brand i{color:#e8c040}
.af-nav{padding:14px 10px;flex:1}
.af-nav a{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:10px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.87rem;font-weight:600;margin-bottom:4px}
.af-nav a:hover{background:rgba(255,255,255,.08);color:#fff}
.af-nav a.active{background:rgba(232,192,64,.15);color:#e8c040}
.af-main{flex:1;min-width:0;display:flex;flex-direction:column}
.af-topbar{background:#fff;border-bottom:1px solid #e8edf5;padding:12px 24px;display:flex;align-items:center;justify-content:space-between;gap:14px}
.af-topbar-title{font-weight:800;font-size:1rem;color:#0c1b36}
.af-user-btn{display:flex;align-items:center;gap:10px;background:none;border:none;cursor:pointer;padding:6px 10px;border-radius:10px}
.af-user-btn:hover{background:#f1f5f9}
.af-avatar{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#e8c040;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;flex-shrink:0}
.af-content{padding:22px;flex:1}
.af-content .card-header{font-size:.85rem}
.af-content table{font-size:.85rem}
.af-content .btn{font-size:.85rem}
.af-content .form-label{font-size:.85rem}
.af-footer{padding:14px 24px;border-top:1px solid #e8edf5;text-align:center;font-size:.75rem;color:#94a3b8;background:#fff}
.af-footer a{color:#c9971a;text-decoration:none}
@media (max-width:768px){.af-wrap{flex-direction:column}.af-sidebar{width:100%}.af-content{padding:16px}.af-topbar{padding:12px 16px}}
</style>
</head>
<body>
<div class="af-wrap">
  <aside class="af-sidebar">
    <div class="af-nav-inner">
      <div class="af-brand"><i class="fas fa-handshake"></i> بوابة الأفلييت</div>
      <nav class="af-nav">
        <a href="dashboard.php" class="<?= $p==='dashboard.php'?'active':'' ?>"><i class="fas fa-chart-line"></i> لوحة التحكم</a>
        <a href="marketing.php" class="<?= $p==='marketing.php'?'active':'' ?>"><i class="fas fa-bullhorn"></i> أدوات التسويق</a>
        <a href="withdraw.php" class="<?= $p==='withdraw.php'?'active':'' ?>"><i class="fas fa-money-bill-transfer"></i> طلب سحب</a>
        <a href="profile.php" class="<?= $p==='profile.php'?'active':'' ?>"><i class="fas fa-user-gear"></i> الملف الشخصي</a>
      </nav>
    </div>
  </aside>
  <div class="af-main">
    <div class="af-topbar">
      <div class="af-topbar-title"><?= e($page_title ?? '') ?></div>
      <div class="dropdown">
        <button class="af-user-btn" type="button" data-bs-toggle="dropdown">
          <div class="af-avatar"><?= $_aff_initial ?></div>
          <div class="text-end d-none d-sm-block">
            <div style="font-size:13px;font-weight:700;color:#0c1b36"><?= e($_aff['full_name']) ?></div>
            <div style="font-size:11px;color:#94a3b8">@<?= e($_aff['username'] ?? '') ?></div>
          </div>
          <i class="fas fa-chevron-down" style="font-size:10px;color:#94a3b8"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-gear me-2 text-muted"></i>الملف الشخصي</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-right-from-bracket me-2"></i>تسجيل الخروج</a></li>
        </ul>
      </div>
    </div>
    <div class="af-content">
