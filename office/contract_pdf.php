<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('contracts', 'view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int) $_SESSION['office_id'];

$id = (int) ($_GET['id'] ?? 0);
$c  = $conn->query("SELECT ct.*, cl.full_name client_name, cl.id_number client_idn, cl.phone client_phone
    FROM contracts ct LEFT JOIN clients cl ON ct.client_id = cl.id
    WHERE ct.id=$id AND ct.office_id=$oid")->fetch_assoc();
if (!$c) { header('Location: contracts.php'); exit; }

$office   = $conn->query("SELECT * FROM offices WHERE id=$oid")->fetch_assoc();
$settings = $conn->query("SELECT * FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?: [];

$clauses = $c['clauses'] ? (json_decode($c['clauses'], true) ?: []) : [];
$linked  = [];
if (!empty($c['linked_ids'])) {
    $ids = implode(',', array_filter(array_map('intval', explode(',', $c['linked_ids']))));
    if ($ids !== '') {
        $lr = $conn->query("SELECT id, contract_number, title FROM contracts WHERE office_id=$oid AND id IN ($ids)");
        if ($lr) while ($x = $lr->fetch_assoc()) $linked[] = $x;
    }
}

/* ── بناء HTML متوافق مع TCPDF (جداول + أنماط بسيطة فقط) ── */
$navy = '#0c1b36';
$muted = '#5b6472';

ob_start();
?>
<style>
  body { font-family: amiri; font-size: 11pt; color: #1a1a1a; line-height: 1.7; }
  h1 { font-size: 17pt; color: <?= $navy ?>; text-align: center; margin: 0 0 3px; }
  .sub { text-align: center; color: <?= $muted ?>; font-size: 9.5pt; margin: 0 0 14px; }
  .sec { font-weight: bold; color: <?= $navy ?>; font-size: 12.5pt; margin: 14px 0 5px; }
  .clause { margin-bottom: 9px; text-align: justify; }
  .clause b { color: <?= $navy ?>; font-size: 11pt; }
  .pre { text-align: justify; }
  .linked { font-size: 9.5pt; color: #45526a; }
</style>
<?php
$SBOX = 'border:0.7pt solid #b9c2d0;font-size:10.5pt;vertical-align:top;line-height:1.9';
$SH   = 'font-weight:bold;color:' . $navy . ';font-size:11.5pt';
$sigBox = function ($title) use ($SBOX, $SH) {
    return '<td width="47%" style="' . $SBOX . '">'
        . '<span style="' . $SH . '">' . $title . '</span>'
        . '<br><br>الاسم: ____________________'
        . '<br><br>الصفة: ____________________'
        . '<br><br>التوقيع والختم:'
        . '<br><br><br><br>&nbsp;</td>';
};
?>

<h1><?= e($c['title']) ?></h1>
<div class="sub"><?= e($c['contract_type'] ?: 'عقد') ?> — رقم <?= e($c['contract_number']) ?></div>

<?php if (!empty($c['party_first']) || !empty($c['party_second'])): ?>
<div class="sec">أطراف العقد</div>
<?php if (!empty($c['party_first'])): ?><div class="clause"><b>الطرف الأول:</b> <?= nl2br(e($c['party_first'])) ?></div><?php endif; ?>
<?php if (!empty($c['party_second'])): ?><div class="clause"><b>الطرف الثاني:</b> <?= nl2br(e($c['party_second'])) ?></div><?php endif; ?>
<?php endif; ?>

<?php if (!empty($c['preamble'])): ?>
<div class="sec">التمهيد</div>
<div class="pre"><?= nl2br(e($c['preamble'])) ?></div>
<?php endif; ?>

<?php if ($clauses): ?>
<div class="sec">بنود العقد</div>
<?php foreach ($clauses as $i => $cl): ?>
<div class="clause"><b><?= ($i + 1) ?>. <?= e($cl['title'] ?? '') ?></b><br><?= nl2br(e($cl['body'] ?? '')) ?></div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($linked): ?>
<div class="sec">عقود ذات صلة</div>
<div class="linked">
<?php foreach ($linked as $l): ?>• <?= e($l['contract_number']) ?> — <?= e($l['title']) ?><br><?php endforeach; ?>
</div>
<?php endif; ?>

<br><br>
<div class="sec">التوقيعات</div>
<br>
<table nobr="true" cellpadding="10" cellspacing="0" border="0" width="100%">
  <tr>
    <?= $sigBox('الطرف الأول') ?>
    <td width="6%">&nbsp;</td>
    <?= $sigBox('الطرف الثاني') ?>
  </tr>
</table>
<?php
$html = ob_get_clean();

// ترويسة نصية بديلة إن لا يوجد ليتر هيد
$fb = '<div style="font-size:9pt;color:#5b6472;border-bottom:1.5pt solid ' . $navy . ';padding-bottom:4px">'
    . '<b style="font-size:13pt;color:' . $navy . '">' . e($office['name']) . '</b>'
    . (!empty($settings['address']) ? ' — ' . e($settings['address']) : '')
    . (!empty($settings['cr_number']) ? '<br>سجل تجاري: ' . e($settings['cr_number']) : '')
    . '</div>';

require_once '../includes/pdf.php';
$safe = preg_replace('/[^\p{Arabic}\w\-]+/u', '_', $c['contract_number'] ?: 'contract');
mehkam_make_pdf($settings, $html, 'عقد-' . $safe . '.pdf', [
    'title' => $c['title'],
    'dest'  => isset($_GET['view']) ? 'I' : 'D',
    'header_fallback_html' => $fb,
]);
