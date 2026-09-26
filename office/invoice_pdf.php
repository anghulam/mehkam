<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/zatca_helper.php';
requireOffice();
if (!can('invoices', 'view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int) $_SESSION['office_id'];

$id  = (int) ($_GET['id'] ?? 0);
$inv = $conn->query("SELECT * FROM invoices WHERE id=$id AND office_id=$oid")->fetch_assoc();
if (!$inv) { header('Location: invoices.php'); exit; }

$office   = $conn->query("SELECT * FROM offices WHERE id=$oid")->fetch_assoc();
$settings = $conn->query("SELECT * FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?: [];
$client   = $inv['client_id'] ? $conn->query("SELECT * FROM clients WHERE id=" . (int) $inv['client_id'])->fetch_assoc() : null;

$qr_data = $inv['qr_data'] ?? '';
if (!$qr_data && !empty($settings['tax_number'])) {
    $qr_data = zatca_qr($office['name'], $settings['tax_number'], (float) $inv['total'], (float) $inv['tax_amount'],
        ($inv['issue_date'] ?? date('Y-m-d')) . 'T00:00:00Z');
}
$items = json_decode($inv['items'] ?? '[]', true) ?: [];
$is_standard = ($inv['invoice_type'] ?? 'simplified') === 'standard';
$tax_rate = (float) ($inv['tax_rate'] ?? 15);
$navy  = '#0c1b36';
$line  = '#d9dee7';
$s_map = ['draft'=>'مسودة','sent'=>'مُرسلة','paid'=>'مدفوعة','overdue'=>'متأخرة','cancelled'=>'ملغاة'];
$s_col = ['paid'=>'#15803d','overdue'=>'#b91c1c','sent'=>'#b45309','draft'=>'#64748b','cancelled'=>'#64748b'];
$pct   = fn($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
$n     = fn($v) => '<span dir="ltr">' . number_format((float) $v, 2) . '</span>';
$naBlock = function ($row) {
    $L = na_lines($row ?: []);
    return $L ? implode('<br>', array_map('e', $L)) : '';
};

/* أنماط مضمّنة — TCPDF يتجاهل padding على td، لذا نعتمد cellpadding على <table> */
$K   = 'color:#6b7280';
$PH  = 'background-color:' . $navy . ';color:#ffffff;font-weight:bold;font-size:10pt';
$PB  = 'font-size:9.5pt;line-height:1.7;vertical-align:top';
$ML  = 'background-color:#f5f6f8;color:#5b6472;font-size:8.5pt;border:0.4pt solid ' . $line;
$MV  = 'font-size:9pt;border:0.4pt solid ' . $line . ';text-align:center';
$TL  = 'font-size:9.5pt;border-bottom:0.4pt solid #e9ecf1;color:#4b5563';
$TV  = 'font-size:9.5pt;border-bottom:0.4pt solid #e9ecf1;text-align:left;font-weight:bold';

ob_start();
?>
<style>
  body { font-family: amiri; font-size: 9.5pt; color: #1f2937; line-height: 1.55; }
  table.items th { background-color: <?= $navy ?>; color: #ffffff; font-size: 8.5pt; }
  table.items td { border-bottom: 0.4pt solid #e6e9ee; font-size: 9pt; }
</style>

<table cellpadding="0" cellspacing="0" width="100%"><tr>
  <td width="64%" style="vertical-align:bottom">
    <span style="font-size:17pt;font-weight:bold;color:<?= $navy ?>"><?= $is_standard ? 'فاتورة ضريبية' : 'فاتورة ضريبية مبسّطة' ?></span>
    &nbsp; <span style="color:#8a929e;font-size:10pt" dir="ltr"><?= e($inv['invoice_number']) ?></span>
  </td>
  <td width="36%" style="text-align:left;vertical-align:bottom">
    <span style="<?= $K ?>;font-size:8pt">الحالة:&nbsp;</span>
    <span style="font-weight:bold;font-size:10.5pt;color:<?= $s_col[$inv['status']] ?? '#334155' ?>"><?= e($s_map[$inv['status']] ?? $inv['status']) ?></span>
  </td>
</tr></table>
<div style="border-bottom:1.2pt solid <?= $navy ?>;margin:5px 0 14px">&nbsp;</div>

<table cellpadding="5" cellspacing="0" width="100%" style="border:0.4pt solid <?= $line ?>">
  <tr>
    <td width="18%" style="<?= $ML ?>">تاريخ الإصدار</td><td width="32%" style="<?= $MV ?>" dir="ltr"><?= e($inv['issue_date'] ?: date('Y-m-d')) ?></td>
    <td width="18%" style="<?= $ML ?>">تاريخ التوريد</td><td width="32%" style="<?= $MV ?>" dir="ltr"><?= e($inv['supply_date'] ?: $inv['issue_date'] ?: date('Y-m-d')) ?></td>
  </tr>
  <?php if ($inv['due_date']): ?>
  <tr><td style="<?= $ML ?>">تاريخ الاستحقاق</td><td style="<?= $MV ?>" dir="ltr"><?= e($inv['due_date']) ?></td>
      <td style="<?= $ML ?>">نوع الفاتورة</td><td style="<?= $MV ?>"><?= $is_standard ? 'ضريبية (B2B)' : 'مبسّطة' ?></td></tr>
  <?php endif; ?>
</table>
<br><br>

<table cellpadding="0" cellspacing="0" width="100%"><tr>
  <td width="48%" style="vertical-align:top">
    <table cellpadding="7" cellspacing="0" width="100%" style="border:0.5pt solid #cfd6e0">
      <tr><td style="<?= $PH ?>">المورِّد</td></tr>
      <tr><td style="<?= $PB ?>">
        <b style="font-size:10.5pt"><?= e($office['name']) ?></b>
        <?php if (!empty($settings['tax_number'])): ?><br><span style="<?= $K ?>">الرقم الضريبي:</span> <span dir="ltr"><?= e($settings['tax_number']) ?></span><?php endif; ?>
        <?php if (!empty($settings['cr_number'])): ?><br><span style="<?= $K ?>">سجل تجاري:</span> <span dir="ltr"><?= e($settings['cr_number']) ?></span><?php endif; ?>
        <?php if (!empty($office['phone'])): ?><br><span style="<?= $K ?>">الجوال:</span> <span dir="ltr"><?= e($office['phone']) ?></span><?php endif; ?>
        <?php if (!empty($settings['address'])): ?><br><span style="<?= $K ?>">العنوان:</span> <?= e($settings['address']) ?><?php endif; ?>
        <?php if ($nb = $naBlock($settings)): ?><br><span style="<?= $K ?>">العنوان الوطني:</span> <?= $nb ?><?php endif; ?>
      </td></tr>
    </table>
  </td>
  <td width="4%"></td>
  <td width="48%" style="vertical-align:top">
    <table cellpadding="7" cellspacing="0" width="100%" style="border:0.5pt solid #cfd6e0">
      <tr><td style="<?= $PH ?>">العميل / المشتري</td></tr>
      <tr><td style="<?= $PB ?>">
        <b style="font-size:10.5pt"><?= e($client['full_name'] ?? 'عميل نقدي') ?></b>
        <?php if (!empty($client['phone'])): ?><br><span style="<?= $K ?>">الجوال:</span> <span dir="ltr"><?= e($client['phone']) ?></span><?php endif; ?>
        <?php $cvat = $inv['buyer_vat'] ?: ($client['vat_number'] ?? ''); ?>
        <?php if ($cvat): ?><br><span style="<?= $K ?>">الرقم الضريبي:</span> <span dir="ltr"><?= e($cvat) ?></span><?php endif; ?>
        <?php if (!empty($client['cr_number'])): ?><br><span style="<?= $K ?>">سجل تجاري:</span> <span dir="ltr"><?= e($client['cr_number']) ?></span><?php endif; ?>
        <?php if ($nb = $naBlock($client)): ?><br><span style="<?= $K ?>">العنوان الوطني:</span> <?= $nb ?><?php endif; ?>
      </td></tr>
    </table>
  </td>
</tr></table>
<br><br>

<table cellpadding="6" cellspacing="0" width="100%" style="border:0.4pt solid <?= $line ?>;border-right:2.5pt solid <?= $navy ?>">
  <tr><td style="background-color:#f5f6f8;font-size:9.5pt"><span style="<?= $K ?>">الموضوع:</span> <b><?= e($inv['title']) ?></b></td></tr>
</table>
<br><br>

<table class="items" cellpadding="6" cellspacing="0" width="100%">
  <thead><tr>
    <th width="7%">#</th><th width="45%" style="text-align:right">البيان</th><th width="12%">الكمية</th><th width="18%" style="text-align:left">سعر الوحدة</th><th width="18%" style="text-align:left">الإجمالي</th>
  </tr></thead>
  <tbody>
  <?php $r = 0; foreach ($items as $it): $r++;
    $lineSub = (float) ($it['qty'] ?? 1) * (float) ($it['price'] ?? 0); ?>
  <tr>
    <td style="text-align:center;color:#9aa1ac"><?= $r ?></td>
    <td><?= e($it['description'] ?? '') ?></td>
    <td style="text-align:center"><?= e($it['qty'] ?? 1) ?></td>
    <td style="text-align:left"><?= $n($it['price'] ?? 0) ?></td>
    <td style="text-align:left"><?= $n($lineSub) ?></td>
  </tr>
  <?php endforeach; if ($r === 0): ?>
  <tr><td colspan="5" style="text-align:center;color:#9aa1ac">لا توجد بنود</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<br><br>

<table cellpadding="0" cellspacing="0" width="100%"><tr>
  <td width="44%">&nbsp;</td>
  <td width="56%">
    <table cellpadding="7" cellspacing="0" width="100%" style="border:0.4pt solid #e6e9ee">
      <tr><td style="<?= $TL ?>">المجموع الفرعي</td><td style="<?= $TV ?>"><?= $n($inv['subtotal']) ?> ر.س</td></tr>
      <?php if ((float) $inv['discount'] > 0): ?>
      <tr><td style="<?= $TL ?>">الخصم<?= ($inv['discount_type'] ?? '') === 'percent' ? ' (' . $pct($inv['discount_value']) . '%)' : '' ?></td>
          <td style="<?= $TV ?>;color:#b91c1c">− <?= $n($inv['discount']) ?> ر.س</td></tr>
      <?php endif; ?>
      <tr><td style="<?= $TL ?>;background-color:#fdf6e3;color:#7a5a12">ضريبة القيمة المضافة (<?= $pct($tax_rate) ?>%)</td>
          <td style="<?= $TV ?>;background-color:#fdf6e3;color:#7a5a12"><?= $n($inv['tax_amount']) ?> ر.س</td></tr>
      <tr><td style="background-color:<?= $navy ?>;color:#ffffff;font-weight:bold;font-size:11pt">الإجمالي شامل الضريبة</td>
          <td style="background-color:<?= $navy ?>;color:#ffffff;font-weight:bold;font-size:11pt;text-align:left"><?= $n($inv['total']) ?> ر.س</td></tr>
    </table>
  </td>
</tr></table>

<?php if ($inv['notes'] || !empty($settings['bank_iban'])): ?>
<br><br>
<table cellpadding="6" cellspacing="0" width="100%" style="border:0.4pt solid <?= $line ?>">
  <tr><td style="font-size:8.5pt;color:#4b5563;line-height:1.6">
    <?php if ($inv['notes']): ?><span style="<?= $K ?>">ملاحظات:</span> <?= nl2br(e($inv['notes'])) ?><br><?php endif; ?>
    <?php if (!empty($settings['bank_iban'])): ?><span style="<?= $K ?>">الآيبان:</span> <span dir="ltr"><?= e($settings['bank_iban']) ?></span><?php if (!empty($settings['bank_name'])): ?> — <?= e($settings['bank_name']) ?><?php endif; ?><?php endif; ?>
  </td></tr>
</table>
<?php endif; ?>
<?php
$html = ob_get_clean();

$fb = '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:1.5pt solid ' . $navy . '">'
    . '<tr><td style="font-size:13pt;font-weight:bold;color:' . $navy . '">' . e($office['name']) . '</td>'
    . '<td style="text-align:left;font-size:8.5pt;color:#6b7686">'
    . (!empty($settings['tax_number']) ? 'الرقم الضريبي: <span dir="ltr">' . e($settings['tax_number']) . '</span>' : '')
    . '</td></tr></table>';

require_once '../includes/pdf.php';
mehkam_make_pdf($settings, $html, 'فاتورة-' . preg_replace('/[^\p{Arabic}\w\-]+/u', '_', $inv['invoice_number']) . '.pdf', [
    'title' => 'فاتورة ' . $inv['invoice_number'],
    'dest'  => isset($_GET['view']) ? 'I' : 'D',
    'size'  => 9.5,
    'header_fallback_html' => $fb,
    'qr' => $qr_data ?: null,
    'qr_caption' => 'رمز الاستجابة السريعة — التحقق من الفاتورة (هيئة الزكاة والضريبة والجمارك)',
    'qr_size' => 32,
]);
