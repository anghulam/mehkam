<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/print_letterhead.php';
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
$logo = $settings['office_logo'] ?? '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>عقد <?= e($c['contract_number']) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Segoe UI',Tahoma,Arial,sans-serif; font-size:13px; color:#1a1a1a; background:#f1f5f9; direction:rtl; line-height:2; }
  .toolbar { background:#0c1b36; padding:10px 20px; display:flex; justify-content:space-between; align-items:center; color:#fff; font-size:13px; }
  .toolbar a { color:#94a3b8; text-decoration:none; }
  .toolbar button { background:#e8c040; color:#0c1b36; border:none; padding:6px 16px; border-radius:6px; font-weight:700; cursor:pointer; }
  .page { width:210mm; min-height:297mm; margin:16px auto; background:#fff; padding:20mm 22mm; box-shadow:0 4px 20px rgba(0,0,0,.1); }
  .c-head { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #0c1b36; padding-bottom:14px; margin-bottom:20px; }
  .c-office { font-size:20px; font-weight:800; color:#0c1b36; }
  .c-office small { display:block; font-size:11px; font-weight:400; color:#64748b; margin-top:3px; }
  .c-logo { height:64px; max-width:130px; object-fit:contain; }
  .c-title { text-align:center; font-size:19px; font-weight:800; margin:14px 0 6px; color:#0c1b36; }
  .c-sub { text-align:center; font-size:12px; color:#64748b; margin-bottom:18px; }
  .c-meta { display:flex; flex-wrap:wrap; gap:8px 24px; font-size:12px; color:#334155; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; margin-bottom:18px; }
  .c-section-t { font-weight:800; color:#0c1b36; margin:16px 0 6px; font-size:14px; }
  .c-preamble { white-space:pre-wrap; text-align:justify; }
  .c-clause { margin-bottom:12px; text-align:justify; }
  .c-clause h4 { font-size:13.5px; color:#0c1b36; margin-bottom:2px; }
  .c-clause .body { white-space:pre-wrap; }
  .c-linked { font-size:12px; color:#475569; background:#f8fafc; border-radius:6px; padding:8px 12px; }
  .c-sign { display:flex; justify-content:space-between; margin-top:48px; gap:40px; }
  .c-sign > div { flex:1; text-align:center; }
  .c-sign .line { margin-top:44px; border-top:1px solid #334155; padding-top:6px; font-size:12px; }
  @media print {
    body { background:#fff; }
    .toolbar { display:none; }
    .page { box-shadow:none; margin:0; width:100%; padding:14mm; }
  }
</style>
<?= lh_head($settings) ?>
</head>
<body class="<?= lh_class($settings) ?>">
<?= lh_body($settings) ?>

<div class="toolbar">
  <div>
    <i class="fas fa-file-signature" style="margin-left:8px"></i>عقد — <?= e($c['contract_number']) ?>
    <?php if (lh_active($settings)): ?>
    <span style="font-size:11px;color:#94a3b8;margin-right:10px">المعاينة تقريبية — «طباعة / PDF» يوزّع النص على صفحات بالليتر هيد على كلٍّ منها (فعّل «رسومات الخلفية» في نافذة الطباعة)</span>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:12px;align-items:center">
    <a href="contracts.php">← عودة</a>
    <button onclick="window.print()">🖨 طباعة / PDF</button>
  </div>
</div>

<div class="page">
  <div class="c-head">
    <div class="c-office">
      <?= e($office['name']) ?>
      <small><?= e($settings['address'] ?? '') ?><?php if (!empty($office['phone'])): ?> — <?= e($office['phone']) ?><?php endif; ?></small>
      <?php if (!empty($settings['cr_number'])): ?><small>سجل تجاري: <?= e($settings['cr_number']) ?></small><?php endif; ?>
    </div>
    <?php if ($logo && file_exists('../' . $logo)): ?>
    <img class="c-logo" src="../<?= e($logo) ?>" alt="">
    <?php endif; ?>
  </div>

  <div class="c-title"><?= e($c['title']) ?></div>
  <div class="c-sub"><?= e($c['contract_type'] ?: 'عقد') ?> — رقم <?= e($c['contract_number']) ?></div>

  <div class="c-meta">
    <span><b>التاريخ:</b> <?= $c['start_date'] ? dDate($c['start_date']) : dDate(date('Y-m-d')) ?></span>
    <?php if ($c['end_date']): ?><span><b>ينتهي:</b> <?= dDate($c['end_date']) ?></span><?php endif; ?>
    <?php if ((float)$c['value'] > 0): ?><span><b>القيمة:</b> <?= number_format($c['value'], 2) ?> ر.س</span><?php endif; ?>
    <?php if ($c['client_name']): ?><span><b>العميل:</b> <?= e($c['client_name']) ?></span><?php endif; ?>
  </div>

  <?php if (!empty($c['party_first']) || !empty($c['party_second'])): ?>
  <div class="c-section-t">أطراف العقد</div>
  <?php if (!empty($c['party_first'])): ?><div class="c-clause"><b>الطرف الأول:</b> <?= nl2br(e($c['party_first'])) ?></div><?php endif; ?>
  <?php if (!empty($c['party_second'])): ?><div class="c-clause"><b>الطرف الثاني:</b> <?= nl2br(e($c['party_second'])) ?></div><?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($c['preamble'])): ?>
  <div class="c-section-t">التمهيد</div>
  <div class="c-preamble"><?= e($c['preamble']) ?></div>
  <?php endif; ?>

  <?php if ($clauses): ?>
  <div class="c-section-t">بنود العقد</div>
  <?php foreach ($clauses as $i => $cl): ?>
  <div class="c-clause">
    <h4><?= ($i + 1) ?>. <?= e($cl['title'] ?? '') ?></h4>
    <div class="body"><?= e($cl['body'] ?? '') ?></div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($linked): ?>
  <div class="c-section-t">عقود ذات صلة</div>
  <div class="c-linked">
    <?php foreach ($linked as $l): ?>
    <div>• <?= e($l['contract_number']) ?> — <?= e($l['title']) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="c-sign">
    <div><b>الطرف الأول</b><div class="line">الاسم / التوقيع / الختم</div></div>
    <div><b>الطرف الثاني</b><div class="line">الاسم / التوقيع / الختم</div></div>
  </div>
</div>

<?= lh_foot($settings) ?>
</body>
</html>
