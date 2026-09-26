<?php
// client_portal_header.php — رأس صفحات بوابة العميل
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['portal_client_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

$_cp_id = (int)$_SESSION['portal_client_id'];
$_cp_client = $conn->query("SELECT * FROM clients WHERE id=$_cp_id LIMIT 1")->fetch_assoc();
if (!$_cp_client || !$_cp_client['portal_enabled']) {
    session_destroy();
    header('Location: login.php?err=disabled'); exit;
}
$_cp_oid = (int)$_cp_client['office_id'];
if (!hasModule($conn, $_cp_oid, 'client_portal')) {
    session_destroy();
    header('Location: login.php?err=disabled'); exit;
}
$_cp_office = $conn->query("SELECT name FROM offices WHERE id=$_cp_oid LIMIT 1")->fetch_assoc();
$p = basename($_SERVER['PHP_SELF']);
$_cp_initial = mb_substr($_cp_client['full_name'], 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ?? 'بوابة العميل') ?> — مِحكام</title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<style>
html{font-size:14px}
html,body{min-height:100%}
body{font-family:'Tajawal',sans-serif;background:#f4f6fb;margin:0;font-size:.9rem}
.cp-wrap{display:flex;align-items:stretch;min-height:100vh}
.cp-sidebar{width:230px;background:linear-gradient(180deg,#0c1b36,#0f2040);color:#fff;flex-shrink:0;display:flex;flex-direction:column}
.cp-nav-inner{display:flex;flex-direction:column;flex:1}
.cp-brand{padding:20px 18px;font-weight:900;font-size:1.05rem;border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:10px}
.cp-brand i{color:#e8c040}
.cp-nav{padding:14px 10px;flex:1}
.cp-nav a{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:10px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.87rem;font-weight:600;margin-bottom:4px}
.cp-nav a:hover{background:rgba(255,255,255,.08);color:#fff}
.cp-nav a.active{background:rgba(232,192,64,.15);color:#e8c040}
.cp-main{flex:1;min-width:0;display:flex;flex-direction:column}
.cp-topbar{background:#fff;border-bottom:1px solid #e8edf5;padding:12px 24px;display:flex;align-items:center;justify-content:space-between;gap:14px}
.cp-topbar-title{font-weight:800;font-size:1rem;color:#0c1b36}
.cp-avatar{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,#0c1b36,#1a3a6e);color:#e8c040;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;flex-shrink:0}
.cp-content{padding:22px;flex:1}
.cp-content .card-header{font-size:.85rem}
.cp-content table{font-size:.85rem}
.cp-content .btn{font-size:.85rem}
.cp-footer{padding:14px 24px;border-top:1px solid #e8edf5;text-align:center;font-size:.75rem;color:#94a3b8;background:#fff}
@media (max-width:768px){.cp-wrap{flex-direction:column}.cp-sidebar{width:100%}.cp-content{padding:16px}.cp-topbar{padding:12px 16px}}
</style>
</head>
<body>
<div class="cp-wrap">
  <aside class="cp-sidebar">
    <div class="cp-nav-inner">
      <div class="cp-brand"><i class="fas fa-door-open"></i> بوابة العميل</div>
      <nav class="cp-nav">
        <a href="dashboard.php" class="<?= $p==='dashboard.php'?'active':'' ?>"><i class="fas fa-chart-line"></i> نظرة عامة</a>
        <a href="cases.php" class="<?= $p==='cases.php'?'active':'' ?>"><i class="fas fa-gavel"></i> قضاياي</a>
        <a href="invoices.php" class="<?= $p==='invoices.php'?'active':'' ?>"><i class="fas fa-file-invoice"></i> فواتيري</a>
        <a href="messages.php" class="<?= $p==='messages.php'?'active':'' ?>"><i class="fas fa-comments"></i> تواصل مع المكتب</a>
      </nav>
    </div>
  </aside>
  <div class="cp-main">
    <div class="cp-topbar">
      <div class="cp-topbar-title"><?= e($page_title ?? '') ?></div>
      <div class="dropdown">
        <button class="d-flex align-items-center gap-2" style="background:none;border:none;cursor:pointer" type="button" data-bs-toggle="dropdown">
          <div class="cp-avatar"><?= $_cp_initial ?></div>
          <div class="text-end d-none d-sm-block">
            <div style="font-size:13px;font-weight:700;color:#0c1b36"><?= e($_cp_client['full_name']) ?></div>
            <div style="font-size:11px;color:#94a3b8"><?= e($_cp_office['name'] ?? '') ?></div>
          </div>
          <i class="fas fa-chevron-down" style="font-size:10px;color:#94a3b8"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-right-from-bracket me-2"></i>تسجيل الخروج</a></li>
        </ul>
      </div>
    </div>
    <div class="cp-content">
