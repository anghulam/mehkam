<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('reports','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];

if (!hasFeature($conn, $oid, 'has_reports')) {
    header("Location: profile.php?tab=upgrade&feature=reports"); exit;
}

$office   = $conn->query("SELECT * FROM offices WHERE id=$oid")->fetch_assoc();
$settings = $conn->query("SELECT * FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?: [];

$navy = '#0c1b36';
$_restricted = isRestricted();
$_uid        = (int)($_SESSION['user_id'] ?? 0);
$_isTaskMgr  = can('tasks','approve');

$_canCases    = can('cases','view');
$_canSessions = can('sessions','view');
$_canTasks    = can('tasks','view');
$_canAppts    = can('tasks','view');
$_canClients  = can('clients','view');
$_canFinance  = can('finance','view') && hasFeature($conn, $oid, 'has_finance');

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
$fromE = $conn->real_escape_string($from);
$toE   = $conn->real_escape_string($to);

// تصدير مخصّص بالكامل: المستخدم يختار أي الأقسام تدخل بالتقرير + كلمة بحث اختيارية
$_tabPerm   = ['cases'=>$_canCases,'sessions'=>$_canSessions,'tasks'=>$_canTasks,'appointments'=>$_canAppts,'clients'=>$_canClients,'finance'=>$_canFinance];
$_tabTitles = ['cases'=>'القضايا','sessions'=>'الجلسات','tasks'=>'المهام','appointments'=>'المواعيد','clients'=>'العملاء','finance'=>'المالية'];

$sections = array_values(array_intersect(
    (array)($_GET['sections'] ?? []),
    array_keys(array_filter($_tabPerm))
));
if (!$sections) {
    // توافق مع الرابط القديم tab=واحد، أو افتراضياً كل الأقسام المسموحة لهذا الموظّف
    if (!empty($_tabPerm[$_GET['tab'] ?? ''])) $sections = [$_GET['tab']];
    else $sections = array_keys(array_filter($_tabPerm));
}
if (!$sections) { header('Location: dashboard.php?msg=denied'); exit; }

$_rpQ  = trim($_GET['q'] ?? '');
$_rpQE = $conn->real_escape_string($_rpQ);

$reportTitle = count($sections) === 1
    ? $_tabTitles[$sections[0]]
    : 'تقرير مخصص — ' . implode('، ', array_map(fn($s) => $_tabTitles[$s], $sections));

$S_SESS = ['scheduled'=>'مجدولة','held'=>'منعقدة','postponed'=>'مؤجّلة','cancelled'=>'ملغاة'];
$S_CASE = ['active'=>'نشطة','closed'=>'مغلقة','suspended'=>'موقوفة','won'=>'مكسوبة','lost'=>'خاسرة','settled'=>'متسوية'];
$S_TASK = ['pending'=>'معلقة','in_progress'=>'جارية','completed'=>'مكتملة','cancelled'=>'ملغاة'];
$S_APPT = ['scheduled'=>'مجدول','completed'=>'تم','cancelled'=>'ملغي'];
$_apTypes = ['meeting'=>'اجتماع','court'=>'محكمة','consultation'=>'استشارة','other'=>'أخرى'];
$P_LBL  = ['low'=>'منخفضة','medium'=>'متوسطة','high'=>'عالية','urgent'=>'عاجلة'];
$dt = function ($v) { if (empty($v)) return '—'; return trim(strip_tags(dDate($v, true))); };
$dd = function ($v) { if (empty($v)) return '—'; return trim(strip_tags(dDate($v))); };

$ROW_CAP = 150;

/* ═══ القضايا ═══ */
$cases_total = 0; $cases_by_status = []; $cases_rows = [];
if (in_array('cases', $sections, true)) {
    $cw = "c.office_id=$oid AND DATE(c.created_at) BETWEEN '$fromE' AND '$toE'" . caseScope('c');
    if ($_rpQ !== '') $cw .= " AND (c.case_number LIKE '%$_rpQE%' OR c.title LIKE '%$_rpQE%')";
    $cases_total = (int)dbVal($conn, "SELECT COUNT(*) FROM cases c WHERE $cw");
    $r = $conn->query("SELECT status, COUNT(*) c FROM cases c WHERE $cw GROUP BY status");
    if ($r) while ($x = $r->fetch_assoc()) $cases_by_status[$x['status']] = (int)$x['c'];
    $rs = $conn->query("SELECT c.*, cl.full_name client_name FROM cases c LEFT JOIN clients cl ON c.client_id=cl.id WHERE $cw ORDER BY c.created_at DESC LIMIT $ROW_CAP");
    if ($rs) while ($x = $rs->fetch_assoc()) $cases_rows[] = $x;
}

/* ═══ الجلسات ═══ */
$sessions_total = 0; $sessions_by_status = []; $sessions_rows = [];
if (in_array('sessions', $sections, true)) {
    $sw = "s.office_id=$oid AND DATE(s.session_date) BETWEEN '$fromE' AND '$toE'" . caseScope('c');
    if ($_rpQ !== '') $sw .= " AND (c.case_number LIKE '%$_rpQE%' OR c.title LIKE '%$_rpQE%' OR s.description LIKE '%$_rpQE%')";
    $sessions_total = (int)dbVal($conn, "SELECT COUNT(*) FROM sessions s JOIN cases c ON s.case_id=c.id WHERE $sw");
    $r = $conn->query("SELECT s.status, COUNT(*) c FROM sessions s JOIN cases c ON s.case_id=c.id WHERE $sw GROUP BY s.status");
    if ($r) while ($x = $r->fetch_assoc()) $sessions_by_status[$x['status']] = (int)$x['c'];
    $rs = $conn->query("SELECT s.*, c.case_number, c.title case_title FROM sessions s JOIN cases c ON s.case_id=c.id WHERE $sw ORDER BY s.session_date DESC LIMIT $ROW_CAP");
    if ($rs) while ($x = $rs->fetch_assoc()) $sessions_rows[] = $x;
}

/* ═══ المهام ═══ */
$tasks_total = 0; $tasks_by_status = []; $tasks_rows = [];
if (in_array('tasks', $sections, true)) {
    $tw = "t.office_id=$oid AND DATE(t.due_date) BETWEEN '$fromE' AND '$toE'";
    if (!$_isTaskMgr) $tw .= " AND t.assigned_to_id=$_uid";
    if ($_rpQ !== '') $tw .= " AND (t.title LIKE '%$_rpQE%' OR t.assigned_to LIKE '%$_rpQE%')";
    $tasks_total = (int)dbVal($conn, "SELECT COUNT(*) FROM tasks t WHERE $tw");
    $r = $conn->query("SELECT status, COUNT(*) c FROM tasks t WHERE $tw GROUP BY status");
    if ($r) while ($x = $r->fetch_assoc()) $tasks_by_status[$x['status']] = (int)$x['c'];
    $rs = $conn->query("SELECT t.*, c.case_number FROM tasks t LEFT JOIN cases c ON t.case_id=c.id WHERE $tw ORDER BY t.due_date DESC LIMIT $ROW_CAP");
    if ($rs) while ($x = $rs->fetch_assoc()) $tasks_rows[] = $x;
}

/* ═══ المواعيد ═══ */
$appts_total = 0; $appts_by_status = []; $appts_rows = [];
if (in_array('appointments', $sections, true)) {
    $aw = "a.office_id=$oid AND DATE(a.appointment_date) BETWEEN '$fromE' AND '$toE'";
    if (!$_isTaskMgr) $aw .= " AND a.assigned_to_id=$_uid";
    if ($_rpQ !== '') $aw .= " AND (a.title LIKE '%$_rpQE%' OR a.location LIKE '%$_rpQE%')";
    $appts_total = (int)dbVal($conn, "SELECT COUNT(*) FROM appointments a WHERE $aw");
    $r = $conn->query("SELECT status, COUNT(*) c FROM appointments a WHERE $aw GROUP BY status");
    if ($r) while ($x = $r->fetch_assoc()) $appts_by_status[$x['status']] = (int)$x['c'];
    $rs = $conn->query("SELECT a.*, cl.full_name client_name, au.full_name assignee_name
        FROM appointments a LEFT JOIN clients cl ON a.client_id=cl.id LEFT JOIN users au ON a.assigned_to_id=au.id
        WHERE $aw ORDER BY a.appointment_date DESC LIMIT $ROW_CAP");
    if ($rs) while ($x = $rs->fetch_assoc()) $appts_rows[] = $x;
}

/* ═══ العملاء ═══ */
$clients_total = 0; $clients_rows = [];
if (in_array('clients', $sections, true)) {
    $clw = "cl.office_id=$oid AND DATE(cl.created_at) BETWEEN '$fromE' AND '$toE'";
    if ($_restricted) $clw .= " AND cl.id IN (SELECT client_id FROM cases c WHERE client_id IS NOT NULL" . caseScope('c') . ")";
    if ($_rpQ !== '') $clw .= " AND (cl.full_name LIKE '%$_rpQE%' OR cl.phone LIKE '%$_rpQE%')";
    $clients_total = (int)dbVal($conn, "SELECT COUNT(*) FROM clients cl WHERE $clw");
    $rs = $conn->query("SELECT * FROM clients cl WHERE $clw ORDER BY cl.created_at DESC LIMIT $ROW_CAP");
    if ($rs) while ($x = $rs->fetch_assoc()) $clients_rows[] = $x;
}

/* ═══ المالية ═══ */
$fin_income = $fin_expense = 0.0; $invoices_rows = [];
if (in_array('finance', $sections, true)) {
    $fwBase = "inv.office_id=$oid AND inv.status='paid' AND DATE(COALESCE(inv.paid_date,inv.created_at)) BETWEEN '$fromE' AND '$toE'" . finScope('inv');
    $fw = $fwBase . ($_rpQ !== '' ? " AND inv.invoice_number LIKE '%$_rpQE%'" : '');
    $fin_income  = (float)dbVal($conn, "SELECT IFNULL(SUM(total),0) FROM invoices inv WHERE $fw AND (direction='income' OR direction IS NULL)");
    $fin_expense = (float)dbVal($conn, "SELECT IFNULL(SUM(total),0) FROM invoices inv WHERE $fw AND direction='expense'");
    $fwRows = $fwBase . ($_rpQ !== '' ? " AND (inv.invoice_number LIKE '%$_rpQE%' OR cl.full_name LIKE '%$_rpQE%' OR ca.case_number LIKE '%$_rpQE%')" : '');
    $rs = $conn->query("SELECT inv.*, cl.full_name client_name, ca.case_number FROM invoices inv LEFT JOIN clients cl ON inv.client_id=cl.id LEFT JOIN cases ca ON inv.case_id=ca.id WHERE $fwRows ORDER BY COALESCE(inv.paid_date, inv.created_at) DESC LIMIT $ROW_CAP");
    if ($rs) while ($x = $rs->fetch_assoc()) $invoices_rows[] = $x;
}
$fin_net = $fin_income - $fin_expense;

ob_start();
?>
<?php
$K  = 'color:#6b7280;font-size:8pt;font-weight:bold';
$HC = 'background-color:' . $navy . ';color:#ffffff;font-size:9pt';
$IC = 'font-size:9pt;border:0.4pt solid #e1e5ec';
$IL = 'background-color:#f5f6f8;color:#5b6472;font-size:8.5pt;border:0.4pt solid #e1e5ec';
$SEP = '<div style="font-size:6pt">&nbsp;</div>';
function rp_section_open($title, $count, $navy) {
    echo '<table width="100%" cellpadding="6" cellspacing="0" style="border:0.6pt solid #c4cddb;margin-top:4px">
      <tr><td style="background-color:'.$navy.';color:#fff;font-weight:bold;font-size:11pt">'.$title.' ('.$count.')</td></tr>
    </table><br>';
}
?>
<div style="text-align:center;font-size:17pt;font-weight:bold;color:<?= $navy ?>"><?= e($reportTitle) ?></div>
<div style="text-align:center;color:#6b7280;font-size:8.5pt;padding-bottom:6px">
  الفترة: <?= e($dd($from)) ?> — <?= e($dd($to)) ?> &nbsp;•&nbsp; تاريخ الإصدار: <?= e($dd(date('Y-m-d'))) ?>
  <?= $_rpQ !== '' ? ' &nbsp;•&nbsp; بحث: «' . e($_rpQ) . '»' : '' ?>
  <?= $_restricted ? ' &nbsp;•&nbsp; بيانات شخصية (القضايا/الجلسات/المهام المُسندة إليك)' : '' ?>
</div>
<div style="border-bottom:1.2pt solid <?= $navy ?>">&nbsp;</div>
<br>

<?php if (in_array('cases', $sections, true)): rp_section_open('القضايا', $cases_total, $navy); ?>
<table width="100%" cellpadding="6" cellspacing="0" style="border:0.4pt solid #dde3ec">
  <tr>
    <td style="<?= $HC ?>">رقم القضية</td><td style="<?= $HC ?>">العنوان</td><td style="<?= $HC ?>">العميل</td>
    <td style="<?= $HC ?>">الحالة</td><td style="<?= $HC ?>">الأولوية</td><td style="<?= $HC ?>">تاريخ الإنشاء</td>
  </tr>
  <?php if ($cases_rows): foreach ($cases_rows as $c): ?>
  <tr>
    <td style="<?= $IC ?>"><b><?= e($c['case_number']) ?></b></td>
    <td style="<?= $IC ?>"><?= e(mb_substr($c['title'],0,45)) ?></td>
    <td style="<?= $IC ?>"><?= e($c['client_name'] ?: '—') ?></td>
    <td style="<?= $IC ?>"><?= e($S_CASE[$c['status']] ?? $c['status']) ?></td>
    <td style="<?= $IC ?>"><?= e($P_LBL[$c['priority']] ?? $c['priority']) ?></td>
    <td style="<?= $IC ?>"><?= e($dd($c['created_at'])) ?></td>
  </tr>
  <?php endforeach; else: ?>
  <tr><td colspan="6" style="<?= $IC ?>;text-align:center;color:#9aa4b2">لا توجد قضايا في هذه الفترة</td></tr>
  <?php endif; ?>
</table>
<?= $SEP ?><br>
<?php endif; ?>

<?php if (in_array('sessions', $sections, true)): rp_section_open('الجلسات', $sessions_total, $navy); ?>
<table width="100%" cellpadding="6" cellspacing="0" style="border:0.4pt solid #dde3ec">
  <tr><td style="<?= $HC ?>">القضية</td><td style="<?= $HC ?>">موعد الجلسة</td><td style="<?= $HC ?>">الحالة</td></tr>
  <?php if ($sessions_rows): foreach ($sessions_rows as $s): ?>
  <tr>
    <td style="<?= $IC ?>"><b><?= e($s['case_number']) ?></b> — <?= e(mb_substr($s['case_title'],0,30)) ?></td>
    <td style="<?= $IC ?>"><?= e($dt($s['session_date'])) ?></td>
    <td style="<?= $IC ?>"><?= e($S_SESS[$s['status']] ?? $s['status']) ?></td>
  </tr>
  <?php endforeach; else: ?>
  <tr><td colspan="3" style="<?= $IC ?>;text-align:center;color:#9aa4b2">لا توجد جلسات في هذه الفترة</td></tr>
  <?php endif; ?>
</table>
<?= $SEP ?><br>
<?php endif; ?>

<?php if (in_array('tasks', $sections, true)): rp_section_open('المهام', $tasks_total, $navy); ?>
<table width="100%" cellpadding="6" cellspacing="0" style="border:0.4pt solid #dde3ec">
  <tr><td style="<?= $HC ?>">المهمة</td><td style="<?= $HC ?>">القضية</td><td style="<?= $HC ?>">المكلَّف</td><td style="<?= $HC ?>">الحالة</td><td style="<?= $HC ?>">الموعد</td></tr>
  <?php if ($tasks_rows): foreach ($tasks_rows as $t): ?>
  <tr>
    <td style="<?= $IC ?>"><?= e(mb_substr($t['title'],0,40)) ?></td>
    <td style="<?= $IC ?>"><?= e($t['case_number'] ?: '—') ?></td>
    <td style="<?= $IC ?>"><?= e($t['assigned_to'] ?: '—') ?></td>
    <td style="<?= $IC ?>"><?= e($S_TASK[$t['status']] ?? $t['status']) ?></td>
    <td style="<?= $IC ?>"><?= e($dt($t['due_date'])) ?></td>
  </tr>
  <?php endforeach; else: ?>
  <tr><td colspan="5" style="<?= $IC ?>;text-align:center;color:#9aa4b2">لا توجد مهام في هذه الفترة</td></tr>
  <?php endif; ?>
</table>
<?= $SEP ?><br>
<?php endif; ?>

<?php if (in_array('appointments', $sections, true)): rp_section_open('المواعيد', $appts_total, $navy); ?>
<table width="100%" cellpadding="6" cellspacing="0" style="border:0.4pt solid #dde3ec">
  <tr><td style="<?= $HC ?>">الموعد</td><td style="<?= $HC ?>">النوع</td><td style="<?= $HC ?>">العميل</td><td style="<?= $HC ?>">المكلَّف</td><td style="<?= $HC ?>">الحالة</td><td style="<?= $HC ?>">التاريخ والوقت</td></tr>
  <?php if ($appts_rows): foreach ($appts_rows as $a): ?>
  <tr>
    <td style="<?= $IC ?>"><?= e(mb_substr($a['title'],0,40)) ?></td>
    <td style="<?= $IC ?>"><?= e($_apTypes[$a['type']] ?? $a['type']) ?></td>
    <td style="<?= $IC ?>"><?= e($a['client_name'] ?: '—') ?></td>
    <td style="<?= $IC ?>"><?= e($a['assignee_name'] ?: '—') ?></td>
    <td style="<?= $IC ?>"><?= e($S_APPT[$a['status']] ?? $a['status']) ?></td>
    <td style="<?= $IC ?>"><?= e($dt($a['appointment_date'])) ?></td>
  </tr>
  <?php endforeach; else: ?>
  <tr><td colspan="6" style="<?= $IC ?>;text-align:center;color:#9aa4b2">لا توجد مواعيد في هذه الفترة</td></tr>
  <?php endif; ?>
</table>
<?= $SEP ?><br>
<?php endif; ?>

<?php if (in_array('clients', $sections, true)): rp_section_open('العملاء الجدد', $clients_total, $navy); ?>
<table width="100%" cellpadding="6" cellspacing="0" style="border:0.4pt solid #dde3ec">
  <tr><td style="<?= $HC ?>">الاسم</td><td style="<?= $HC ?>">النوع</td><td style="<?= $HC ?>">الجوال</td><td style="<?= $HC ?>">تاريخ الإضافة</td></tr>
  <?php if ($clients_rows): foreach ($clients_rows as $cl): ?>
  <tr>
    <td style="<?= $IC ?>"><b><?= e($cl['full_name']) ?></b></td>
    <td style="<?= $IC ?>"><?= $cl['client_type']==='company' ? 'شركة' : 'فرد' ?></td>
    <td style="<?= $IC ?>"><?= e($cl['phone'] ?: '—') ?></td>
    <td style="<?= $IC ?>"><?= e($dd($cl['created_at'])) ?></td>
  </tr>
  <?php endforeach; else: ?>
  <tr><td colspan="4" style="<?= $IC ?>;text-align:center;color:#9aa4b2">لا يوجد عملاء جدد في هذه الفترة</td></tr>
  <?php endif; ?>
</table>
<?= $SEP ?><br>
<?php endif; ?>

<?php if (in_array('finance', $sections, true)): rp_section_open('الحركة المالية', count($invoices_rows), $navy); ?>
<table width="100%" cellpadding="7" cellspacing="0" style="border:0.4pt solid #dde3ec;margin-bottom:8px">
  <tr>
    <td width="34%" style="<?= $IL ?>">إجمالي الإيرادات</td><td width="16%" style="<?= $IC ?>;color:#16a34a"><b><?= number_format($fin_income,2) ?></b></td>
    <td width="34%" style="<?= $IL ?>">إجمالي المصروفات</td><td width="16%" style="<?= $IC ?>;color:#dc2626"><b><?= number_format($fin_expense,2) ?></b></td>
  </tr>
</table>
<table width="100%" cellpadding="6" cellspacing="0" style="border:0.4pt solid #dde3ec">
  <tr><td style="<?= $HC ?>">الرقم</td><td style="<?= $HC ?>">العميل</td><td style="<?= $HC ?>">القضية</td><td style="<?= $HC ?>">النوع</td><td style="<?= $HC ?>">المبلغ</td><td style="<?= $HC ?>">تاريخ السداد</td></tr>
  <?php if ($invoices_rows): foreach ($invoices_rows as $inv): ?>
  <tr>
    <td style="<?= $IC ?>"><b><?= e($inv['invoice_number'] ?: ('#'.$inv['id'])) ?></b></td>
    <td style="<?= $IC ?>"><?= e($inv['client_name'] ?: '—') ?></td>
    <td style="<?= $IC ?>"><?= e($inv['case_number'] ?: '—') ?></td>
    <td style="<?= $IC ?>"><?= ($inv['direction'] ?? 'income')==='expense' ? 'مصروف' : 'إيراد' ?></td>
    <td style="<?= $IC ?>"><?= number_format((float)$inv['total'],2) ?> ر.س</td>
    <td style="<?= $IC ?>"><?= e($dd($inv['paid_date'] ?: $inv['created_at'])) ?></td>
  </tr>
  <?php endforeach; else: ?>
  <tr><td colspan="6" style="<?= $IC ?>;text-align:center;color:#9aa4b2">لا توجد حركة مالية في هذه الفترة</td></tr>
  <?php endif; ?>
</table>
<?php endif; ?>

<br>
<div style="color:#6b7280;font-size:8pt;border-top:0.5pt solid #e1e5ec;padding-top:5px">
  أُصدر هذا التقرير آلياً من منصة مِحكام — <?= e($office['name'] ?? '') ?>
</div>
<?php
$html = ob_get_clean();

$fb = '<div style="font-size:9pt;color:#5b6472;border-bottom:1.5pt solid ' . $navy . ';padding-bottom:4px">'
    . '<b style="font-size:13pt;color:' . $navy . '">' . e($office['name'] ?? '') . '</b></div>';

require_once '../includes/pdf.php';
$fn = 'التقرير-الشامل-' . $from . '-إلى-' . $to . '.pdf';
mehkam_make_pdf($settings, $html, $fn, [
    'title' => 'التقرير الشامل',
    'dest'  => isset($_GET['view']) ? 'I' : 'D',
    'size'  => 9.5,
    'header_fallback_html' => $fb,
]);
