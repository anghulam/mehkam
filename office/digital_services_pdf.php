<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('services', 'view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int) $_SESSION['office_id'];

if (!hasFeature($conn, $oid, 'has_digital_services')) {
    header("Location: profile.php?tab=upgrade&feature=digital_services"); exit;
}

$office   = $conn->query("SELECT * FROM offices WHERE id=$oid")->fetch_assoc();
$settings = $conn->query("SELECT * FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?: [];

$navy = '#0c1b36';

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
$fromE = $conn->real_escape_string($from);
$toE   = $conn->real_escape_string($to);

$fstat = $_GET['status_f'] ?? '';
$ftype = (int) ($_GET['type_f'] ?? 0);
$fq    = trim($_GET['q'] ?? '');
$fqE   = $conn->real_escape_string($fq);

$w = "sr.office_id=$oid AND DATE(sr.created_at) BETWEEN '$fromE' AND '$toE'";
if (in_array($fstat, ['new', 'in_progress', 'completed', 'cancelled'], true)) $w .= " AND sr.status='$fstat'";
if ($ftype) $w .= " AND os.type_id=$ftype";
if ($fq !== '') $w .= " AND (cl.full_name LIKE '%$fqE%' OR cl.id_number LIKE '%$fqE%' OR sr.service_name LIKE '%$fqE%')";

$S_SVC = ['new' => 'جديد', 'in_progress' => 'قيد التنفيذ', 'completed' => 'مكتمل', 'cancelled' => 'ملغى'];
$dd = function ($v) { if (empty($v)) return '—'; return trim(strip_tags(dDate($v))); };
$dt = function ($v) { if (empty($v)) return '—'; return trim(strip_tags(dDate($v, true))); };
$nf = fn($v) => number_format((float) $v, 2);

/* ═══ الإجمالي العام للفترة ═══ */
$totals = $conn->query("SELECT COUNT(*) cnt, IFNULL(SUM(sr.amount),0) amount, IFNULL(SUM(sr.vat_amount),0) vat, IFNULL(SUM(sr.total),0) total
    FROM service_requests sr
    LEFT JOIN clients cl ON sr.client_id = cl.id
    LEFT JOIN office_services os ON sr.service_id = os.id
    WHERE $w")->fetch_assoc();

$by_status = [];
$rs = $conn->query("SELECT sr.status, COUNT(*) cnt, IFNULL(SUM(sr.total),0) total
    FROM service_requests sr
    LEFT JOIN clients cl ON sr.client_id = cl.id
    LEFT JOIN office_services os ON sr.service_id = os.id
    WHERE $w GROUP BY sr.status");
if ($rs) while ($x = $rs->fetch_assoc()) $by_status[$x['status']] = $x;

/* ═══ التوزيع اليومي — هذا هو جوهر التقرير: ماذا أُنجز كل يوم ═══ */
$daily = [];
$rs = $conn->query("SELECT DATE(sr.created_at) d, COUNT(*) cnt,
        SUM(sr.status='new') c_new, SUM(sr.status='in_progress') c_prog,
        SUM(sr.status='completed') c_done, SUM(sr.status='cancelled') c_cancel,
        IFNULL(SUM(sr.amount),0) amount, IFNULL(SUM(sr.vat_amount),0) vat, IFNULL(SUM(sr.total),0) total
    FROM service_requests sr
    LEFT JOIN clients cl ON sr.client_id = cl.id
    LEFT JOIN office_services os ON sr.service_id = os.id
    WHERE $w GROUP BY DATE(sr.created_at) ORDER BY d DESC");
if ($rs) while ($x = $rs->fetch_assoc()) $daily[] = $x;

/* ═══ الأكثر طلباً — توزيع حسب الخدمة ═══ */
$by_service = [];
$rs = $conn->query("SELECT sr.service_name, COUNT(*) cnt, IFNULL(SUM(sr.total),0) total
    FROM service_requests sr
    LEFT JOIN clients cl ON sr.client_id = cl.id
    LEFT JOIN office_services os ON sr.service_id = os.id
    WHERE $w GROUP BY sr.service_name ORDER BY total DESC LIMIT 15");
if ($rs) while ($x = $rs->fetch_assoc()) $by_service[] = $x;

/* ═══ تفصيل كل الطلبات ═══ */
$ROW_CAP = 300;
$rows = [];
$rs = $conn->query("SELECT sr.*, cl.full_name client_name, c.case_number, ot.name type_name, u.full_name emp_name
    FROM service_requests sr
    LEFT JOIN clients cl ON sr.client_id = cl.id
    LEFT JOIN cases c ON sr.case_id = c.id
    LEFT JOIN office_services os ON sr.service_id = os.id
    LEFT JOIN office_service_types ot ON os.type_id = ot.id
    LEFT JOIN users u ON sr.created_by = u.id
    WHERE $w ORDER BY sr.created_at DESC LIMIT $ROW_CAP");
if ($rs) while ($x = $rs->fetch_assoc()) $rows[] = $x;

$daysCount  = max(1, (strtotime($to) - strtotime($from)) / 86400 + 1);
$avgPerDay  = $totals['cnt'] / $daysCount;

ob_start();
?>
<div style="text-align:center;font-size:17pt;font-weight:bold;color:<?= $navy ?>">تقرير الخدمات الرقمية</div>
<div style="text-align:center;color:#6b7280;font-size:8.5pt;padding-bottom:6px">
  الفترة: <?= e($dd($from)) ?> — <?= e($dd($to)) ?> &nbsp;•&nbsp; تاريخ الإصدار: <?= e($dd(date('Y-m-d'))) ?>
  <?= $fq !== '' ? ' &nbsp;•&nbsp; بحث: «' . e($fq) . '»' : '' ?>
</div>
<div style="border-bottom:1.2pt solid <?= $navy ?>">&nbsp;</div>
<br>

<?php
$K  = 'background-color:#f5f6f8;color:#5b6472;font-size:8.5pt;border:0.4pt solid #e1e5ec';
$V  = 'font-size:11pt;font-weight:bold;border:0.4pt solid #e1e5ec';
$HC = 'background-color:' . $navy . ';color:#ffffff;font-size:9pt';
$IC = 'font-size:9pt;border:0.4pt solid #e1e5ec';
$SEP = '<div style="font-size:6pt">&nbsp;</div>';
function ds_section($title, $navy) {
    echo '<table width="100%" cellpadding="6" cellspacing="0" style="border:0.6pt solid #c4cddb;margin-top:4px">
      <tr><td style="background-color:'.$navy.';color:#fff;font-weight:bold;font-size:11pt">'.$title.'</td></tr>
    </table><br>';
}
?>

<!-- ═══ ملخص عام ═══ -->
<table width="100%" cellpadding="7" cellspacing="0" style="margin-bottom:8px">
  <tr>
    <td width="20%" style="<?= $K ?>">عدد الطلبات</td>
    <td width="20%" style="<?= $K ?>">متوسط يومي</td>
    <td width="20%" style="<?= $K ?>">السعر قبل الضريبة</td>
    <td width="20%" style="<?= $K ?>">ضريبة القيمة المضافة</td>
    <td width="20%" style="<?= $K ?>">الإجمالي شامل الضريبة</td>
  </tr>
  <tr>
    <td style="<?= $V ?>;color:<?= $navy ?>"><?= (int) $totals['cnt'] ?></td>
    <td style="<?= $V ?>;color:<?= $navy ?>"><?= number_format($avgPerDay, 1) ?></td>
    <td style="<?= $V ?>"><?= $nf($totals['amount']) ?> ﷼</td>
    <td style="<?= $V ?>"><?= $nf($totals['vat']) ?> ﷼</td>
    <td style="<?= $V ?>;color:#16a34a"><?= $nf($totals['total']) ?> ﷼</td>
  </tr>
</table>

<!-- ═══ حسب الحالة ═══ -->
<?php ds_section('توزيع الطلبات حسب الحالة', $navy); ?>
<table width="100%" cellpadding="6" cellspacing="0" style="border:0.4pt solid #dde3ec">
  <tr><td style="<?= $HC ?>">الحالة</td><td style="<?= $HC ?>">عدد الطلبات</td><td style="<?= $HC ?>">الإجمالي</td></tr>
  <?php foreach ($S_SVC as $sk => $sl): $row = $by_status[$sk] ?? null; ?>
  <tr>
    <td style="<?= $IC ?>"><?= e($sl) ?></td>
    <td style="<?= $IC ?>"><?= (int) ($row['cnt'] ?? 0) ?></td>
    <td style="<?= $IC ?>"><?= $nf($row['total'] ?? 0) ?> ﷼</td>
  </tr>
  <?php endforeach; ?>
</table>
<?= $SEP ?><br>

<!-- ═══ التوزيع اليومي — ماذا أُنجز كل يوم ═══ -->
<?php ds_section('التوزيع اليومي', $navy); ?>
<table width="100%" cellpadding="6" cellspacing="0" style="border:0.4pt solid #dde3ec">
  <tr>
    <td style="<?= $HC ?>">التاريخ</td><td style="<?= $HC ?>">عدد الطلبات</td>
    <td style="<?= $HC ?>">جديد</td><td style="<?= $HC ?>">قيد التنفيذ</td>
    <td style="<?= $HC ?>">مكتمل</td><td style="<?= $HC ?>">ملغى</td>
    <td style="<?= $HC ?>">السعر قبل الضريبة</td><td style="<?= $HC ?>">الضريبة</td><td style="<?= $HC ?>">الإجمالي</td>
  </tr>
  <?php if ($daily): foreach ($daily as $d): ?>
  <tr>
    <td style="<?= $IC ?>"><b><?= e($dd($d['d'])) ?></b></td>
    <td style="<?= $IC ?>"><?= (int) $d['cnt'] ?></td>
    <td style="<?= $IC ?>"><?= (int) $d['c_new'] ?></td>
    <td style="<?= $IC ?>"><?= (int) $d['c_prog'] ?></td>
    <td style="<?= $IC ?>"><?= (int) $d['c_done'] ?></td>
    <td style="<?= $IC ?>"><?= (int) $d['c_cancel'] ?></td>
    <td style="<?= $IC ?>"><?= $nf($d['amount']) ?></td>
    <td style="<?= $IC ?>"><?= $nf($d['vat']) ?></td>
    <td style="<?= $IC ?>"><b><?= $nf($d['total']) ?></b></td>
  </tr>
  <?php endforeach; else: ?>
  <tr><td colspan="9" style="<?= $IC ?>;text-align:center;color:#9aa4b2">لا توجد طلبات في هذه الفترة</td></tr>
  <?php endif; ?>
</table>
<?= $SEP ?><br>

<!-- ═══ حسب الخدمة ═══ -->
<?php if ($by_service): ds_section('الأكثر طلباً — حسب الخدمة', $navy); ?>
<table width="100%" cellpadding="6" cellspacing="0" style="border:0.4pt solid #dde3ec">
  <tr><td style="<?= $HC ?>">الخدمة</td><td style="<?= $HC ?>">عدد الطلبات</td><td style="<?= $HC ?>">الإجمالي</td></tr>
  <?php foreach ($by_service as $s): ?>
  <tr>
    <td style="<?= $IC ?>"><?= e(mb_substr($s['service_name'], 0, 55)) ?></td>
    <td style="<?= $IC ?>"><?= (int) $s['cnt'] ?></td>
    <td style="<?= $IC ?>"><?= $nf($s['total']) ?> ﷼</td>
  </tr>
  <?php endforeach; ?>
</table>
<?= $SEP ?><br>
<?php endif; ?>

<!-- ═══ تفصيل كل طلب ═══ -->
<?php ds_section('تفصيل الطلبات (' . count($rows) . ')', $navy); ?>
<table width="100%" cellpadding="6" cellspacing="0" style="border:0.4pt solid #dde3ec">
  <tr>
    <td style="<?= $HC ?>">التاريخ</td><td style="<?= $HC ?>">العميل</td><td style="<?= $HC ?>">الخدمة</td>
    <td style="<?= $HC ?>">القضية</td><td style="<?= $HC ?>">الإجمالي</td><td style="<?= $HC ?>">الحالة</td><td style="<?= $HC ?>">الموظف</td>
  </tr>
  <?php if ($rows): foreach ($rows as $r): ?>
  <tr>
    <td style="<?= $IC ?>;white-space:nowrap"><?= e($dt($r['created_at'])) ?></td>
    <td style="<?= $IC ?>"><?= e($r['client_name'] ?: '—') ?></td>
    <td style="<?= $IC ?>">
      <?= e(mb_substr($r['service_name'], 0, 35)) ?>
      <?php if ($r['type_name']): ?> <span style="color:#6b7280;font-size:7.5pt">(<?= e($r['type_name']) ?>)</span><?php endif; ?>
    </td>
    <td style="<?= $IC ?>"><?= e($r['case_number'] ?: '—') ?></td>
    <td style="<?= $IC ?>"><b><?= $nf($r['total']) ?></b></td>
    <td style="<?= $IC ?>"><?= e($S_SVC[$r['status']] ?? $r['status']) ?></td>
    <td style="<?= $IC ?>"><?= e($r['emp_name'] ?: $r['created_by_name'] ?: '—') ?></td>
  </tr>
  <?php endforeach; else: ?>
  <tr><td colspan="7" style="<?= $IC ?>;text-align:center;color:#9aa4b2">لا توجد طلبات في هذه الفترة</td></tr>
  <?php endif; ?>
</table>
<?php if (count($rows) >= $ROW_CAP): ?>
<div style="font-size:7.5pt;color:#9aa4b2;padding-top:4px">* يعرض هذا الجدول أحدث <?= $ROW_CAP ?> طلباً ضمن الفترة المحددة — ضيّق نطاق التاريخ لعرض كل الطلبات.</div>
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
$fn = 'تقرير-الخدمات-الرقمية-' . $from . '-إلى-' . $to . '.pdf';
mehkam_make_pdf($settings, $html, $fn, [
    'title' => 'تقرير الخدمات الرقمية',
    'dest'  => isset($_GET['view']) ? 'I' : 'D',
    'size'  => 9.5,
    'header_fallback_html' => $fb,
]);
