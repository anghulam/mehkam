<?php
// admin_header.php
$p = basename($_SERVER['PHP_SELF']);
$_admin_name = $_SESSION['full_name'] ?? 'مدير النظام';
$_admin_initial = mb_substr($_admin_name, 0, 1, 'UTF-8');

// إشعارات غير مقروءة للأدمن (فقط ما يخص الإدارة نفسها — لا تنبيهات المكاتب الداخلية كالجلسات والمهام لموظفيها)
$_adm_uid = (int)($_SESSION['user_id'] ?? 0);
$_adm_unread = 0;
$aq = $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id IS NULL AND is_read=0");
if ($aq) $_adm_unread = (int)$aq->fetch_assoc()['c'];

// رسائل اتصل بنا غير مقروءة
$_contact_unread = 0;
$cq = $conn->query("SELECT COUNT(*) c FROM contact_messages WHERE is_read=0");
if ($cq) $_contact_unread = (int)$cq->fetch_assoc()['c'];

// طلبات تغيير الباقة المعلقة
$_pkg_req_pending = 0;
$prq = $conn->query("SELECT COUNT(*) c FROM package_requests WHERE status='pending'");
if ($prq) $_pkg_req_pending = (int)$prq->fetch_assoc()['c'];

// طلبات سحب الأفلييت المعلّقة
$_aff_wd_pending = 0;
try {
    $awq = $conn->query("SELECT COUNT(*) c FROM affiliate_withdrawals WHERE status='pending'");
    if ($awq) $_aff_wd_pending = (int)$awq->fetch_assoc()['c'];
} catch (\Throwable $e) {}

// طلبات موديولات إضافية معلّقة من المكاتب
$_mod_req_pending = 0;
try {
    $mrq = $conn->query("SELECT COUNT(*) c FROM module_requests WHERE status='pending'");
    if ($mrq) $_mod_req_pending = (int)$mrq->fetch_assoc()['c'];
} catch (\Throwable $e) {}

function adminSidebarItem($href, $icon, $label, $active, $badge = 0) {
    $cls = $active ? ' active' : '';
    $badgeHtml = $badge > 0
        ? "<span style='background:#dc2626;color:#fff;font-size:10px;font-weight:700;border-radius:50px;padding:1px 7px;margin-right:auto;min-width:18px;text-align:center'>$badge</span>"
        : '';
    echo "<a href=\"{$href}\" class=\"mk-sb-item{$cls}\">
        <i class=\"fas fa-{$icon} nav-icon\"></i>
        <span>{$label}</span>
        {$badgeHtml}
    </a>";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ?? 'لوحة الإدارة') ?> — مِحكام Admin</title>
<meta name="robots" content="noindex">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<link rel="stylesheet" href="../css/mehkam.css?v=<?= filemtime('../css/mehkam.css') ?>">

<style>
/* Admin: sidebar accent color (slate instead of navy) */
.mk-sidebar {
  --sb-bg:  #0f1729;
  --sb-bg2: #0c1220;
}
.mk-sb-pkg { background: rgba(99,102,241,.15) !important; color: #a5b4fc !important; }
/* Collapsed */
body.sidebar-collapsed .mk-sidebar { width: 68px; }
body.sidebar-collapsed .mk-sb-info,
body.sidebar-collapsed .mk-sb-item span,
body.sidebar-collapsed .mk-sb-group,
body.sidebar-collapsed .mk-sb-user .mk-sb-uname,
body.sidebar-collapsed .mk-sb-user .mk-sb-urole,
body.sidebar-collapsed .mk-sb-user .mk-sb-logout { display: none !important; }
body.sidebar-collapsed .mk-sb-item { justify-content: center; padding: 10px 0 !important; }
body.sidebar-collapsed .mk-sb-brand { padding: 14px 8px; justify-content: center; }
body.sidebar-collapsed .mk-main { margin-right: 68px; }
body.sidebar-collapsed .mk-sb-bottom { padding: 10px 6px; }
</style>
</head>
<body>

<div class="mk-overlay" id="mkOverlay"></div>
<div class="mk-wrapper">

  <!-- ADMIN SIDEBAR -->
  <aside class="mk-sidebar" id="mkSidebar">

    <div class="mk-sb-brand">
      <div class="mk-sb-logo">
        <?php $__slogo = (function_exists('site_logo') && isset($conn)) ? site_logo($conn) : ''; ?>
        <?php if ($__slogo): ?>
          <img src="<?= e($__slogo) ?>" alt="شعار المنصّة">
        <?php elseif (file_exists('../assets/img/logo-x.png')): ?>
          <img src="../assets/img/logo-x.png" alt="مِحكام">
        <?php else: ?>
          <span class="mk-sb-logo-text">م</span>
        <?php endif; ?>
      </div>
      <div class="mk-sb-info">
        <div class="mk-sb-name">مِحكام — الإدارة</div>
        <div class="mk-sb-pkg">Super Admin</div>
      </div>
    </div>

    <nav class="mk-sb-nav">
      <div class="mk-sb-group">الإحصائيات</div>
      <?php adminSidebarItem('dashboard.php',    'chart-bar',    'لوحة التحكم',  $p==='dashboard.php'); ?>
      <?php adminSidebarItem('revenue.php',      'coins',        'الإيرادات',    $p==='revenue.php'); ?>
      <?php adminSidebarItem('notifications.php','bell',         'التنبيهات',    $p==='notifications.php'); ?>
      <?php adminSidebarItem('messages.php',     'envelope',     'الرسائل',      $p==='messages.php', $_contact_unread); ?>

      <div class="mk-sb-group">إدارة المنصة</div>
      <?php adminSidebarItem('offices.php',         'building',       'المكاتب',            $p==='offices.php'); ?>
      <?php adminSidebarItem('packages.php',        'boxes-stacked',  'الباقات',            $p==='packages.php'); ?>
      <?php adminSidebarItem('activations.php',     'toggle-on',      'التفعيلات',          $p==='activations.php'); ?>
      <?php adminSidebarItem('package_requests.php','exchange-alt',   'طلبات الباقات',      $p==='package_requests.php', $_pkg_req_pending); ?>
      <?php adminSidebarItem('feature_pricing.php', 'sliders-h',      'تسعير الميزات',      $p==='feature_pricing.php'); ?>
      <?php adminSidebarItem('office_modules.php',  'puzzle-piece',   'الموديولات الإضافية', $p==='office_modules.php', $_mod_req_pending); ?>

      <div class="mk-sb-group">الأفلييت</div>
      <?php adminSidebarItem('affiliates.php',            'user-tag',           'الأفلييت',       $p==='affiliates.php'); ?>
      <?php adminSidebarItem('affiliate_withdrawals.php', 'money-bill-transfer','طلبات السحب',    $p==='affiliate_withdrawals.php', $_aff_wd_pending); ?>

      <div class="mk-sb-group">الإعدادات</div>
      <?php adminSidebarItem('content.php',          'pencil',       'إدارة المحتوى',     $p==='content.php'); ?>
      <?php adminSidebarItem('settings.php',         'gear',         'إعدادات النظام',    $p==='settings.php'); ?>
      <?php adminSidebarItem('payment_gateways.php', 'credit-card',  'بوابات الدفع',      $p==='payment_gateways.php'); ?>
    </nav>

    <div class="mk-sb-bottom">
      <div class="mk-sb-user">
        <div class="mk-sb-avatar"><?= e($_admin_initial) ?></div>
        <div style="flex:1;min-width:0">
          <div class="mk-sb-uname"><?= e($_admin_name) ?></div>
          <div class="mk-sb-urole">مدير النظام</div>
        </div>
        <a href="../logout.php" class="mk-sb-logout" title="تسجيل الخروج">
          <i class="fas fa-right-from-bracket"></i>
        </a>
      </div>
    </div>

  </aside>

  <!-- ADMIN MAIN -->
  <div class="mk-main">

    <header class="mk-header">
      <button class="mk-toggle" id="mkToggle"><i class="fas fa-bars"></i></button>
      <div class="mk-hdr-title"><?= e($page_title ?? '') ?></div>

      <a href="notifications.php" class="mk-notif-btn ms-auto">
        <i class="fas fa-bell"></i>
        <?php if ($_adm_unread > 0): ?>
        <span class="mk-notif-dot"><?= $_adm_unread ?></span>
        <?php endif; ?>
      </a>

      <div class="mk-hdr-user" id="mkUserMenu">
        <div class="mk-hdr-avatar"><?= e($_admin_initial) ?></div>
        <div class="d-none d-sm-block">
          <div class="mk-hdr-uname"><?= e($_admin_name) ?></div>
          <div class="mk-hdr-urole">مدير النظام</div>
        </div>
        <i class="fas fa-chevron-down mk-hdr-caret"></i>
        <div class="mk-user-dropdown" id="mkUserDrop">
          <a href="settings.php"><i class="fas fa-gear"></i> الإعدادات</a>
          <div class="divider"></div>
          <a href="../logout.php" class="danger"><i class="fas fa-right-from-bracket"></i> تسجيل الخروج</a>
        </div>
      </div>
    </header>

    <?php if (isset($_GET['msg'])): ?>
    <div style="padding:12px 24px 0">
    <?php
    $msgs = ['saved'=>['success','تم الحفظ'],'deleted'=>['success','تم الحذف'],'error'=>['danger','خطأ'],
             'gderr'=>['danger','ملف Google Drive JSON غير صالح — تأكد أنه مفتاح Service Account (يبدأ بـ "type":"service_account")']];
    [$type,$text] = $msgs[$_GET['msg']] ?? ['info', e($_GET['msg'])];
    ?>
    <div class="alert alert-<?= $type ?> alert-dismissible auto-dismiss d-flex align-items-center gap-2">
      <i class="fas fa-<?= $type==='success'?'check-circle':'exclamation-triangle' ?>"></i>
      <span><?= $text ?></span>
      <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    </div>
    <?php endif; ?>

    <div class="mk-content">
