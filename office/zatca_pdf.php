<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/zatca_helper.php';
requireOffice();
if (!can('finance', 'view') && !can('invoices', 'view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int) $_SESSION['office_id'];

try { $conn->query("ALTER TABLE invoices ADD COLUMN direction ENUM('income','expense') DEFAULT 'income'"); } catch (\Throwable $e) {}

$period_type = $_GET['period_type'] ?? 'month';
$year  = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
$quarter = (int) ($_GET['quarter'] ?? ceil(date('n') / 3));

$monthsAr = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
if ($period_type === 'month') {
    $month = max(1, min(12, $month));
    $date_from = sprintf('%04d-%02d-01', $year, $month);
    $date_to   = date('Y-m-t', strtotime($date_from));
    $period_label = $monthsAr[$month - 1] . ' ' . $year;
} elseif ($period_type === 'quarter') {
    $quarter = max(1, min(4, $quarter));
    $qs = ($quarter - 1) * 3 + 1;
    $date_from = sprintf('%04d-%02d-01', $year, $qs);
    $date_to   = date('Y-m-t', mktime(0, 0, 0, $qs + 2, 1, $year));
    $period_label = "الربع {$quarter} — {$year}";
} else {
    $date_from = "{$year}-01-01"; $date_to = "{$year}-12-31";
    $period_label = "السنة المالية {$year}";
}
$ff = $conn->real_escape_string($date_from);
$ft = $conn->real_escape_string($date_to);

$office   = $conn->query("SELECT * FROM offices WHERE id=$oid")->fetch_assoc();
$settings = $conn->query("SELECT * FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?: [];
$vatNum = trim($settings['tax_number'] ?? '');
$crNum  = trim($settings['cr_number'] ?? '');

$navy = '#0c1b36';
$gold = '#b98a33';
/* رقم بصيغة LTR ثابتة لتفادي انعكاس الأرقام في RTL */
$n = fn($v) => '<span dir="ltr">' . number_format((float) $v, 2) . '</span>';

$base = "FROM invoices WHERE office_id=$oid AND status != 'cancelled' AND issue_date BETWEEN '$ff' AND '$ft'";
$sales = $conn->query("SELECT invoice_number,issue_date,title,subtotal,tax_amount,total $base AND (direction='income' OR direction IS NULL) ORDER BY issue_date ASC");
$purch = $conn->query("SELECT invoice_number,issue_date,title,subtotal,tax_amount,total $base AND direction='expense' ORDER BY issue_date ASC");
$st = $conn->query("SELECT COUNT(*) cnt, IFNULL(SUM(subtotal),0) sub, IFNULL(SUM(tax_amount),0) tax, IFNULL(SUM(total),0) tot $base AND (direction='income' OR direction IS NULL)")->fetch_assoc();
$pt = $conn->query("SELECT COUNT(*) cnt, IFNULL(SUM(subtotal),0) sub, IFNULL(SUM(tax_amount),0) tax, IFNULL(SUM(total),0) tot $base AND direction='expense'")->fetch_assoc();
$outVat = (float) $st['tax']; $inVat = (float) $pt['tax']; $netVat = $outVat - $inVat;

/* أنماط مضمّنة — TCPDF لا يدعم محدّدات CSS المركّبة (.a .b) بثبات، ولا padding على td (نستخدم cellpadding) */
$BAND = 'background-color:' . $navy . ';color:#ffffff;font-weight:bold;font-size:10pt;padding:6px 10px;letter-spacing:.3px';
$IL   = 'background-color:#f6f7f9;color:#5b6472;font-size:8.5pt;border:0.4pt solid #e1e5ec';
$IV   = 'font-size:9pt;border:0.4pt solid #e1e5ec';
$RW   = 'border:0.4pt solid #e4e8f0;font-size:9.5pt';                          // عمود البند
$RA   = 'border:0.4pt solid #e4e8f0;font-size:9.5pt;font-weight:bold;text-align:left;background-color:#fafbfd'; // عمود المبلغ
$VG   = 'background-color:#eef1f7;color:' . $navy . ';font-weight:bold;font-size:9.5pt;border:0.4pt solid #e4e8f0';
$NW   = 'background-color:' . $navy . ';color:#ffffff;font-size:10.5pt;font-weight:bold;border:0.4pt solid ' . $navy;
$NA   = 'background-color:' . $navy . ';color:#ffffff;font-size:10.5pt;font-weight:bold;text-align:left;border:0.4pt solid ' . $navy;
$TH   = 'background-color:#3d4d66;color:#ffffff;font-size:8.5pt;text-align:center';
$TC   = 'border:0.4pt solid #e1e5ec;font-size:8.5pt;text-align:center';
$TCm  = 'border:0.4pt solid #e1e5ec;font-size:8.5pt;text-align:left';
$TF   = 'border:0.4pt solid #e1e5ec;font-size:8.5pt;background-color:#f4f6fa;font-weight:bold;text-align:center';
$TFm  = 'border:0.4pt solid #e1e5ec;font-size:8.5pt;background-color:#f4f6fa;font-weight:bold;text-align:left';
$amt  = fn($v, $unit = ' ر.س') => '<b><span dir="ltr">' . number_format((float) $v, 2) . '</span>' . $unit . '</b>';
$band = fn($txt) => '<table width="100%" cellpadding="7" cellspacing="0" style="background-color:' . $navy . '">'
    . '<tr><td style="color:#ffffff;font-weight:bold;font-size:10pt">' . $txt . '</td></tr></table>';

ob_start();
?>
<style>body { font-family: amiri; font-size: 9.5pt; color: #1f2937; line-height: 1.6; }</style>

<br>
<div style="text-align:center;font-size:16pt;font-weight:bold;color:<?= $navy ?>">إقرار ضريبة القيمة المضافة</div>
<div style="text-align:center;color:#6b7280;font-size:9pt"><?= e($period_label) ?> &nbsp;•&nbsp; <span dir="ltr"><?= e($date_from) ?> — <?= e($date_to) ?></span></div>
<br>

<table width="100%" cellpadding="5" cellspacing="0" style="border:0.4pt solid #dde3ec">
  <tr><td width="16%" style="<?= $IL ?>">المنشأة</td><td width="34%" style="<?= $IV ?>"><?= e($office['name']) ?></td>
      <td width="16%" style="<?= $IL ?>">الرقم الضريبي</td><td width="34%" style="<?= $IV ?>" dir="ltr"><?= e($vatNum ?: '—') ?></td></tr>
  <tr><td width="16%" style="<?= $IL ?>">السجل التجاري</td><td width="34%" style="<?= $IV ?>" dir="ltr"><?= e($crNum ?: '—') ?></td>
      <td width="16%" style="<?= $IL ?>">تاريخ الإصدار</td><td width="34%" style="<?= $IV ?>" dir="ltr"><?= e(date('Y-m-d')) ?></td></tr>
</table>
<br><br>

<?= $band('نموذج الإقرار الضريبي — ' . e($period_label)) ?>
<table width="100%" cellpadding="7" cellspacing="0" style="border:0.8pt solid <?= $navy ?>">
  <tr><td colspan="2" style="<?= $VG ?>">أولاً: المخرجات (المبيعات والإيرادات)</td></tr>
  <tr><td width="68%" style="<?= $RW ?>">١. إجمالي المبيعات الخاضعة للضريبة (15%)</td><td width="32%" style="<?= $RA ?>"><?= $n($st['sub']) ?> ر.س</td></tr>
  <tr><td width="68%" style="<?= $RW ?>">٢. ضريبة القيمة المضافة على المبيعات (ضريبة المخرجات)</td><td width="32%" style="<?= $RA ?>"><?= $n($outVat) ?> ر.س</td></tr>
  <tr><td width="68%" style="<?= $RW ?>">٣. إجمالي المبيعات شامل الضريبة</td><td width="32%" style="<?= $RA ?>"><?= $n($st['tot']) ?> ر.س</td></tr>

  <tr><td colspan="2" style="<?= $VG ?>">ثانياً: المدخلات (المشتريات والمصروفات)</td></tr>
  <tr><td width="68%" style="<?= $RW ?>">٤. إجمالي المشتريات الخاضعة للضريبة (15%)</td><td width="32%" style="<?= $RA ?>"><?= $n($pt['sub']) ?> ر.س</td></tr>
  <tr><td width="68%" style="<?= $RW ?>">٥. ضريبة القيمة المضافة على المشتريات (المدخلات القابلة للخصم)</td><td width="32%" style="<?= $RA ?>"><?= $n($inVat) ?> ر.س</td></tr>

  <tr><td colspan="2" style="<?= $VG ?>">ثالثاً: صافي الضريبة المستحقة</td></tr>
  <tr><td width="68%" style="<?= $RW ?>">٦. ضريبة المخرجات (بند ٢)</td><td width="32%" style="<?= $RA ?>"><?= $n($outVat) ?> ر.س</td></tr>
  <tr><td width="68%" style="<?= $RW ?>">٧. يُخصم: ضريبة المدخلات القابلة للخصم (بند ٥)</td><td width="32%" style="<?= $RA ?>" dir="ltr">&#8722;&nbsp;<?= number_format($inVat, 2) ?>&nbsp;ر.س</td></tr>
  <tr><td width="68%" style="<?= $NW ?>"><?= $netVat >= 0 ? '٨. صافي الضريبة المستحقة الدفع للهيئة' : '٨. رصيد ضريبي دائن — قابل للاسترداد أو الترحيل' ?></td>
      <td width="32%" style="<?= $NA ?>"><?= $n(abs($netVat)) ?> ر.س</td></tr>
</table>
<br><br>

<?= $band('فواتير المبيعات والإيرادات &nbsp;<span dir="ltr">(' . (int) $st['cnt'] . ')</span>') ?>
<table width="100%" cellpadding="5" cellspacing="0">
  <tr>
    <th width="17%" style="<?= $TH ?>">رقم الفاتورة</th><th width="14%" style="<?= $TH ?>">التاريخ</th><th width="29%" style="<?= $TH ?>">البيان</th>
    <th width="14%" style="<?= $TH ?>">قبل الضريبة</th><th width="12%" style="<?= $TH ?>">الضريبة</th><th width="14%" style="<?= $TH ?>">الإجمالي</th>
  </tr>
  <?php $i = 0; if ($sales) while ($r = $sales->fetch_assoc()): $i++; ?>
    <tr>
      <td width="17%" style="<?= $TC ?>" dir="ltr"><?= e($r['invoice_number']) ?></td>
      <td width="14%" style="<?= $TC ?>" dir="ltr"><?= e($r['issue_date']) ?></td>
      <td width="29%" style="<?= $TCm ?>;text-align:right"><?= e(mb_substr($r['title'] ?? '', 0, 40)) ?></td>
      <td width="14%" style="<?= $TC ?>"><?= $n($r["subtotal"]) ?></td>
      <td width="12%" style="<?= $TC ?>"><?= $n($r["tax_amount"]) ?></td>
      <td width="14%" style="<?= $TC ?>"><?= $n($r["total"]) ?></td>
    </tr>
  <?php endwhile; if ($i === 0): ?><tr><td colspan="6" style="<?= $TC ?>">لا توجد فواتير في هذه الفترة</td></tr><?php endif; ?>
  <?php if ($i > 0): ?>
  <tr>
    <td colspan="3" width="60%" style="<?= $TFm ?>;text-align:right">الإجمالي &nbsp;<span dir="ltr">(<?= (int) $st['cnt'] ?>)</span> فاتورة</td>
    <td width="14%" style="<?= $TF ?>"><?= $n($st["sub"]) ?></td><td width="12%" style="<?= $TF ?>"><?= $n($outVat) ?></td><td width="14%" style="<?= $TF ?>"><?= $n($st["tot"]) ?></td>
  </tr>
  <?php endif; ?>
</table>
<br><br>

<?= $band('فواتير المشتريات والمصروفات &nbsp;<span dir="ltr">(' . (int) $pt['cnt'] . ')</span>') ?>
<table width="100%" cellpadding="5" cellspacing="0">
  <tr>
    <th width="17%" style="<?= $TH ?>">رقم الفاتورة</th><th width="14%" style="<?= $TH ?>">التاريخ</th><th width="29%" style="<?= $TH ?>">البيان</th>
    <th width="14%" style="<?= $TH ?>">قبل الضريبة</th><th width="12%" style="<?= $TH ?>">الضريبة</th><th width="14%" style="<?= $TH ?>">الإجمالي</th>
  </tr>
  <?php $j = 0; if ($purch) while ($r = $purch->fetch_assoc()): $j++; ?>
    <tr>
      <td width="17%" style="<?= $TC ?>" dir="ltr"><?= e($r['invoice_number']) ?></td>
      <td width="14%" style="<?= $TC ?>" dir="ltr"><?= e($r['issue_date']) ?></td>
      <td width="29%" style="<?= $TCm ?>;text-align:right"><?= e(mb_substr($r['title'] ?? '', 0, 40)) ?></td>
      <td width="14%" style="<?= $TC ?>"><?= $n($r["subtotal"]) ?></td>
      <td width="12%" style="<?= $TC ?>"><?= $n($r["tax_amount"]) ?></td>
      <td width="14%" style="<?= $TC ?>"><?= $n($r["total"]) ?></td>
    </tr>
  <?php endwhile; if ($j === 0): ?><tr><td colspan="6" style="<?= $TC ?>">لا توجد فواتير في هذه الفترة</td></tr><?php endif; ?>
  <?php if ($j > 0): ?>
  <tr>
    <td colspan="3" width="60%" style="<?= $TFm ?>;text-align:right">الإجمالي &nbsp;<span dir="ltr">(<?= (int) $pt['cnt'] ?>)</span> فاتورة</td>
    <td width="14%" style="<?= $TF ?>"><?= $n($pt["sub"]) ?></td><td width="12%" style="<?= $TF ?>"><?= $n($inVat) ?></td><td width="14%" style="<?= $TF ?>"><?= $n($pt["tot"]) ?></td>
  </tr>
  <?php endif; ?>
</table>
<br><br>

<table width="100%" cellpadding="6" cellspacing="0" style="border-top:0.5pt solid #dde3ec">
  <tr><td style="color:#7a8698;font-size:7.5pt;line-height:1.6">هذا التقرير مُولَّد إلكترونياً من بيانات الفواتير المُدخلة في نظام مِحكام، وفق متطلبات المرحلة الأولى من الفوترة الإلكترونية (هيئة الزكاة والضريبة والجمارك). جميع المبالغ بالريال السعودي.</td></tr>
</table>
<?php
$html = ob_get_clean();

$fb = '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:1.5pt solid ' . $navy . '">'
    . '<tr><td style="font-size:13pt;font-weight:bold;color:' . $navy . '">' . e($office['name']) . '</td>'
    . '<td style="text-align:left;font-size:8.5pt;color:#6b7686">'
    . ($vatNum ? 'الرقم الضريبي: <span dir="ltr">' . e($vatNum) . '</span>' : '')
    . ($crNum ? '<br>سجل تجاري: <span dir="ltr">' . e($crNum) . '</span>' : '')
    . '</td></tr></table>';

require_once '../includes/pdf.php';
mehkam_make_pdf($settings, $html, 'اقرار-ضريبي-' . $year . '-' . $period_type . '.pdf', [
    'title' => 'إقرار ضريبة القيمة المضافة — ' . $period_label,
    'dest'  => isset($_GET['view']) ? 'I' : 'D',
    'size'  => 9.5,
    'header_fallback_html' => $fb,
]);
