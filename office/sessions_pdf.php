<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('sessions', 'view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int) $_SESSION['office_id'];

$office   = $conn->query("SELECT * FROM offices WHERE id=$oid")->fetch_assoc();
$settings = $conn->query("SELECT * FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?: [];

$navy = '#0c1b36';
$S_LBL = ['scheduled' => 'مجدولة', 'held' => 'منعقدة', 'postponed' => 'مؤجّلة', 'cancelled' => 'ملغاة'];
$dt = function ($v) {
    if (empty($v)) return '—';
    return trim(strip_tags(dDate($v, true)));
};

$caseId = (int) ($_GET['case'] ?? 0);
$from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';

if ($caseId) {
    if (!canSeeCase($conn, $caseId)) { header('Location: cases.php'); exit; } // موظّف مقيَّد لا يرى محضر قضية غير مُسندة إليه
    $case = $conn->query("SELECT c.*, cl.full_name client_name FROM cases c
        LEFT JOIN clients cl ON c.client_id = cl.id
        WHERE c.id=$caseId AND c.office_id=$oid")->fetch_assoc();
    if (!$case) { header('Location: cases.php'); exit; }
    $rows = $conn->query("SELECT * FROM sessions WHERE case_id=$caseId AND office_id=$oid ORDER BY session_date ASC");
    $docTitle = 'محضر جلسات القضية ' . $case['case_number'];
} else {
    $w = "s.office_id=$oid" . caseScope('c'); // موظّف مقيَّد يرى جلسات قضاياه المُسندة فقط
    if ($from) $w .= " AND DATE(s.session_date) >= '" . $conn->real_escape_string($from) . "'";
    if ($to)   $w .= " AND DATE(s.session_date) <= '" . $conn->real_escape_string($to) . "'";
    $rows = $conn->query("SELECT s.*, c.case_number, c.title case_title
        FROM sessions s JOIN cases c ON s.case_id = c.id
        WHERE $w ORDER BY s.session_date ASC LIMIT 300");
    $docTitle = 'سجل الجلسات' . ($from || $to ? ' — ' . ($from ?: '…') . ' إلى ' . ($to ?: '…') : '');
}

ob_start();
?>
<?php
$K  = 'color:#6b7280;font-size:8pt;font-weight:bold';                  // عنوان الحقل
$RC = 'border-top:0.4pt solid #e9ecf1;font-size:9.5pt;line-height:1.55';
$HC = 'background-color:' . $navy . ';color:#ffffff';
$IC = 'font-size:9pt;border:0.4pt solid #e1e5ec';
$IL = 'background-color:#f5f6f8;color:#5b6472;font-size:8.5pt;border:0.4pt solid #e1e5ec';
$val = fn($v) => ($v !== '' && $v !== null) ? nl2br(e($v)) : '<span style="color:#9aa4b2">—</span>';
?>
<div style="text-align:center;font-size:16pt;font-weight:bold;color:<?= $navy ?>"><?= e($caseId ? 'محضر جلسات القضية' : 'سجل الجلسات') ?></div>
<div style="text-align:center;color:#6b7280;font-size:8.5pt;padding-bottom:6px"><?= e($docTitle) ?> &nbsp;•&nbsp; تاريخ الإصدار: <?= e(trim(strip_tags(dDate(date('Y-m-d'))))) ?></div>
<div style="border-bottom:1.2pt solid <?= $navy ?>">&nbsp;</div>
<br>

<?php if ($caseId): ?>
<table width="100%" cellpadding="6" cellspacing="0" style="border:0.4pt solid #dde3ec">
  <tr>
    <td width="16%" style="<?= $IL ?>">رقم القضية</td>
    <td width="34%" style="<?= $IC ?>"><?= e($case['case_number']) ?></td>
    <td width="16%" style="<?= $IL ?>">المحكمة</td>
    <td width="34%" style="<?= $IC ?>"><?= e($case['court_name'] ?: '—') ?></td>
  </tr>
  <tr>
    <td style="<?= $IL ?>">الموضوع</td>
    <td style="<?= $IC ?>"><?= e($case['title']) ?></td>
    <td style="<?= $IL ?>">العميل</td>
    <td style="<?= $IC ?>"><?= e($case['client_name'] ?: '—') ?></td>
  </tr>
</table>
<br><br>
<?php endif; ?>

<?php
$i = 0;
if ($rows) while ($s = $rows->fetch_assoc()): $i++;
  $stat = $S_LBL[$s['status']] ?? $s['status'];
?>
<table nobr="true" width="100%" cellpadding="7" cellspacing="0" style="border:0.6pt solid #c4cddb">
  <tr>
    <td width="24%" style="<?= $HC ?>;font-weight:bold;font-size:10.5pt">الجلسة <?= $i ?></td>
    <td width="52%" style="<?= $HC ?>;font-size:9pt;text-align:center"><?= e($dt($s['session_date'])) ?></td>
    <td width="24%" style="<?= $HC ?>;font-size:9pt;text-align:left;font-weight:bold"><?= e($stat) ?></td>
  </tr>
  <?php if (!$caseId): ?>
  <tr><td colspan="3" style="<?= $RC ?>"><span style="<?= $K ?>">القضية:</span> <b><?= e($s['case_number']) ?></b> — <?= e($s['case_title'] ?? '') ?></td></tr>
  <?php endif; ?>
  <tr><td colspan="3" style="<?= $RC ?>"><span style="<?= $K ?>">ما تم في الجلسة</span><br><?= $val($s['description'] ?? '') ?></td></tr>
  <tr><td colspan="3" style="<?= $RC ?>"><span style="<?= $K ?>">النتيجة / القرار</span><br><?= $val($s['result'] ?? '') ?></td></tr>
  <?php if (!empty($s['notes'])): ?>
  <tr><td colspan="3" style="<?= $RC ?>"><span style="<?= $K ?>">ملاحظات</span><br><?= nl2br(e($s['notes'])) ?></td></tr>
  <?php endif; ?>
</table>
<div style="font-size:6pt">&nbsp;</div>
<?php endwhile; ?>

<?php if ($i === 0): ?>
<div style="text-align:center;color:#94a3b8;padding:24px;border:0.5pt solid #e1e5ec;font-size:9.5pt">لا توجد جلسات في هذه الفترة</div>
<?php endif; ?>

<br>
<div style="color:#6b7280;font-size:8.5pt;border-top:0.5pt solid #e1e5ec;padding-top:5px">إجمالي عدد الجلسات: <?= $i ?></div>
<?php
$html = ob_get_clean();

$fb = '<div style="font-size:9pt;color:#5b6472;border-bottom:1.5pt solid ' . $navy . ';padding-bottom:4px">'
    . '<b style="font-size:13pt;color:' . $navy . '">' . e($office['name']) . '</b></div>';

require_once '../includes/pdf.php';
$fn = ($caseId ? 'محضر-جلسات-' . preg_replace('/[^\p{Arabic}\w\-]+/u', '_', $case['case_number']) : 'سجل-الجلسات') . '.pdf';
mehkam_make_pdf($settings, $html, $fn, [
    'title' => $docTitle,
    'dest'  => isset($_GET['view']) ? 'I' : 'D',
    'size'  => 9.5,
    'header_fallback_html' => $fb,
]);
