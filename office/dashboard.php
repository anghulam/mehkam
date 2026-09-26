<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
$page_title = 'لوحة التحكم';
$oid = (int)$_SESSION['office_id'];
// اسم متغيّر مقصود التميّز عن $_can_finance في office_header.php (ذاك يفحص توفّر الميزة
// بالباقة hasFeature عبر include بنفس نطاق المتغيّرات، وهذا يفحص صلاحية الموظّف نفسه —
// إعادة استخدام نفس الاسم كانت تجعل include يطغى عليه ويُظهر البيانات المالية للجميع)
$_permFinance = can('finance','view'); // إخفاء كل الأرقام المالية عن موظّف بلا صلاحية الشؤون المالية
$_restricted  = isRestricted(); // موظّف "نطاق بيانات مقيَّد" يرى قضاياه/جلساته/مهامه فقط، لا لوحة المكتب كاملة
$_uid         = (int) ($_SESSION['user_id'] ?? 0);
// بعض المهام القديمة أُسندت باسم حر (assigned_to) دون ربطها فعلياً بحساب المستخدم
// (assigned_to_id) — نطابق بالاسم أيضاً حتى لا تختفي هذه المهام عن صاحبها
$_uname       = $conn->real_escape_string($_SESSION['full_name'] ?? '');
$_myTaskScope = '';
if ($_restricted) {
    $_myTaskScope = " AND (assigned_to_id=$_uid OR (assigned_to_id IS NULL AND assigned_to='$_uname')";
    if (hasModule($conn, $oid, 'absence_delegation')) {
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS absence_delegations (
                id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, from_user_id INT NOT NULL, to_user_id INT NOT NULL,
                start_date DATE NOT NULL, end_date DATE NOT NULL, reason VARCHAR(255) DEFAULT NULL,
                status ENUM('active','ended') DEFAULT 'active', created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_office (office_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $_myTaskScope .= " OR assigned_to_id IN (SELECT from_user_id FROM absence_delegations WHERE office_id=$oid AND to_user_id=$_uid AND status='active' AND CURDATE() BETWEEN start_date AND end_date)";
        } catch (\Throwable $e) {}
    }
    $_myTaskScope .= ")";
}

/* ── إحصائيات سريعة ── */
$total_cases       = (int)dbVal($conn,"SELECT COUNT(*) FROM cases WHERE office_id=$oid"); // إجمالي المكتب دوماً (يقارَن بحد الباقة)
$active_cases      = (int)dbVal($conn,"SELECT COUNT(*) FROM cases WHERE office_id=$oid AND status='active'" . caseScope('cases'));
$total_clients     = (int)dbVal($conn,"SELECT COUNT(*) FROM clients WHERE office_id=$oid");
$pending_tasks     = (int)dbVal($conn,"SELECT COUNT(*) FROM tasks WHERE office_id=$oid AND status IN('pending','in_progress')$_myTaskScope");
$upcoming_sessions = (int)dbVal($conn,"SELECT COUNT(*) FROM sessions WHERE office_id=$oid AND session_date>=NOW() AND status='scheduled'" . finScope());
// تُقيَّد الأرقام المالية لموظّف "نطاق بيانات مقيَّد" على قضاياه المُسندة فقط — لا يرى أرقام المكتب كاملة
$total_income      = (float)dbVal($conn,"SELECT IFNULL(SUM(amount),0) FROM transactions WHERE office_id=$oid AND type='income'" . finScope());
$total_expense     = (float)dbVal($conn,"SELECT IFNULL(SUM(amount),0) FROM transactions WHERE office_id=$oid AND type='expense'" . finScope());
$unpaid_invoices   = (int)dbVal($conn,"SELECT COUNT(*) FROM invoices WHERE office_id=$oid AND status IN('sent','overdue')" . finScope());

/* ── الجلسات القريبة (قضايا الموظّف المُسندة فقط إن كان مقيَّداً) ── */
$soon_sessions = $conn->query("
    SELECT c.case_number, c.title, c.priority, c.court_name, c.next_session,
           cl.full_name client_name
    FROM cases c
    LEFT JOIN clients cl ON c.client_id = cl.id
    WHERE c.office_id=$oid AND c.next_session IS NOT NULL
      AND c.status='active' AND c.next_session >= CURDATE()" . caseScope('c') . "
    ORDER BY c.next_session ASC
    LIMIT 6
");

/* ── مهام جارية (مهامي فقط إن كنت موظّفاً مقيَّداً) ── */
$active_tasks = $conn->query("
    SELECT * FROM tasks
    WHERE office_id=$oid AND status IN('pending','in_progress')$_myTaskScope
    ORDER BY FIELD(priority,'urgent','high','medium','low'), due_date ASC
    LIMIT 6
");

/* ── آخر المعاملات ── */
$recent_trans = $conn->query("
    SELECT t.*, cl.full_name client_name
    FROM transactions t
    LEFT JOIN clients cl ON t.client_id = cl.id
    WHERE t.office_id=$oid" . finScope('t') . "
    ORDER BY t.created_at DESC
    LIMIT 6
");

/* ── آخر الفواتير ── */
$recent_invoices = $conn->query("
    SELECT inv.*, cl.full_name client_name
    FROM invoices inv
    LEFT JOIN clients cl ON inv.client_id = cl.id
    WHERE inv.office_id=$oid" . finScope('inv') . "
    ORDER BY inv.created_at DESC
    LIMIT 4
");

/* ── الأداء المالي آخر 6 أشهر — من الفواتير المدفوعة (نفس مصدر الشؤون المالية) ──
   نبني هيكل الأشهر الستة كاملاً (حتى لو ما فيه بيانات لبعضها) لأن رسم بياني بنقطة
   وحيدة لا يظهر بشكل مرئي في ApexCharts (لا خط ولا مساحة تُرسم بين نقطة واحدة). */
$months_labels = $months_income = $months_expense = [];
$_ym_index = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $months_labels[] = date('n/Y', strtotime($ym.'-01'));
    $_ym_index[$ym]  = count($months_income);
    $months_income[]  = 0.0;
    $months_expense[] = 0.0;
}
$monthly = $conn->query("
    SELECT DATE_FORMAT(COALESCE(paid_date, created_at),'%Y-%m') ym,
           SUM(CASE WHEN direction='expense' THEN 0 ELSE total END) income,
           SUM(CASE WHEN direction='expense' THEN total ELSE 0 END) expense
    FROM invoices
    WHERE office_id=$oid AND status='paid'
      AND COALESCE(paid_date, created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)" . finScope() . "
    GROUP BY ym ORDER BY ym ASC
");
$_has_invoice_data = false;
if ($monthly) while ($m = $monthly->fetch_assoc()) {
    if (!isset($_ym_index[$m['ym']])) continue;
    $idx = $_ym_index[$m['ym']];
    $months_income[$idx]  = (float)$m['income'];
    $months_expense[$idx] = (float)$m['expense'];
    $_has_invoice_data = true;
}
// احتياطي: لو ما فيه فواتير، اسحب من القيود المالية
if (!$_has_invoice_data) {
    $mt = $conn->query("
        SELECT DATE_FORMAT(transaction_date,'%Y-%m') ym,
               SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) income,
               SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) expense
        FROM transactions
        WHERE office_id=$oid AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)" . finScope() . "
        GROUP BY ym ORDER BY ym ASC");
    if ($mt) while ($m = $mt->fetch_assoc()) {
        if (!isset($_ym_index[$m['ym']])) continue;
        $idx = $_ym_index[$m['ym']];
        $months_income[$idx]  = (float)$m['income'];
        $months_expense[$idx] = (float)$m['expense'];
    }
}

/* ── توزيع حالات القضايا (قضاياي فقط إن كنت مقيَّداً) ── */
$case_status_q = $conn->query("
    SELECT status, COUNT(*) cnt FROM cases
    WHERE office_id=$oid" . caseScope('cases') . " GROUP BY status
");
$caseStatuses = $caseCounts = [];
while ($r = $case_status_q->fetch_assoc()) {
    $labels = ['active'=>'نشطة','closed'=>'مغلقة','won'=>'مكسوبة','lost'=>'خاسرة','settled'=>'مُسوّاة','suspended'=>'موقوفة','pending'=>'قيد الفتح','archived'=>'مؤرشفة'];
    $caseStatuses[] = $labels[$r['status']] ?? $r['status'];
    $caseCounts[]   = (int)$r['cnt'];
}

/* ── الحد الأقصى للباقة ── */
$max_cases   = getFeatureLimit($conn, $oid, 'max_cases');
$max_clients = getFeatureLimit($conn, $oid, 'max_clients');

// تنبيه النسخة التجريبية
$_trial_banner = null;
$_office_info = $conn->query("SELECT status, subscription_end FROM offices WHERE id=$oid LIMIT 1")->fetch_assoc();
if ($_office_info && $_office_info['status'] === 'trial' && !empty($_office_info['subscription_end'])) {
    $_trial_days_left = (int)ceil((strtotime($_office_info['subscription_end']) - time()) / 86400);
    $_trial_banner = max(0, $_trial_days_left);
}

/* ══ مركز قيادة المدير (لمالك المكتب) ══ */
$_is_manager = currentRole() === 'office_owner';
if ($_is_manager) {
    $today = date('Y-m-d');
    $md = [];
    $md['sessions'] = (int)dbVal($conn, "SELECT COUNT(*) FROM sessions WHERE office_id=$oid AND status='scheduled' AND DATE(session_date)='$today'")
                    + (int)dbVal($conn, "SELECT COUNT(*) FROM cases WHERE office_id=$oid AND status='active' AND DATE(next_session)='$today'");
    $md['appointments'] = (int)dbVal($conn, "SELECT COUNT(*) FROM appointments WHERE office_id=$oid AND DATE(appointment_date)='$today'");
    $md['due_today']  = (int)dbVal($conn, "SELECT COUNT(*) FROM tasks WHERE office_id=$oid AND DATE(due_date)='$today' AND status IN('pending','in_progress')");
    $md['done_today'] = (int)dbVal($conn, "SELECT COUNT(*) FROM tasks WHERE office_id=$oid AND status='completed' AND DATE(completed_at)='$today'");
    $md['overdue']    = (int)dbVal($conn, "SELECT COUNT(*) FROM tasks WHERE office_id=$oid AND due_date<'$today' AND status IN('pending','in_progress')");
    $md['idle_cases'] = (int)dbVal($conn, "SELECT COUNT(*) FROM cases WHERE office_id=$oid AND status='active' AND (next_session IS NULL OR next_session < '$today')");

    // أداء المستخدمين — نسبة الإنجاز = المنجزة ÷ (المنجزة + المفتوحة)
    $md['users'] = [];
    $teamDone = $teamOpen = 0;
    $ur = $conn->query("
        SELECT u.id, u.full_name, u.role,
          SUM(CASE WHEN t.status='completed' THEN 1 ELSE 0 END) done,
          SUM(CASE WHEN t.status IN('pending','in_progress') THEN 1 ELSE 0 END) open,
          SUM(CASE WHEN t.due_date<'$today' AND t.status IN('pending','in_progress') THEN 1 ELSE 0 END) late
        FROM users u
        LEFT JOIN tasks t ON t.assigned_to_id=u.id AND t.office_id=$oid
        WHERE u.office_id=$oid AND u.is_active=1
        GROUP BY u.id, u.full_name, u.role ORDER BY done DESC, u.full_name");
    if ($ur) while ($u = $ur->fetch_assoc()) {
        $dn = (int)$u['done']; $op = (int)$u['open'];
        $u['pct'] = ($dn + $op) ? round($dn / ($dn + $op) * 100) : null;
        $teamDone += $dn; $teamOpen += $op;
        $md['users'][] = $u;
    }
    $md['team_pct'] = ($teamDone + $teamOpen) ? round($teamDone / ($teamDone + $teamOpen) * 100) : 100;

    // جلسات اليوم (تفصيل)
    $md['today_list'] = $conn->query("SELECT c.case_number, c.title, c.court_name, s.session_date
        FROM sessions s JOIN cases c ON s.case_id=c.id
        WHERE s.office_id=$oid AND s.status='scheduled' AND DATE(s.session_date)='$today'
        ORDER BY s.session_date ASC LIMIT 6");

    // مواعيد قادمة (7 أيام)
    $md['upcoming'] = $conn->query("SELECT case_number, title, next_session FROM cases
        WHERE office_id=$oid AND status='active' AND DATE(next_session) > '$today'
          AND DATE(next_session) <= DATE_ADD('$today', INTERVAL 7 DAY)
        ORDER BY next_session ASC LIMIT 6");

    // تنبيهات ومخاطر
    $md['alerts'] = [];
    $soon = $conn->query("SELECT c.case_number, s.session_date FROM sessions s JOIN cases c ON s.case_id=c.id
        WHERE s.office_id=$oid AND s.status='scheduled' AND s.session_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 HOUR)
        ORDER BY s.session_date ASC LIMIT 3");
    if ($soon) while ($r = $soon->fetch_assoc()) {
        $md['alerts'][] = ['lvl'=>'red', 'txt'=>'جلسة ' . $r['case_number'] . ' خلال ساعات — الساعة ' . date('H:i', strtotime($r['session_date']))];
    }
    if ($md['overdue'] > 0)    $md['alerts'][] = ['lvl'=>'orange', 'txt'=>$md['overdue'] . ' مهمة متأخرة تحتاج متابعة عاجلة'];
    if ($md['idle_cases'] > 0) $md['alerts'][] = ['lvl'=>'orange', 'txt'=>$md['idle_cases'] . ' قضية نشطة بدون جلسة قادمة'];
    $lateStaff = array_filter($md['users'], fn($u) => (int)$u['late'] > 0);
    if ($lateStaff) $md['alerts'][] = ['lvl'=>'orange', 'txt'=>count($lateStaff) . ' من الفريق لديهم مهام متأخرة'];
    if (!$md['alerts']) $md['alerts'][] = ['lvl'=>'green', 'txt'=>'لا تنبيهات عاجلة — كل شيء تحت السيطرة ✨'];
}

include '../includes/office_header.php';
?>


<?php if ($_is_manager): $C = 326.726; $off = round($C * (1 - min(100,max(0,$md['team_pct'])) / 100), 1); ?>
<style>
.mgr{margin-bottom:1.5rem}
.mgr *{box-sizing:border-box}
.mgr-hero{display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;
  background:linear-gradient(135deg,var(--mk-navy) 0%,var(--mk-navy4) 100%);
  border-radius:16px 16px 0 0;padding:22px 26px;color:#fff;position:relative;overflow:hidden}
.mgr-hero::after{content:"";position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(circle at 88% -25%,rgba(184,134,11,.4),transparent 46%)}
.mgr-hero-l{position:relative;z-index:1}
.mgr-eyebrow{font-size:12px;letter-spacing:.5px;color:var(--mk-gold4);font-weight:700;margin-bottom:7px}
.mgr-date{font-size:20px;font-weight:800;line-height:1.3}
.mgr-greg{font-size:12.5px;opacity:.55;margin-top:3px}
.mgr-ring{position:relative;width:104px;height:104px;flex-shrink:0;z-index:1}
.mgr-ring svg{width:100%;height:100%;transform:rotate(-90deg)}
.mgr-ring circle{fill:none;stroke-width:9;stroke-linecap:round}
.mgr-ring .bg{stroke:rgba(255,255,255,.14)}
.mgr-ring .fg{stroke:var(--mk-gold4);transition:stroke-dashoffset .9s ease}
.mgr-ring-txt{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
.mgr-ring-txt b{font-size:23px;font-weight:800;line-height:1}
.mgr-ring-txt small{font-size:9.5px;opacity:.72;margin-top:3px}

.mgr-kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:1px;background:var(--mk-border);
  border:1px solid var(--mk-border);border-top:0;border-radius:0 0 4px 4px;overflow:hidden}
.mgr-kpi{background:var(--mk-card);padding:16px 10px;text-align:center;text-decoration:none;display:block;transition:background .15s}
.mgr-kpi:hover{background:var(--mk-bg)}
.mgr-kpi i{font-size:15px;margin-bottom:9px;display:block}
.mgr-kpi b{font-size:24px;font-weight:800;color:var(--mk-t1);display:block;line-height:1}
.mgr-kpi span{font-size:11.5px;color:var(--mk-t4);margin-top:6px;display:block}
.mgr-kpi.info i{color:var(--c-info)} .mgr-kpi.gold i{color:var(--mk-gold2)}
.mgr-kpi.warning i{color:var(--c-warning)} .mgr-kpi.success i{color:var(--c-success)}
.mgr-kpi.danger i{color:var(--c-danger)}
.mgr-kpi.hot b{color:var(--c-danger)}

.mgr-cols{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}
.mgr-panel{background:var(--mk-card);border:1px solid var(--mk-border);border-radius:12px;box-shadow:var(--sh-sm);overflow:hidden}
.mgr-panel-h{font-size:13.5px;font-weight:700;color:var(--mk-t2);padding:13px 16px;
  border-bottom:1px solid var(--mk-border);display:flex;align-items:center;gap:8px}
.mgr-panel-h i{color:var(--mk-gold2)}
.mgr-panel-b{padding:6px 16px 14px}

.mgr-user{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--mk-bg2)}
.mgr-user:last-child{border-bottom:0}
.mgr-user-nm{font-size:12.5px;color:var(--mk-t2);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mgr-user-bar{width:84px;height:7px;border-radius:4px;background:var(--mk-bg2);overflow:hidden;flex-shrink:0}
.mgr-user-bar i{display:block;height:100%;border-radius:4px}
.mgr-user-pct{font-size:12px;font-weight:700;width:36px;text-align:left;flex-shrink:0}
.mgr-chip-late{font-size:10px;background:rgba(220,38,38,.1);color:var(--c-danger);padding:1px 6px;border-radius:20px;flex-shrink:0}

.mgr-alert{display:flex;gap:9px;align-items:flex-start;padding:10px 12px;border-radius:9px;
  font-size:12.5px;margin-bottom:7px;border-right:3px solid;line-height:1.5}
.mgr-alert:last-child{margin-bottom:0}
.mgr-alert.red{background:rgba(220,38,38,.07);border-color:var(--c-danger);color:#991b1b}
.mgr-alert.orange{background:rgba(217,119,6,.08);border-color:var(--c-warning);color:#92400e}
.mgr-alert.green{background:rgba(5,150,105,.08);border-color:var(--c-success);color:#065f46}
.mgr-alert i{margin-top:2px;flex-shrink:0}

.mgr-li{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--mk-bg2);font-size:12.5px}
.mgr-li:last-child{border-bottom:0}
.mgr-li-time{background:var(--mk-navy);color:var(--mk-gold4);font-size:11px;font-weight:700;
  padding:3px 8px;border-radius:6px;flex-shrink:0;min-width:54px;text-align:center}
.mgr-li-nm{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--mk-t2)}
.mgr-li-meta{font-size:11px;color:var(--mk-t4);flex-shrink:0;max-width:38%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mgr-empty{font-size:12px;color:var(--mk-t4);padding:16px 0;text-align:center}
.mgr-more{display:inline-block;margin-top:11px;font-size:12px;color:var(--mk-gold2);text-decoration:none;font-weight:600}
.mgr-more:hover{color:var(--mk-gold)}

@media(max-width:900px){
  .mgr-kpis{grid-template-columns:repeat(3,1fr)}
  .mgr-cols{grid-template-columns:1fr}
}
@media(max-width:480px){
  .mgr-kpis{grid-template-columns:repeat(2,1fr)}
  .mgr-hero{padding:18px}
  .mgr-user-bar{width:56px}
}
</style>

<section class="mgr">
  <div class="mgr-hero">
    <div class="mgr-hero-l">
      <div class="mgr-eyebrow"><i class="fas fa-gauge-high me-1"></i>مركز قيادة المدير</div>
      <div class="mgr-date"><?= e(dayName($today)) ?> · <?= e(hijriDate($today)) ?></div>
      <div class="mgr-greg"><?= date('d/m/Y') ?> م</div>
    </div>
    <div class="mgr-ring" title="نسبة إنجاز مهام الفريق">
      <svg viewBox="0 0 120 120">
        <circle class="bg" cx="60" cy="60" r="52"></circle>
        <circle class="fg" cx="60" cy="60" r="52" stroke-dasharray="<?= $C ?>" stroke-dashoffset="<?= $off ?>"></circle>
      </svg>
      <div class="mgr-ring-txt"><b><?= (int)$md['team_pct'] ?>%</b><small>إنجاز الفريق</small></div>
    </div>
  </div>

  <div class="mgr-kpis">
    <?php
    $kpis = [
      ['sessions_pdf.php?from='.date('Y-m-d').'&to='.date('Y-m-d').'&view=1', 'fa-gavel', 'info', $md['sessions'], 'جلسات اليوم'],
      ['tasks.php?tab=appointments',  'fa-calendar-day',      'gold',    $md['appointments'], 'مواعيد اليوم'],
      ['tasks.php',                   'fa-list-check',        'warning', $md['due_today'],    'مهام مستحقة'],
      ['tasks.php',                   'fa-circle-check',      'success', $md['done_today'],   'مهام منجزة'],
      ['tasks.php',                   'fa-clock-rotate-left', 'danger',  $md['overdue'],     'مهام متأخرة'],
      ['cases.php',                   'fa-folder-open',       'danger',  $md['idle_cases'],  'قضايا بلا إجراء'],
    ];
    foreach ($kpis as $i => $k):
      $hot = ($i >= 4 && $k[3] > 0) ? ' hot' : '';
    ?>
    <a class="mgr-kpi <?= $k[2] . $hot ?>" href="<?= $k[0] ?>">
      <i class="fas <?= $k[1] ?>"></i>
      <b><?= (int)$k[3] ?></b>
      <span><?= $k[4] ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="mgr-cols">
    <div class="mgr-panel">
      <div class="mgr-panel-h"><i class="fas fa-users"></i>أداء الفريق</div>
      <div class="mgr-panel-b">
        <?php if (!$md['users']): ?>
          <div class="mgr-empty">لا مستخدمين نشطين</div>
        <?php else: foreach ($md['users'] as $u): $p = $u['pct'];
          $col = $p === null ? 'var(--mk-t5)' : ($p >= 75 ? 'var(--c-success)' : ($p >= 40 ? 'var(--c-warning)' : 'var(--c-danger)')); ?>
        <div class="mgr-user">
          <span class="mgr-user-nm"><?= e($u['full_name']) ?></span>
          <?php if ((int)$u['late'] > 0): ?><span class="mgr-chip-late"><?= (int)$u['late'] ?> متأخرة</span><?php endif; ?>
          <span class="mgr-user-bar"><i style="width:<?= $p === null ? 0 : max(3, $p) ?>%;background:<?= $col ?>"></i></span>
          <span class="mgr-user-pct" style="color:<?= $col ?>"><?= $p === null ? '—' : $p . '%' ?></span>
        </div>
        <?php endforeach; endif; ?>
        <a class="mgr-more" href="performance.php">التقرير الكامل للأداء ←</a>
      </div>
    </div>

    <div class="mgr-panel">
      <div class="mgr-panel-h"><i class="fas fa-triangle-exclamation"></i>تنبيهات ومخاطر</div>
      <div class="mgr-panel-b">
        <?php foreach ($md['alerts'] as $a):
          $ic = $a['lvl'] === 'red' ? 'fa-circle-exclamation' : ($a['lvl'] === 'orange' ? 'fa-triangle-exclamation' : 'fa-circle-check'); ?>
        <div class="mgr-alert <?= $a['lvl'] ?>"><i class="fas <?= $ic ?>"></i><span><?= e($a['txt']) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if (($md['today_list'] && $md['today_list']->num_rows) || ($md['upcoming'] && $md['upcoming']->num_rows)): ?>
  <div class="mgr-cols">
    <div class="mgr-panel">
      <div class="mgr-panel-h"><i class="fas fa-calendar-check"></i>جلسات اليوم</div>
      <div class="mgr-panel-b">
        <?php if (!$md['today_list'] || !$md['today_list']->num_rows): ?>
          <div class="mgr-empty">لا جلسات مجدولة اليوم</div>
        <?php else: while ($s = $md['today_list']->fetch_assoc()): ?>
        <div class="mgr-li">
          <span class="mgr-li-time"><?= date('H:i', strtotime($s['session_date'])) ?></span>
          <span class="mgr-li-nm"><?= e($s['case_number']) ?> — <?= e($s['title']) ?></span>
          <?php if (!empty($s['court_name'])): ?><span class="mgr-li-meta"><?= e($s['court_name']) ?></span><?php endif; ?>
        </div>
        <?php endwhile; endif; ?>
      </div>
    </div>
    <div class="mgr-panel">
      <div class="mgr-panel-h"><i class="fas fa-forward"></i>مواعيد قادمة (7 أيام)</div>
      <div class="mgr-panel-b">
        <?php if (!$md['upcoming'] || !$md['upcoming']->num_rows): ?>
          <div class="mgr-empty">لا مواعيد خلال الأسبوع القادم</div>
        <?php else: while ($c = $md['upcoming']->fetch_assoc()):
          $dl = (int)floor((strtotime($c['next_session']) - strtotime($today)) / 86400); ?>
        <div class="mgr-li">
          <span class="mgr-li-time"><?= $dl <= 1 ? 'غداً' : 'بعد ' . $dl ?></span>
          <span class="mgr-li-nm"><?= e($c['case_number']) ?> — <?= e($c['title']) ?></span>
          <span class="mgr-li-meta"><?= e(hijriDate($c['next_session'], true, false)) ?></span>
        </div>
        <?php endwhile; endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($_trial_banner !== null): ?>
<div class="alert d-flex align-items-center gap-3 mb-3 py-2"
     style="background:linear-gradient(135deg,#78350f,#b45309);border:none;border-radius:10px;color:#fff">
  <i class="fas fa-clock fa-lg" style="flex-shrink:0"></i>
  <div style="flex:1">
    <strong>النسخة التجريبية</strong> —
    <?php if ($_trial_banner > 0): ?>
      متبقي <strong><?= $_trial_banner ?></strong> يوم على انتهاء الفترة التجريبية.
    <?php else: ?>
      آخر يوم في الفترة التجريبية.
    <?php endif; ?>
  </div>
  <a href="profile.php?tab=subscription" class="btn btn-sm"
     style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff;white-space:nowrap">
    <i class="fas fa-crown me-1"></i>ترقية الباقة
  </a>
</div>
<?php endif; ?>

<!-- ═══ Stats Row ═══ -->
<?php $_statCol = $_permFinance ? 'col-6 col-xl-3' : 'col-6 col-xl-4'; ?>
<div class="row g-3 mb-4">
  <div class="<?= $_statCol ?>">
    <a href="cases.php" class="mk-stat info text-decoration-none">
      <div class="mk-stat-icon info"><i class="fas fa-gavel"></i></div>
      <div class="mk-stat-body">
        <div class="mk-stat-val"><?= $active_cases ?></div>
        <div class="mk-stat-lbl">قضية نشطة</div>
        <?php if ($max_cases > 0): ?>
        <div class="mk-stat-trend up">
          <i class="fas fa-chart-line"></i>
          <?= $total_cases ?> / <?= $max_cases ?>
        </div>
        <?php endif; ?>
      </div>
    </a>
  </div>
  <div class="<?= $_statCol ?>">
    <a href="clients.php" class="mk-stat success text-decoration-none">
      <div class="mk-stat-icon success"><i class="fas fa-users"></i></div>
      <div class="mk-stat-body">
        <div class="mk-stat-val"><?= $total_clients ?></div>
        <div class="mk-stat-lbl">إجمالي العملاء</div>
        <?php if ($max_clients > 0): ?>
        <div class="mk-stat-trend up"><i class="fas fa-user-plus"></i> حد <?= $max_clients ?></div>
        <?php endif; ?>
      </div>
    </a>
  </div>
  <div class="<?= $_statCol ?>">
    <a href="tasks.php" class="mk-stat warning text-decoration-none">
      <div class="mk-stat-icon warning"><i class="fas fa-list-check"></i></div>
      <div class="mk-stat-body">
        <div class="mk-stat-val"><?= $pending_tasks ?></div>
        <div class="mk-stat-lbl">مهام معلقة</div>
        <div class="mk-stat-trend <?= $upcoming_sessions>0?'up':'' ?>">
          <i class="fas fa-calendar"></i> <?= $upcoming_sessions ?> جلسة قادمة
        </div>
      </div>
    </a>
  </div>
  <?php if ($_permFinance): ?>
  <div class="<?= $_statCol ?>">
    <a href="invoices.php" class="mk-stat danger text-decoration-none">
      <div class="mk-stat-icon danger"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="mk-stat-body">
        <div class="mk-stat-val"><?= $unpaid_invoices ?></div>
        <div class="mk-stat-lbl">فواتير بانتظار الدفع</div>
        <div class="mk-stat-trend down"><i class="fas fa-clock"></i> تحتاج متابعة</div>
      </div>
    </a>
  </div>
  <?php endif; ?>
</div>

<!-- ═══ Revenue Banner ═══ -->
<?php if ($_permFinance): ?>
<div class="mk-rev-card mb-4">
  <div style="flex:1;min-width:0;position:relative;z-index:1">
    <div class="mk-rev-label">صافي الإيرادات المحصّلة</div>
    <div class="d-flex align-items-baseline gap-2 mt-1">
      <div class="mk-rev-amount"><?= number_format($total_income - $total_expense, 0, '.', ',') ?></div>
      <span class="mk-rev-cur">ر.س</span>
    </div>
    <div style="font-size:12px;opacity:.55;margin-top:6px;color:#fff">
      إيرادات: <?= number_format($total_income) ?> ر.س &nbsp;·&nbsp;
      مصروفات: <?= number_format($total_expense) ?> ر.س
    </div>
  </div>
  <div class="d-flex gap-2 flex-shrink-0" style="position:relative;z-index:1">
    <a href="finance.php" class="btn btn-sm" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff">
      <i class="fas fa-chart-bar me-1"></i>التقارير المالية
    </a>
    <?php if ($_can_invoices): ?>
    <a href="invoices.php" class="btn btn-gold btn-sm">
      <i class="fas fa-plus me-1"></i>فاتورة جديدة
    </a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ═══ Main Grid ═══ -->
<div class="row g-3">

  <!-- الجلسات القريبة -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <span><i class="fas fa-calendar-alt text-info me-2"></i>أقرب الجلسات</span>
        <a href="sessions_pdf.php?view=1" class="btn btn-outline-primary btn-sm">عرض الكل</a>
      </div>
      <div class="card-body p-0">
        <?php if ($soon_sessions->num_rows === 0): ?>
        <div class="mk-empty">
          <div class="mk-empty-icon"><i class="fas fa-calendar-check"></i></div>
          <div class="mk-empty-title">لا توجد جلسات قريبة</div>
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
        <?php while ($c = $soon_sessions->fetch_assoc()):
          $daysLeft = (int)((strtotime($c['next_session']) - time()) / 86400);
          $urgency  = $daysLeft <= 1 ? 'danger' : ($daysLeft <= 3 ? 'warning' : 'success');
        ?>
          <div class="list-group-item px-3 py-2 border-0 border-bottom">
            <div class="d-flex align-items-start gap-3">
              <div class="text-center flex-shrink-0"
                   style="min-width:42px;padding:4px 0;background:var(--mk-bg);border-radius:8px">
                <?php $hp = (calPref() !== 'gregorian') ? hijriParts($c['next_session']) : null; ?>
                <?php if ($hp): ?>
                <div style="font-size:17px;font-weight:800;color:var(--mk-t1);line-height:1"><?= $hp[2] ?></div>
                <div style="font-size:9px;color:var(--mk-t4)"><?= mb_substr($GLOBALS['_hijri_months'][$hp[1]] ?? '', 0, 6) ?></div>
                <div style="font-size:8px;color:var(--mk-t4)"><?= date('d/m', strtotime($c['next_session'])) ?></div>
                <?php else: ?>
                <div style="font-size:17px;font-weight:800;color:var(--mk-t1);line-height:1"><?= date('d', strtotime($c['next_session'])) ?></div>
                <div style="font-size:10px;color:var(--mk-t4)"><?= date('M', strtotime($c['next_session'])) ?></div>
                <?php endif; ?>
              </div>
              <div style="flex:1;min-width:0">
                <div class="fw-semibold text-truncate" style="font-size:13px"><?= e($c['title']) ?></div>
                <div style="font-size:12px;color:var(--mk-t4)">
                  <?= e($c['client_name'] ?? '—') ?> · <?= e($c['court_name']) ?>
                </div>
              </div>
              <div class="text-end flex-shrink-0">
                <?= priorityBadge($c['priority']) ?>
                <div class="badge bg-<?= $urgency ?>-subtle text-<?= $urgency ?> mt-1" style="font-size:10px">
                  <?= $daysLeft === 0 ? 'اليوم' : ($daysLeft === 1 ? 'غداً' : "بعد $daysLeft أيام") ?>
                </div>
              </div>
            </div>
          </div>
        <?php endwhile; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- المهام الجارية -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <span><i class="fas fa-list-check text-warning me-2"></i>المهام الجارية</span>
        <a href="tasks.php" class="btn btn-outline-warning btn-sm">عرض الكل</a>
      </div>
      <div class="card-body p-0">
        <?php if ($active_tasks->num_rows === 0): ?>
        <div class="mk-empty">
          <div class="mk-empty-icon"><i class="fas fa-check-double"></i></div>
          <div class="mk-empty-title">لا توجد مهام معلقة</div>
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
        <?php while ($t = $active_tasks->fetch_assoc()):
          $overdue = $t['due_date'] && strtotime($t['due_date']) < time() && $t['status'] !== 'completed';
        ?>
          <div class="list-group-item px-3 py-2 border-0 border-bottom">
            <div class="d-flex align-items-start gap-2">
              <div class="mt-1 flex-shrink-0">
                <?php if ($t['status'] === 'in_progress'): ?>
                <i class="fas fa-spinner fa-spin text-primary" style="font-size:13px"></i>
                <?php else: ?>
                <i class="fas fa-circle" style="font-size:10px;color:var(--mk-t5)"></i>
                <?php endif; ?>
              </div>
              <div style="flex:1;min-width:0">
                <div class="fw-semibold text-truncate" style="font-size:13px;<?= $overdue?'text-decoration:line-through;opacity:.6':'' ?>"><?= e($t['title']) ?></div>
                <div class="d-flex align-items-center gap-2 mt-1">
                  <?= priorityBadge($t['priority']) ?>
                  <?php if ($t['assigned_to']): ?>
                  <span style="font-size:11px;color:var(--mk-t4)">
                    <i class="fas fa-user-circle me-1"></i><?= e($t['assigned_to']) ?>
                  </span>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($t['due_date']): ?>
              <span class="badge <?= $overdue ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-secondary' ?> flex-shrink-0" style="font-size:10px">
                <?= dDate($t['due_date']) ?>
              </span>
              <?php endif; ?>
            </div>
          </div>
        <?php endwhile; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- الرسم البياني للإيرادات -->
  <?php if ($_permFinance && !empty($months_labels)): ?>
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <span><i class="fas fa-chart-line me-2 text-primary"></i>الأداء المالي — آخر 6 أشهر</span>
        <span class="badge bg-success"><?= number_format(array_sum($months_income)) ?> ر.س إيرادات</span>
        <span class="badge bg-danger"><?= number_format(array_sum($months_expense)) ?> ر.س مصروفات</span>
      </div>
      <div class="card-body">
        <div id="revenueChart" style="min-height:220px"></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- توزيع القضايا -->
  <?php if (!empty($caseStatuses)): ?>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <span><i class="fas fa-chart-donut text-primary me-2"></i>حالات القضايا</span>
      </div>
      <div class="card-body">
        <div id="caseChart" style="min-height:230px"></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- آخر المعاملات -->
  <?php if ($_permFinance): ?>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">
        <span><i class="fas fa-exchange-alt text-success me-2"></i>آخر المعاملات</span>
        <a href="finance.php" class="btn btn-outline-success btn-sm">عرض الكل</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th>التاريخ</th><th>النوع</th><th>التصنيف</th><th>العميل</th><th>المبلغ</th>
            </tr></thead>
            <tbody>
            <?php while ($tr = $recent_trans->fetch_assoc()): ?>
            <tr>
              <td style="white-space:nowrap"><?= dDate($tr['transaction_date']) ?></td>
              <td><?= statusBadge($tr['type']) ?></td>
              <td><?= e($tr['category']) ?></td>
              <td><?= e($tr['client_name'] ?? '—') ?></td>
              <td class="fw-bold <?= $tr['type']==='income'?'text-success':'text-danger' ?>">
                <?= $tr['type']==='income'?'+':'-' ?><?= number_format($tr['amount']) ?> ر.س
              </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- آخر الفواتير -->
  <?php if ($_permFinance): ?>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">
        <span><i class="fas fa-file-invoice text-primary me-2"></i>آخر الفواتير</span>
        <?php if ($_can_invoices): ?>
        <a href="invoices.php" class="btn btn-outline-primary btn-sm">عرض الكل</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (!$_can_invoices): ?>
        <div class="mk-upgrade-banner m-3">
          <div class="mk-upgrade-icon"><i class="fas fa-crown"></i></div>
          <div class="mk-upgrade-body">
            <div class="mk-upgrade-title">ميزة الفواتير</div>
            <div class="mk-upgrade-desc">متوفرة في باقة الاحترافية وما فوق</div>
          </div>
          <a href="profile.php?tab=upgrade" class="btn btn-gold btn-sm flex-shrink-0">ترقية</a>
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
        <?php while ($inv = $recent_invoices->fetch_assoc()): ?>
          <div class="list-group-item px-3 py-2 border-0 border-bottom d-flex align-items-center gap-2">
            <div style="flex:1;min-width:0">
              <div class="fw-semibold" style="font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= e($inv['title']) ?>
              </div>
              <div style="font-size:11.5px;color:var(--mk-t4)">
                <?= e($inv['client_name'] ?? '—') ?> · <?= e($inv['invoice_number']) ?>
              </div>
            </div>
            <div class="text-end flex-shrink-0">
              <div class="mk-inv-badge <?= $inv['status'] ?>"><?= statusBadge($inv['status']) ?></div>
              <div class="fw-bold mt-1" style="font-size:12px"><?= number_format($inv['total']) ?> ر.س</div>
            </div>
          </div>
        <?php endwhile; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>


</div>

<!-- Charts (ApexCharts via CDN) -->
<?php if (!empty($months_labels) || !empty($caseStatuses)): ?>
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.1/dist/apexcharts.min.js"></script>
<script>
(function initDashCharts(tries){
  if (typeof ApexCharts === 'undefined') {
    if ((tries || 0) < 20) return setTimeout(function(){ initDashCharts((tries||0)+1); }, 150);
    return;
  }
  <?php if ($_permFinance && !empty($months_labels)): ?>
  try {
    var revEl = document.getElementById('revenueChart');
    if (revEl) new ApexCharts(revEl, {
      chart: { type: 'area', height: 220, toolbar: {show:false}, fontFamily: 'Tajawal,sans-serif' },
      series: [
        { name: 'الإيرادات (ر.س)',  data: <?= json_encode(array_map('floatval',$months_income)) ?> },
        { name: 'المصروفات (ر.س)', data: <?= json_encode(array_map('floatval',$months_expense)) ?> }
      ],
      xaxis: { categories: <?= json_encode($months_labels) ?> },
      colors: ['#059669','#dc2626'],
      fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05 } },
      stroke: { curve: 'smooth', width: 2 },
      dataLabels: { enabled: false },
      grid: { borderColor: '#f0f0f0' },
      yaxis: { labels: { formatter: v => v.toLocaleString('ar') } },
      tooltip: { y: { formatter: v => v.toLocaleString('ar') + ' ر.س' } }
    }).render();
  } catch (e) { console.error('revenue chart', e); }
  <?php endif; ?>

  <?php if (!empty($caseStatuses)): ?>
  try {
    var caseEl = document.getElementById('caseChart');
    if (caseEl) new ApexCharts(caseEl, {
      series: <?= json_encode(array_map('intval',$caseCounts)) ?>,
      labels: <?= json_encode(array_values($caseStatuses)) ?>,
      chart: { type: 'donut', height: 230, fontFamily: 'Tajawal, sans-serif' },
      colors: ['#2563eb','#64748b','#059669','#dc2626','#0891b2','#d97706','#7c3aed','#0f766e'],
      dataLabels: { enabled: true, formatter: function(val, opts){ return opts.w.config.series[opts.seriesIndex]; } },
      legend: { position: 'bottom', fontFamily: 'Tajawal, sans-serif' },
      plotOptions: { pie: { donut: { size: '58%' } } }
    }).render();
  } catch (e) { console.error('case chart', e); }
  <?php endif; ?>
})();
</script>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
