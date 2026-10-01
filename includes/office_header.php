<?php
// office_header.php — لا يحتوي على منطق الصفحة، يُضمَّن بعد كل المتغيرات
$p = basename($_SERVER['PHP_SELF']);
$oid = (int)($_SESSION['office_id'] ?? 0);

// معلومات المكتب والباقة من الجلسة
$_office_name = $_SESSION['office_name']   ?? 'المكتب';
$_full_name   = $_SESSION['full_name']     ?? 'المستخدم';
$_role        = $_SESSION['role']          ?? 'lawyer';
$_role_label  = ['office_owner'=>'مالك المكتب','lawyer'=>'محامي','secretary'=>'سكرتير','trainee'=>'متدرّب'][$_role] ?? $_role;
$_user_initial = mb_substr($_full_name, 0, 1, 'UTF-8');

// جلب اسم الباقة + تاريخ الانتهاء + شعار المكتب
$_pkg_name = '—'; $_sub_end = null; $_sub_status = 'trial'; $_office_logo = '';
if ($oid) {
    $q = $conn->query("SELECT p.name, o.subscription_end, o.status FROM offices o LEFT JOIN packages p ON o.package_id=p.id WHERE o.id=$oid LIMIT 1");
    if ($q && $row = $q->fetch_assoc()) {
        $_pkg_name   = $row['name'] ?? '—';
        $_sub_end    = $row['subscription_end'];
        $_sub_status = $row['status'] ?? 'trial';
        $_SESSION['pkg_name'] = $_pkg_name;
    }
    // شعار المكتب
    try {
        $ql = $conn->query("SELECT office_logo FROM office_settings WHERE office_id=$oid LIMIT 1");
        if ($ql && $rl = $ql->fetch_assoc()) $_office_logo = $rl['office_logo'] ?? '';
    } catch (\Exception $e) {}
}

// عدد الإشعارات غير المقروءة — تنبيه موجَّه لي شخصياً، أو تنبيه عام للمكتب كله
// (بلا مستخدم محدَّد) — وليس أي صف لمجرّد أنه يحمل office_id نفسه حتى لو كان
// موجَّهاً أصلاً لموظّف آخر بعينه (وإلا لرآه كل موظفي المكتب لا الموظف المقصود فقط)
$_uid = (int)($_SESSION['user_id'] ?? 0);
$_unread = 0;
$q2 = $conn->query("SELECT COUNT(*) c FROM notifications WHERE (user_id=$_uid OR (office_id=$oid AND user_id IS NULL)) AND is_read=0");
if ($q2) $_unread = (int)$q2->fetch_assoc()['c'];

// التحقق من المزايا حسب الباقة
$_can_contracts    = hasFeature($conn, $oid, 'has_contracts');
$_can_finance      = hasFeature($conn, $oid, 'has_finance');
$_can_library      = hasFeature($conn, $oid, 'has_library');
$_can_archive      = hasFeature($conn, $oid, 'has_archive');
$_can_correspondence = hasFeature($conn, $oid, 'has_correspondence');
$_can_ai           = hasFeature($conn, $oid, 'has_ai');
$_can_api          = hasFeature($conn, $oid, 'has_api');
$_can_invoices     = hasFeature($conn, $oid, 'has_invoices');
$_can_reports      = hasFeature($conn, $oid, 'has_reports');
$_can_precedents   = hasFeature($conn, $oid, 'has_precedents');
$_can_digital_services = hasFeature($conn, $oid, 'has_digital_services');
// موديولات إضافية يُفعِّلها الأدمن يدوياً لهذا المكتب تحديداً (مستقلة عن الباقة)
$_can_marketing       = hasModule($conn, $oid, 'marketing');
$_can_time_tracking   = hasModule($conn, $oid, 'time_tracking');
$_can_hr              = hasModule($conn, $oid, 'hr');
$_can_esignature      = hasModule($conn, $oid, 'esignature');
$_can_ai_legal        = hasModule($conn, $oid, 'ai_legal');
$_can_bi_dashboard    = hasModule($conn, $oid, 'bi_dashboard');
$_can_multi_branch    = hasModule($conn, $oid, 'multi_branch');
$_can_support_tickets = hasModule($conn, $oid, 'support_tickets');
$_can_client_portal   = hasModule($conn, $oid, 'client_portal');
$_can_legal_deadlines = hasModule($conn, $oid, 'legal_deadlines');
$_can_referral_network= hasModule($conn, $oid, 'referral_network');
$_can_client_surveys  = hasModule($conn, $oid, 'client_surveys');
$_can_recurring_billing = hasModule($conn, $oid, 'recurring_billing');
$_can_calendar_sync   = hasModule($conn, $oid, 'calendar_sync');
$_can_expert_witness  = hasModule($conn, $oid, 'expert_witness');
$_can_financial_approvals = hasModule($conn, $oid, 'financial_approvals');
$_can_meeting_rooms       = hasModule($conn, $oid, 'meeting_rooms');
$_can_regulatory_alerts   = hasModule($conn, $oid, 'regulatory_alerts');
$_can_reputation_management = hasModule($conn, $oid, 'reputation_management');
$_can_trust_accounts = hasModule($conn, $oid, 'trust_accounts');
$_can_conflict_check = hasModule($conn, $oid, 'conflict_check');
$_can_case_enforcement = hasModule($conn, $oid, 'case_enforcement');
$_can_client_doc_expiry = hasModule($conn, $oid, 'client_doc_expiry');
$_can_session_prep = hasModule($conn, $oid, 'session_prep');
$_can_session_clash = hasModule($conn, $oid, 'session_clash');
$_can_case_closure_review = hasModule($conn, $oid, 'case_closure_review');
$_can_client_periodic_report = hasModule($conn, $oid, 'client_periodic_report');
$_can_absence_delegation = hasModule($conn, $oid, 'absence_delegation');
$_can_external_collab = hasModule($conn, $oid, 'external_collab');
$_can_data_export = hasModule($conn, $oid, 'data_export');
$_can_fee_quote = hasModule($conn, $oid, 'fee_quote');
$_can_case_checklists = hasModule($conn, $oid, 'case_checklists');
$_can_consultation_booking = hasModule($conn, $oid, 'consultation_booking');
$_can_workload_balancer = hasModule($conn, $oid, 'workload_balancer');
$_can_fee_shortfall = hasModule($conn, $oid, 'fee_shortfall');
$_can_pro_bono_tracker = hasModule($conn, $oid, 'pro_bono_tracker');
$_can_global_search = hasModule($conn, $oid, 'global_search');
$_can_client_silence_alert = hasModule($conn, $oid, 'client_silence_alert');
$_can_lawyer_license_expiry = hasModule($conn, $oid, 'lawyer_license_expiry');
$_can_client_source_attribution = hasModule($conn, $oid, 'client_source_attribution');
$_can_petition_wizard = hasModule($conn, $oid, 'petition_wizard');
$_can_lawyer_daily_journal = hasModule($conn, $oid, 'lawyer_daily_journal');
$_can_staff_performance_review = hasModule($conn, $oid, 'staff_performance_review');
$_can_case_workflows = hasModule($conn, $oid, 'case_workflows');
$_can_court_sms_import = hasModule($conn, $oid, 'court_sms_import');
$_can_client_action_link = hasModule($conn, $oid, 'client_action_link');
$_can_ai_case_intake = hasModule($conn, $oid, 'ai_case_intake');
$_any_addon_module = $_can_marketing || $_can_time_tracking || $_can_hr || $_can_esignature
    || $_can_ai_legal || $_can_bi_dashboard || $_can_multi_branch || $_can_support_tickets || $_can_client_portal
    || $_can_legal_deadlines || $_can_referral_network || $_can_client_surveys || $_can_recurring_billing
    || $_can_calendar_sync || $_can_expert_witness
    || $_can_financial_approvals || $_can_meeting_rooms || $_can_regulatory_alerts || $_can_reputation_management || $_can_trust_accounts || $_can_case_workflows || $_can_court_sms_import || $_can_client_action_link || $_can_ai_case_intake || $_can_conflict_check || $_can_case_enforcement || $_can_client_doc_expiry || $_can_session_prep || $_can_session_clash || $_can_case_closure_review || $_can_client_periodic_report || $_can_absence_delegation || $_can_external_collab || $_can_data_export || $_can_fee_quote || $_can_case_checklists || $_can_consultation_booking || $_can_workload_balancer || $_can_fee_shortfall || $_can_pro_bono_tracker || $_can_global_search || $_can_client_silence_alert || $_can_lawyer_license_expiry || $_can_client_source_attribution || $_can_petition_wizard || $_can_lawyer_daily_journal || $_can_staff_performance_review;

// دالة لعرض عنصر قائمة مع إمكانية القفل
function sidebarItem($href, $icon, $label, $active, $locked = false, $badge = null) {
    if ($locked) return;
    $activeClass = $active ? ' active' : '';
    $bdg = $badge ? "<span class=\"mk-sb-badge\">{$badge}</span>" : '';
    echo "<a href=\"{$href}\" class=\"mk-sb-item{$activeClass}\">
        <i class=\"fas fa-{$icon} nav-icon\"></i>
        <span>{$label}</span>
        {$bdg}
    </a>";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ?? 'لوحة التحكم') ?> — مِحكام</title>
<meta name="robots" content="noindex">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">

<!-- Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<!-- Bootstrap 5 RTL -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">

<!-- مِحكام Design System -->
<link rel="stylesheet" href="../css/mehkam.css?v=<?= filemtime('../css/mehkam.css') ?>">

<!-- Sidebar collapsed state -->
<style>
/* Collapsed sidebar */
body.sidebar-collapsed .mk-sidebar {
  width: 68px;
}
body.sidebar-collapsed .mk-sb-info,
body.sidebar-collapsed .mk-sb-item span,
body.sidebar-collapsed .mk-sb-item .mk-sb-badge,
body.sidebar-collapsed .mk-sb-group,
body.sidebar-collapsed .mk-sb-user .mk-sb-uname,
body.sidebar-collapsed .mk-sb-user .mk-sb-urole,
body.sidebar-collapsed .mk-sb-user .mk-sb-logout,
body.sidebar-collapsed .mk-sb-pkg {
  display: none !important;
}
body.sidebar-collapsed .mk-sb-item {
  justify-content: center;
  padding: 10px 0 !important;
}
body.sidebar-collapsed .mk-sb-item i { margin: 0 !important; font-size: 18px; }
body.sidebar-collapsed .mk-sb-brand { padding: 14px 8px; justify-content: center; }
body.sidebar-collapsed .mk-sb-avatar { margin: 0 auto; }
body.sidebar-collapsed .mk-main { margin-right: 68px; }
body.sidebar-collapsed .mk-sb-bottom { padding: 10px 6px; }

@media (max-width:992px) {
  body.sidebar-collapsed .mk-main { margin-right: 0; }
}
</style>
</head>
<body>

<!-- Mobile Overlay -->
<div class="mk-overlay" id="mkOverlay"></div>

<div class="mk-wrapper">

  <!-- ═══════════════════════════════ SIDEBAR ═══════════════════════════════ -->
  <aside class="mk-sidebar" id="mkSidebar">

    <!-- Brand -->
    <div class="mk-sb-brand">
      <div class="mk-sb-logo">
        <?php $__slogo = function_exists('site_logo') ? site_logo($conn) : ''; ?>
        <?php if (!empty($_office_logo) && file_exists('../' . $_office_logo)): ?>
          <img src="../<?= e($_office_logo) ?>?v=<?= filemtime('../' . $_office_logo) ?>" alt="<?= e($_office_name) ?>"
               style="max-width:100%;max-height:100%;object-fit:contain;border-radius:6px">
        <?php elseif ($__slogo): ?>
          <img src="<?= e($__slogo) ?>" alt="شعار المنصّة" style="max-width:100%;max-height:100%;object-fit:contain">
        <?php elseif (file_exists('../assets/img/logo-x.png')): ?>
          <img src="../assets/img/logo-x.png" alt="مِحكام">
        <?php else: ?>
          <span class="mk-sb-logo-text"><?= mb_substr($_office_name, 0, 1, 'UTF-8') ?></span>
        <?php endif; ?>
      </div>
      <div class="mk-sb-info">
        <div class="mk-sb-name"><?= e($_office_name) ?></div>
        <div class="mk-sb-pkg"><?= e($_pkg_name) ?></div>
      </div>
    </div>

    <!-- Navigation -->
    <nav class="mk-sb-nav">

      <!-- الرئيسية -->
      <div class="mk-sb-group">الرئيسية</div>
      <?php sidebarItem('dashboard.php',    'chart-pie',    'لوحة التحكم',     $p==='dashboard.php'); ?>
      <?php sidebarItem('notifications.php','bell',         'التنبيهات',       $p==='notifications.php', false, $_unread ?: null); ?>

      <!-- القضايا والعملاء -->
      <div class="mk-sb-group">القضايا والعملاء</div>
      <?php sidebarItem('clients.php',      'address-book', 'ملفات العملاء',   $p==='clients.php', !can('clients','view')); ?>
      <?php sidebarItem('cases.php',        'gavel',        'القضايا والجلسات',$p==='cases.php', !can('cases','view')); ?>
      <?php sidebarItem('precedents.php',   'scale-balanced','السوابق القضائية',$p==='precedents.php', !$_can_precedents || !can('precedents','view')); ?>

      <!-- الإدارة -->
      <div class="mk-sb-group">الإدارة</div>
      <?php sidebarItem('tasks.php',        'calendar-check','المهام والمواعيد',$p==='tasks.php', !can('tasks','view')); ?>
      <?php sidebarItem('contracts.php',    'file-signature','العقود والوكالات',$p==='contracts.php', !$_can_contracts || !can('contracts','view')); ?>
      <?php sidebarItem('correspondence.php','envelope',    'الصادر والوارد',  $p==='correspondence.php', !$_can_correspondence || !can('correspondence','view')); ?>

      <!-- المالية -->
      <div class="mk-sb-group">المالية</div>
      <?php sidebarItem('finance.php',      'coins',        'الشؤون المالية',  $p==='finance.php', !$_can_finance || !can('finance','view')); ?>
      <?php sidebarItem('invoices.php',     'file-invoice-dollar','الفواتير',  $p==='invoices.php', !$_can_invoices || !can('invoices','view') || !can('finance','view')); ?>

      <!-- التقارير -->
      <div class="mk-sb-group">التقارير</div>
      <?php sidebarItem('reports.php',      'chart-line',   'التقارير المتقدمة',$p==='reports.php', !$_can_reports || !can('reports','view')); ?>

      <!-- الموارد -->
      <div class="mk-sb-group">الموارد</div>
      <?php sidebarItem('library.php',      'book-open',    'المكتبة القانونية',$p==='library.php', !$_can_library || !can('library','view')); ?>
      <?php sidebarItem('archive.php',      'box-archive',  'الأرشيف الإلكتروني',$p==='archive.php', !$_can_archive || !can('archive','view')); ?>
      <?php sidebarItem('services.php',     'link',         'الخدمات الإلكترونية',$p==='services.php'); ?>
      <?php sidebarItem('digital_services.php','hand-holding-dollar','الخدمات الرقمية',$p==='digital_services.php', !$_can_digital_services || !can('services','view')); ?>

      <!-- المتقدم -->
      <div class="mk-sb-group">المتقدم</div>
      <?php sidebarItem('ai_assistant.php', 'robot',        'المساعد الذكي AI', $p==='ai_assistant.php', !$_can_ai); ?>
      <?php sidebarItem('api_keys.php',     'code',         'واجهة API',        $p==='api_keys.php', !$_can_api); ?>
      <?php if ($_any_addon_module || $_role==='office_owner'): ?>
      <div class="mk-sb-group">إضافات</div>
      <?php if ($_can_marketing): ?>
      <?php sidebarItem('marketing.php',        'bullhorn',       'إدارة التسويق',        $p==='marketing.php', !can('marketing','view')); ?>
      <?php endif; ?>
      <?php if ($_can_time_tracking): ?>
      <?php sidebarItem('time_tracking.php',    'stopwatch',      'تتبّع الوقت والساعات', $p==='time_tracking.php', !can('time_tracking','view')); ?>
      <?php endif; ?>
      <?php if ($_can_hr): ?>
      <?php sidebarItem('hr.php',               'user-tie',       'الموارد البشرية',      $p==='hr.php', !can('hr','view')); ?>
      <?php endif; ?>
      <?php if ($_can_esignature): ?>
      <?php sidebarItem('esignature.php',       'signature',      'التوقيع الإلكتروني',   $p==='esignature.php', !can('esignature','view')); ?>
      <?php endif; ?>
      <?php if ($_can_ai_legal): ?>
      <?php sidebarItem('ai_legal.php',         'brain',          'المساعد القانوني الذكي', $p==='ai_legal.php', !can('ai_legal','view')); ?>
      <?php endif; ?>
      <?php if ($_can_bi_dashboard): ?>
      <?php sidebarItem('bi_dashboard.php',     'chart-pie',      'لوحة تحليلات تنفيذية', $p==='bi_dashboard.php', !can('bi_dashboard','view')); ?>
      <?php endif; ?>
      <?php if ($_can_multi_branch): ?>
      <?php sidebarItem('multi_branch.php',     'code-branch',    'إدارة الفروع',         $p==='multi_branch.php', !can('multi_branch','view')); ?>
      <?php endif; ?>
      <?php if ($_can_support_tickets): ?>
      <?php sidebarItem('support_tickets.php',  'headset',        'تذاكر الدعم الفني',    $p==='support_tickets.php', !can('support_tickets','view')); ?>
      <?php endif; ?>
      <?php if ($_can_client_portal): ?>
      <?php sidebarItem('client_portal.php',    'door-open',      'بوابة العميل',         $p==='client_portal.php', !can('clients','view')); ?>
      <?php endif; ?>
      <?php if ($_can_legal_deadlines): ?>
      <?php sidebarItem('legal_deadlines.php',  'hourglass-half', 'المواعيد النظامية',    $p==='legal_deadlines.php', !can('legal_deadlines','view')); ?>
      <?php endif; ?>
      <?php if ($_can_referral_network): ?>
      <?php sidebarItem('referral_network.php', 'people-arrows',  'شبكة الإحالات',        $p==='referral_network.php', !can('referral_network','view')); ?>
      <?php endif; ?>
      <?php if ($_can_client_surveys): ?>
      <?php sidebarItem('client_surveys.php',   'star-half-stroke','استطلاعات رضا العملاء', $p==='client_surveys.php', !can('client_surveys','view')); ?>
      <?php endif; ?>
      <?php if ($_can_recurring_billing): ?>
      <?php sidebarItem('recurring_billing.php','arrows-rotate',  'الفوترة المتكررة',     $p==='recurring_billing.php', !can('recurring_billing','view')); ?>
      <?php endif; ?>
      <?php if ($_can_calendar_sync): ?>
      <?php sidebarItem('calendar_sync.php',    'calendar-days',  'مزامنة التقويم',       $p==='calendar_sync.php', !can('calendar_sync','view')); ?>
      <?php endif; ?>
      <?php if ($_can_expert_witness): ?>
      <?php sidebarItem('expert_witness.php',   'user-doctor',    'الخبراء والشهود',      $p==='expert_witness.php', !can('expert_witness','view')); ?>
      <?php endif; ?>
      <?php if ($_can_financial_approvals): ?>
      <?php sidebarItem('financial_approvals.php', 'money-check-dollar', 'اعتماد المصروفات', $p==='financial_approvals.php', !can('financial_approvals','view')); ?>
      <?php endif; ?>
      <?php if ($_can_meeting_rooms): ?>
      <?php sidebarItem('meeting_rooms.php',    'door-closed',    'حجز قاعات الاجتماعات', $p==='meeting_rooms.php', !can('meeting_rooms','view')); ?>
      <?php endif; ?>
      <?php if ($_can_regulatory_alerts): ?>
      <?php sidebarItem('regulatory_alerts.php','triangle-exclamation', 'تنبيهات نظامية', $p==='regulatory_alerts.php', !can('regulatory_alerts','view')); ?>
      <?php endif; ?>
      <?php if ($_can_reputation_management): ?>
      <?php sidebarItem('reputation_management.php', 'thumbs-up', 'السمعة والتقييمات', $p==='reputation_management.php', !can('reputation_management','view')); ?>
      <?php endif; ?>
      <?php if ($_can_trust_accounts): ?>
      <?php sidebarItem('trust_accounts.php', 'vault', 'حسابات الأمانات', $p==='trust_accounts.php', !can('trust_accounts','view')); ?>
      <?php endif; ?>
      <?php if ($_can_case_workflows): ?>
      <?php sidebarItem('case_workflows.php', 'diagram-project', 'أتمتة إجراءات القضية', $p==='case_workflows.php', !can('case_workflows','view')); ?>
      <?php endif; ?>
      <?php if ($_can_court_sms_import): ?>
      <?php sidebarItem('court_sms_import.php', 'message', 'استيراد رسائل المحكمة', $p==='court_sms_import.php', !can('court_sms_import','view')); ?>
      <?php endif; ?>
      <?php if ($_can_client_action_link): ?>
      <?php sidebarItem('client_action_link.php', 'link', 'رابط إنجاز العميل', $p==='client_action_link.php', !can('client_action_link','view')); ?>
      <?php endif; ?>
      <?php if ($_can_ai_case_intake): ?>
      <?php sidebarItem('ai_case_intake.php', 'file-import', 'قضية من صحيفة الدعوى', $p==='ai_case_intake.php', !can('ai_case_intake','view')); ?>
      <?php endif; ?>
      <?php if ($_can_conflict_check): ?>
      <?php sidebarItem('conflict_check.php', 'user-shield', 'فحص تعارض المصالح', $p==='conflict_check.php', !can('conflict_check','view')); ?>
      <?php endif; ?>
      <?php if ($_can_case_enforcement): ?>
      <?php sidebarItem('case_enforcement.php', 'gavel', 'متابعة تنفيذ الأحكام', $p==='case_enforcement.php', !can('case_enforcement','view')); ?>
      <?php endif; ?>
      <?php if ($_can_client_doc_expiry): ?>
      <?php sidebarItem('client_doc_expiry.php', 'file-circle-exclamation', 'متتبّع انتهاء وثائق العميل', $p==='client_doc_expiry.php', !can('client_doc_expiry','view')); ?>
      <?php endif; ?>
      <?php if ($_can_session_prep): ?>
      <?php sidebarItem('session_prep.php', 'file-lines', 'مذكرة تحضير الجلسة', $p==='session_prep.php', !can('session_prep','view')); ?>
      <?php endif; ?>
      <?php if ($_can_session_clash): ?>
      <?php sidebarItem('session_clash.php', 'calendar-xmark', 'كاشف تعارض المواعيد', $p==='session_clash.php', !can('session_clash','view')); ?>
      <?php endif; ?>
      <?php if ($_can_case_closure_review): ?>
      <?php sidebarItem('case_closure_review.php', 'clipboard-check', 'تقييم القضية بعد إغلاقها', $p==='case_closure_review.php', !can('case_closure_review','view')); ?>
      <?php endif; ?>
      <?php if ($_can_client_periodic_report): ?>
      <?php sidebarItem('client_periodic_report.php', 'file-contract', 'تقرير دوري للعميل', $p==='client_periodic_report.php', !can('client_periodic_report','view')); ?>
      <?php endif; ?>
      <?php if ($_can_absence_delegation): ?>
      <?php sidebarItem('absence_delegation.php', 'right-left', 'تفويض غياب مؤقت', $p==='absence_delegation.php', !can('absence_delegation','view')); ?>
      <?php endif; ?>
      <?php if ($_can_external_collab): ?>
      <?php sidebarItem('external_collab.php', 'user-tie', 'بوابة المحامي الاستشاري الخارجي', $p==='external_collab.php', !can('external_collab','view')); ?>
      <?php endif; ?>
      <?php if ($_can_fee_quote): ?>
      <?php sidebarItem('fee_quote.php', 'file-invoice', 'عروض الأتعاب الإلكترونية', $p==='fee_quote.php', !can('fee_quote','view')); ?>
      <?php endif; ?>
      <?php if ($_can_case_checklists): ?>
      <?php sidebarItem('case_checklists.php', 'list-check', 'قوائم تحقق القضايا', $p==='case_checklists.php', !can('case_checklists','view')); ?>
      <?php endif; ?>
      <?php if ($_can_consultation_booking): ?>
      <?php sidebarItem('consultation_booking.php', 'calendar-plus', 'حجز استشارة أولية أونلاين', $p==='consultation_booking.php', !can('consultation_booking','view')); ?>
      <?php endif; ?>
      <?php if ($_can_workload_balancer): ?>
      <?php sidebarItem('workload_balancer.php', 'scale-balanced', 'موازن الحمل الوظيفي', $p==='workload_balancer.php', !can('workload_balancer','view')); ?>
      <?php endif; ?>
      <?php if ($_can_fee_shortfall): ?>
      <?php sidebarItem('fee_shortfall.php', 'money-bill-trend-up', 'متابعة الأتعاب الناقصة', $p==='fee_shortfall.php', !can('fee_shortfall','view')); ?>
      <?php endif; ?>
      <?php if ($_can_pro_bono_tracker): ?>
      <?php sidebarItem('pro_bono_tracker.php', 'hand-holding-heart', 'سجل المحاماة المجانية', $p==='pro_bono_tracker.php', !can('pro_bono_tracker','view')); ?>
      <?php endif; ?>
      <?php if ($_can_global_search): ?>
      <?php sidebarItem('global_search.php', 'magnifying-glass', 'البحث الشامل الموحّد', $p==='global_search.php', !can('global_search','view')); ?>
      <?php endif; ?>
      <?php if ($_can_client_silence_alert): ?>
      <?php sidebarItem('client_silence_alert.php', 'comment-slash', 'تنبيه العميل الصامت', $p==='client_silence_alert.php', !can('client_silence_alert','view')); ?>
      <?php endif; ?>
      <?php if ($_can_lawyer_license_expiry): ?>
      <?php sidebarItem('lawyer_license_expiry.php', 'id-card', 'تراخيص المحامين المهنية', $p==='lawyer_license_expiry.php', !can('lawyer_license_expiry','view')); ?>
      <?php endif; ?>
      <?php if ($_can_client_source_attribution): ?>
      <?php sidebarItem('client_source_attribution.php', 'route', 'تتبّع مصدر العميل', $p==='client_source_attribution.php', !can('client_source_attribution','view')); ?>
      <?php endif; ?>
      <?php if ($_can_petition_wizard): ?>
      <?php sidebarItem('petition_wizard.php', 'scroll', 'مولّد صحيفة الدعوى', $p==='petition_wizard.php', !can('petition_wizard','view')); ?>
      <?php endif; ?>
      <?php if ($_can_lawyer_daily_journal): ?>
      <?php sidebarItem('lawyer_daily_journal.php', 'book-journal-whills', 'دفتر يوميات المحامي', $p==='lawyer_daily_journal.php', !can('lawyer_daily_journal','view')); ?>
      <?php endif; ?>
      <?php if ($_can_staff_performance_review): ?>
      <?php sidebarItem('staff_performance_review.php', 'chart-simple', 'تقييم أداء الموظفين الدوري', $p==='staff_performance_review.php', !can('staff_performance_review','view')); ?>
      <?php endif; ?>
      <?php if ($_can_data_export && $_role==='office_owner'): ?>
      <?php sidebarItem('data_export.php', 'database', 'التصدير والنسخ الاحتياطي', $p==='data_export.php'); ?>
      <?php endif; ?>
      <?php if ($_role==='office_owner'): ?>
      <?php sidebarItem('modules.php',      'puzzle-piece', 'متجر الموديولات',  $p==='modules.php'); ?>
      <?php endif; ?>
      <?php endif; ?>

      <!-- الإعدادات -->
      <div class="mk-sb-group">الإعدادات</div>
      <?php sidebarItem('users.php',        'users',        'إدارة المستخدمين',$p==='users.php', $_role!=='office_owner'); ?>
      <?php sidebarItem('performance.php',  'chart-line',   'أداء الفريق',      $p==='performance.php', $_role!=='office_owner' || !$_can_reports); ?>
      <?php sidebarItem('activity.php',     'clock-rotate-left','سجل الإجراءات',$p==='activity.php', $_role!=='office_owner'); ?>
      <?php sidebarItem('profile.php',      'gear',         'الملف الشخصي',    $p==='profile.php'); ?>

    </nav>

    <!-- User / Bottom -->
    <div class="mk-sb-bottom">
      <div class="mk-sb-user">
        <div class="mk-sb-avatar"><?= e($_user_initial) ?></div>
        <div style="flex:1;min-width:0">
          <div class="mk-sb-uname"><?= e($_full_name) ?></div>
          <div class="mk-sb-urole"><?= e($_role_label) ?></div>
        </div>
        <a href="../logout.php" class="mk-sb-logout" title="تسجيل الخروج" data-bs-toggle="tooltip">
          <i class="fas fa-right-from-bracket"></i>
        </a>
      </div>
    </div>

  </aside>
  <!-- ═══════════════════════════════ /SIDEBAR ══════════════════════════════ -->

  <!-- ═══════════════════════════════ MAIN ══════════════════════════════════ -->
  <div class="mk-main">

    <!-- Top Header -->
    <header class="mk-header">

      <!-- Toggle sidebar -->
      <button class="mk-toggle" id="mkToggle" title="تبديل القائمة">
        <i class="fas fa-bars"></i>
      </button>

      <!-- Page title -->
      <div class="mk-hdr-title"><?= e($page_title ?? '') ?></div>

      <!-- Search -->
      <form action="" method="GET" class="mk-hdr-search d-none d-md-flex">
        <i class="fas fa-search"></i>
        <input type="text" name="q" placeholder="بحث سريع..." value="<?= e($_GET['q'] ?? '') ?>">
      </form>

      <!-- Notifications -->
      <a href="notifications.php" class="mk-notif-btn" title="التنبيهات">
        <i class="fas fa-bell"></i>
        <?php if ($_unread > 0): ?>
        <span class="mk-notif-dot"><?= $_unread ?></span>
        <?php endif; ?>
      </a>

      <!-- User menu -->
      <div class="mk-hdr-user" id="mkUserMenu">
        <div class="mk-hdr-avatar"><?= e($_user_initial) ?></div>
        <div class="d-none d-sm-block">
          <div class="mk-hdr-uname"><?= e($_full_name) ?></div>
          <div class="mk-hdr-urole"><?= e($_role_label) ?></div>
        </div>
        <i class="fas fa-chevron-down mk-hdr-caret"></i>

        <!-- Dropdown -->
        <div class="mk-user-dropdown" id="mkUserDrop">
          <a href="profile.php"><i class="fas fa-user-circle"></i> الملف الشخصي</a>
          <a href="profile.php?tab=subscription"><i class="fas fa-crown"></i> اشتراكي — <?= e($_pkg_name) ?></a>
          <?php if ($_sub_end): ?>
          <a href="profile.php?tab=subscription" style="font-size:12px;color:var(--mk-t4)">
            <i class="fas fa-calendar-alt"></i>
            ينتهي: <?= dDate($_sub_end) ?>
          </a>
          <?php endif; ?>
          <div class="divider"></div>
          <a href="../logout.php" class="danger"><i class="fas fa-right-from-bracket"></i> تسجيل الخروج</a>
        </div>
      </div>

    </header>
    <!-- /Top Header -->

    <!-- Alert messages -->
    <?php if (isset($_GET['msg'])): ?>
    <div style="padding: 12px 24px 0">
    <?php
    $msgs = [
        'saved'          => ['success', 'تم الحفظ بنجاح'],
        'deleted'        => ['success', 'تم الحذف بنجاح'],
        'updated'        => ['success', 'تم التحديث بنجاح'],
        'session_saved'  => ['success', 'تم تسجيل الجلسة بنجاح'],
        'uploaded'       => ['success', 'تم رفع الملف بنجاح'],
        'upload_failed'  => ['danger',  'تعذّر رفع الملف. تحقّق من نوعه وحجمه وحاول مجدداً'],
        'archived'       => ['success', 'تمت أرشفة الملف بنجاح'],
        'case_archived'  => ['success', 'تمت أرشفة القضية بكامل بياناتها وملفاتها. تجدها في «القضايا المؤرشفة».'],
        'case_restored'  => ['success', 'تمت استعادة القضية من الأرشيف'],
        'archive_active' => ['warning', 'لا يمكن أرشفة قضية غير منتهية — غيّر حالتها إلى مغلقة/مكسوبة/خاسرة/متسوية أولاً'],
        'wa_fail'        => ['warning', 'حُفظ الرقم لكن رسالة الاختبار لم تصل — تأكد من الرقم ومن مفتاح CallMeBot ومن إرسالك رسالة السماح للبوت.'],
        'error'          => ['danger',  'حدث خطأ، يرجى المحاولة مجدداً'],
        'limit'          => ['warning', 'لقد وصلت إلى الحد الأقصى لباقتك. يرجى الترقية.'],
        'feature_locked' => ['warning', 'هذه الميزة غير متوفرة في باقتك الحالية. يرجى الترقية.'],
        'denied'         => ['danger',  'ليست لديك صلاحية لهذا الإجراء. تواصل مع مالك المكتب.'],
        'gd_connected'   => ['success', 'تم ربط Google Drive بنجاح'],
        'gd_disconnected'=> ['success', 'تم فصل Google Drive'],
        'gd_fail'        => ['danger',  'تعذّر ربط Google Drive — راجع التفاصيل أدناه'],
        'gd_needconnect' => ['warning', 'اربط حساب Google Drive أولاً ثم اختر هذا الخيار'],
        'gd_noconfig'    => ['warning', 'خاصية Google Drive غير مُفعّلة على مستوى المنصة بعد'],
        'req_sent'       => ['success', 'تم إرسال طلبك بنجاح'],
        'owner_only'     => ['warning', 'هذا الإجراء متاح لمالك المكتب فقط'],
    ];
    $msgKey = $_GET['msg'];
    [$type, $text] = $msgs[$msgKey] ?? ['info', e($msgKey)];
    ?>
    <div class="alert alert-<?= $type ?> alert-dismissible auto-dismiss d-flex align-items-center gap-2">
      <i class="fas fa-<?= $type==='success'?'check-circle':($type==='warning'?'exclamation-triangle':'times-circle') ?>"></i>
      <span><?= $text ?></span>
      <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    </div>
    <?php endif; ?>

    <!-- Page Content -->
    <div class="mk-content">
