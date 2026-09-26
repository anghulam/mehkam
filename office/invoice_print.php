<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/zatca_helper.php';
require_once '../includes/print_letterhead.php';
requireOffice();
$oid = (int)$_SESSION['office_id'];

$id  = (int)($_GET['id'] ?? 0);
$inv = $conn->query("SELECT * FROM invoices WHERE id=$id AND office_id=$oid")->fetch_assoc();
if (!$inv) { header("Location: invoices.php"); exit; }

// بيانات المكتب وإعداداته
$office   = $conn->query("SELECT * FROM offices WHERE id=$oid")->fetch_assoc();
$settings = $conn->query("SELECT * FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?? [];
$client   = $inv['client_id'] ? $conn->query("SELECT * FROM clients WHERE id={$inv['client_id']}")->fetch_assoc() : null;

// توليد QR إذا لم يكن محفوظاً
$qr_data = $inv['qr_data'] ?? '';
if (!$qr_data && ($settings['tax_number'] ?? '')) {
    $qr_data = zatca_qr(
        $office['name'],
        $settings['tax_number'],
        (float)$inv['total'],
        (float)$inv['tax_amount'],
        ($inv['issue_date'] ?? date('Y-m-d')) . 'T00:00:00Z'
    );
    $conn->query("UPDATE invoices SET qr_data='".addslashes($qr_data)."' WHERE id=$id");
}

$items = json_decode($inv['items'] ?? '[]', true) ?: [];
$inv_type = $inv['invoice_type'] ?? 'simplified';
$is_standard = ($inv_type === 'standard');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>فاتورة <?= e($inv['invoice_number']) ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Segoe UI',Tahoma,Arial,sans-serif; font-size:12px; color:#1a1a1a; background:#fff; direction:rtl; }
  .page { width:210mm; min-height:297mm; margin:0 auto; padding:12mm 14mm; }

  /* رأس الفاتورة */
  .inv-head { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #0c1b36; padding-bottom:12px; margin-bottom:16px; }
  .inv-logo { font-size:24px; font-weight:900; color:#0c1b36; letter-spacing:-0.5px; }
  .inv-logo small { font-size:11px; font-weight:400; color:#64748b; display:block; }
  .inv-type-badge { background:#0c1b36; color:#e8c040; padding:4px 14px; border-radius:20px; font-size:11px; font-weight:700; }
  .inv-type-en { color:#64748b; font-size:10px; display:block; text-align:center; margin-top:2px; }

  /* بيانات الفاتورة */
  .inv-meta { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
  .inv-meta-box { border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; }
  .inv-meta-title { font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; font-weight:700; border-bottom:1px solid #f1f5f9; padding-bottom:4px; }
  .inv-meta-row { display:flex; justify-content:space-between; margin-bottom:4px; font-size:11.5px; }
  .inv-meta-row .lbl { color:#64748b; }
  .inv-meta-row .val { font-weight:600; text-align:left; direction:ltr; }
  .inv-meta-row .val-ar { font-weight:600; }

  /* الأطراف */
  .parties { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
  .party-box { border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; }
  .party-label { font-size:10px; font-weight:700; color:#fff; background:#0c1b36; padding:3px 8px; border-radius:4px; display:inline-block; margin-bottom:8px; }
  .party-label.buyer { background:#1a3a6e; }
  .party-name { font-size:14px; font-weight:800; color:#0c1b36; margin-bottom:4px; }
  .party-detail { font-size:11px; color:#64748b; margin-bottom:2px; }
  .party-vat { font-size:11px; background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:4px; display:inline-block; margin-top:4px; font-weight:700; }

  /* بنود الفاتورة */
  .items-table { width:100%; border-collapse:collapse; margin-bottom:16px; font-size:11.5px; }
  .items-table thead tr { background:#0c1b36; color:#fff; }
  .items-table th { padding:8px 10px; text-align:right; font-weight:600; }
  .items-table th.num { text-align:center; }
  .items-table tbody tr:nth-child(even) { background:#f8fafc; }
  .items-table tbody tr { border-bottom:1px solid #e2e8f0; }
  .items-table td { padding:8px 10px; }
  .items-table td.num { text-align:center; color:#94a3b8; }
  .items-table td.amount { text-align:left; direction:ltr; font-weight:600; }
  .items-table tfoot tr { background:#f1f5f9; }
  .items-table tfoot td { padding:6px 10px; font-weight:600; }
  .items-table tfoot .label { text-align:right; }
  .items-table tfoot .value { text-align:left; direction:ltr; }
  .total-row td { background:#0c1b36 !important; color:#fff; font-weight:800; font-size:13px; }
  .vat-row td { background:#fef3c7; color:#92400e; }

  /* QR + ملاحظات */
  .inv-bottom { display:flex; justify-content:space-between; align-items:flex-start; margin-top:16px; }
  .qr-section { text-align:center; }
  .qr-section canvas, .qr-section img { border:1px solid #e2e8f0; border-radius:6px; padding:4px; }
  .qr-label { font-size:9px; color:#64748b; margin-top:4px; }
  .notes-section { flex:1; margin-right:20px; }
  .notes-title { font-size:10px; font-weight:700; color:#64748b; margin-bottom:6px; }
  .notes-text { font-size:11px; color:#374151; line-height:1.7; }
  .bank-section { margin-top:10px; padding:8px 12px; background:#f8fafc; border-radius:6px; border:1px solid #e2e8f0; }
  .bank-title { font-size:10px; font-weight:700; color:#64748b; margin-bottom:4px; }
  .bank-row { font-size:11px; color:#374151; margin-bottom:2px; }

  /* تذييل */
  .inv-footer { margin-top:20px; border-top:1px solid #e2e8f0; padding-top:10px; display:flex; justify-content:space-between; align-items:center; }
  .inv-footer-text { font-size:10px; color:#94a3b8; }
  .zatca-badge { font-size:9.5px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:3px 10px; border-radius:20px; font-weight:600; }

  @media print {
    body { background:#fff; }
    .page { padding:8mm 10mm; margin:0; width:100%; }
    .no-print { display:none !important; }
  }
</style>
<?= lh_head($settings) ?>
</head>
<body class="<?= lh_class($settings) ?>">
<?= lh_body($settings) ?>

<!-- أدوات الصفحة - تختفي عند الطباعة -->
<div class="no-print" style="background:#0c1b36;padding:10px 20px;display:flex;justify-content:space-between;align-items:center;color:#fff;font-size:13px">
  <div><i class="fas fa-file-invoice" style="margin-left:8px"></i>فاتورة ضريبية — <?= e($inv['invoice_number']) ?></div>
  <div style="display:flex;gap:10px">
    <a href="invoices.php" style="color:#94a3b8;text-decoration:none;font-size:12px">← عودة</a>
    <?php if (!empty($inv['public_token'])): ?>
    <button onclick="var u=location.origin+'/public/invoice_view.php?t=<?= e($inv['public_token']) ?>';navigator.clipboard&&navigator.clipboard.writeText(u);this.textContent='✓ نُسخ رابط العميل'"
            style="background:#1e3a60;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px">
      🔗 نسخ رابط نسخة العميل
    </button>
    <?php endif; ?>
    <button onclick="window.print()" style="background:#e8c040;color:#0c1b36;border:none;padding:6px 16px;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px">
      🖨 طباعة / PDF
    </button>
  </div>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<div class="page">

  <!-- رأس الفاتورة -->
  <div class="inv-head">
    <div class="lh-hide" style="display:flex;align-items:flex-start;gap:12px">
      <?php if (!empty($settings['office_logo'])): ?>
      <img src="../<?= e($settings['office_logo']) ?>" alt="شعار المكتب"
           style="height:60px;max-width:120px;object-fit:contain;border-radius:6px;flex-shrink:0">
      <?php endif; ?>
      <div>
      <div class="inv-logo">
        <?= e($office['name']) ?>
        <small><?= e($settings['address'] ?? '') ?></small>
      </div>
      <?php if ($settings['tax_number'] ?? ''): ?>
      <div style="margin-top:6px;font-size:11px">
        <span style="color:#64748b">الرقم الضريبي:</span>
        <strong style="font-family:monospace"><?= e($settings['tax_number']) ?></strong>
      </div>
      <?php endif; ?>
      <?php if ($settings['cr_number'] ?? ''): ?>
      <div style="font-size:11px">
        <span style="color:#64748b">السجل التجاري:</span>
        <strong style="font-family:monospace"><?= e($settings['cr_number']) ?></strong>
      </div>
      <?php endif; ?>
      </div><!-- /logo+text -->
    </div>
    <div style="text-align:center">
      <div class="inv-type-badge">
        <?= $is_standard ? 'فاتورة ضريبية' : 'فاتورة ضريبية مبسّطة' ?>
      </div>
      <div class="inv-type-en"><?= $is_standard ? 'Tax Invoice' : 'Simplified Tax Invoice' ?></div>
      <div style="margin-top:8px;font-size:13px;font-weight:800;color:#0c1b36"><?= e($inv['invoice_number']) ?></div>
    </div>
  </div>

  <!-- بيانات الفاتورة -->
  <div class="inv-meta">
    <div class="inv-meta-box">
      <div class="inv-meta-title">بيانات الفاتورة — Invoice Details</div>
      <div class="inv-meta-row">
        <span class="lbl">رقم الفاتورة:</span>
        <span class="val"><?= e($inv['invoice_number']) ?></span>
      </div>
      <div class="inv-meta-row">
        <span class="lbl">تاريخ الإصدار:</span>
        <span class="val"><?= $inv['issue_date'] ? date('Y/m/d', strtotime($inv['issue_date'])) : date('Y/m/d') ?></span>
      </div>
      <div class="inv-meta-row">
        <span class="lbl">تاريخ التوريد:</span>
        <span class="val"><?= $inv['supply_date'] ? date('Y/m/d', strtotime($inv['supply_date'])) : date('Y/m/d') ?></span>
      </div>
      <?php if ($inv['due_date']): ?>
      <div class="inv-meta-row">
        <span class="lbl">تاريخ الاستحقاق:</span>
        <span class="val"><?= date('Y/m/d', strtotime($inv['due_date'])) ?></span>
      </div>
      <?php endif; ?>
      <div class="inv-meta-row">
        <span class="lbl">نوع الفاتورة:</span>
        <span class="val-ar"><?= $is_standard ? 'ضريبية (B2B)' : 'مبسّطة (B2C)' ?></span>
      </div>
      <?php if ($inv['uuid'] ?? ''): ?>
      <div class="inv-meta-row">
        <span class="lbl">UUID:</span>
        <span class="val" style="font-size:8.5px"><?= e($inv['uuid']) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <div class="inv-meta-box">
      <div class="inv-meta-title">الحالة والمبالغ — Status & Amounts</div>
      <div class="inv-meta-row">
        <span class="lbl">الحالة:</span>
        <span class="val-ar"><?php
          $s_map = ['draft'=>'مسودة','sent'=>'مُرسلة','paid'=>'مدفوعة','overdue'=>'متأخرة','cancelled'=>'ملغاة'];
          echo $s_map[$inv['status']] ?? $inv['status'];
        ?></span>
      </div>
      <div class="inv-meta-row">
        <span class="lbl">المجموع قبل الضريبة:</span>
        <span class="val"><?= number_format($inv['subtotal'] ?? 0, 2) ?> ر.س</span>
      </div>
      <?php if (($inv['discount'] ?? 0) > 0): ?>
      <div class="inv-meta-row">
        <span class="lbl">الخصم:</span>
        <span class="val" style="color:#dc2626">- <?= number_format($inv['discount'], 2) ?> ر.س</span>
      </div>
      <?php endif; ?>
      <div class="inv-meta-row">
        <span class="lbl">ضريبة القيمة المضافة (<?= $inv['tax_rate'] ?? 15 ?>%):</span>
        <span class="val"><?= number_format($inv['tax_amount'] ?? 0, 2) ?> ر.س</span>
      </div>
      <div class="inv-meta-row" style="font-size:13px">
        <span class="lbl" style="font-weight:800;color:#0c1b36">الإجمالي:</span>
        <span class="val" style="font-weight:900;color:#0c1b36;font-size:14px"><?= number_format($inv['total'] ?? 0, 2) ?> ر.س</span>
      </div>
    </div>
  </div>

  <!-- البائع والمشتري -->
  <div class="parties">
    <div class="party-box">
      <div><span class="party-label">المورد — Supplier</span></div>
      <div class="party-name"><?= e($office['name']) ?></div>
      <?php if ($office['owner_name'] ?? ''): ?>
      <div class="party-detail"><i>المالك:</i> <?= e($office['owner_name']) ?></div>
      <?php endif; ?>
      <?php if ($settings['address'] ?? ''): ?>
      <div class="party-detail"><?= e($settings['address']) ?></div>
      <?php endif; ?>
      <?php if ($office['phone'] ?? ''): ?>
      <div class="party-detail">📞 <?= e($office['phone']) ?></div>
      <?php endif; ?>
      <?php foreach (na_lines($settings) as $nl): ?>
      <div class="party-detail"><i class="fas fa-location-dot" style="color:#0369a1"></i> <?= e($nl) ?></div>
      <?php endforeach; ?>
      <?php if ($settings['tax_number'] ?? ''): ?>
      <div class="party-vat">VAT: <?= e($settings['tax_number']) ?></div>
      <?php endif; ?>
    </div>
    <div class="party-box">
      <div><span class="party-label buyer">العميل — Customer</span></div>
      <?php if ($client): ?>
      <div class="party-name"><?= e($client['full_name']) ?></div>
      <?php if ($client['company'] ?? ''): ?>
      <div class="party-detail"><?= e($client['company']) ?></div>
      <?php endif; ?>
      <?php if ($client['address'] ?? ''): ?>
      <div class="party-detail"><?= e($client['address']) ?></div>
      <?php endif; ?>
      <?php if ($client['phone'] ?? ''): ?>
      <div class="party-detail">📞 <?= e($client['phone']) ?></div>
      <?php endif; ?>
      <?php foreach (na_lines($client) as $nl): ?>
      <div class="party-detail"><i class="fas fa-location-dot" style="color:#0369a1"></i> <?= e($nl) ?></div>
      <?php endforeach; ?>
      <?php if (($inv['buyer_vat'] ?? '') || ($client['vat_number'] ?? '')): ?>
      <div class="party-vat">VAT: <?= e($inv['buyer_vat'] ?? $client['vat_number']) ?></div>
      <?php endif; ?>
      <?php elseif ($inv['buyer_vat'] ?? ''): ?>
      <div class="party-name">عميل نقدي</div>
      <div class="party-vat">VAT: <?= e($inv['buyer_vat']) ?></div>
      <?php else: ?>
      <div class="party-name">عميل نقدي / Cash Customer</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- عنوان الفاتورة -->
  <div style="margin-bottom:10px;padding:8px 12px;background:#f8fafc;border-radius:6px;border-right:3px solid #0c1b36">
    <span style="font-size:10px;color:#64748b">الموضوع: </span>
    <strong style="font-size:13px"><?= e($inv['title']) ?></strong>
  </div>

  <!-- بنود الفاتورة -->
  <table class="items-table">
    <thead>
      <tr>
        <th class="num" style="width:36px">#</th>
        <th>البيان / Description</th>
        <th style="width:60px;text-align:center">الكمية</th>
        <th style="width:100px;text-align:left;direction:ltr">سعر الوحدة</th>
        <th style="width:80px;text-align:left;direction:ltr">الضريبة</th>
        <th style="width:110px;text-align:left;direction:ltr">الإجمالي</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $tax_rate = (float)($inv['tax_rate'] ?? 15);
    foreach ($items as $i => $item):
        $line_sub = (float)$item['qty'] * (float)$item['price'];
        $line_vat = round($line_sub * $tax_rate / 100, 2);
        $line_tot = $line_sub + $line_vat;
    ?>
    <tr>
      <td class="num"><?= $i+1 ?></td>
      <td><?= e($item['description']) ?></td>
      <td style="text-align:center"><?= e($item['qty']) ?></td>
      <td class="amount"><?= number_format((float)$item['price'], 2) ?></td>
      <td class="amount" style="color:#92400e"><?= number_format($line_vat, 2) ?></td>
      <td class="amount"><?= number_format($line_tot, 2) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <?php $subtotal_after_disc = (float)$inv['subtotal'] - (float)($inv['discount'] ?? 0); ?>
      <tr>
        <td colspan="5" class="label" style="color:#64748b;font-size:11px">المجموع الفرعي قبل الضريبة / Subtotal excl. VAT</td>
        <td class="value"><?= number_format((float)$inv['subtotal'], 2) ?> ر.س</td>
      </tr>
      <?php if (($inv['discount'] ?? 0) > 0): ?>
      <tr>
        <td colspan="5" class="label" style="color:#dc2626;font-size:11px">الخصم / Discount</td>
        <td class="value" style="color:#dc2626">- <?= number_format((float)$inv['discount'], 2) ?> ر.س</td>
      </tr>
      <?php endif; ?>
      <tr class="vat-row">
        <td colspan="5" class="label">
          ضريبة القيمة المضافة <?= $tax_rate ?>% / VAT <?= $tax_rate ?>%
          <span style="font-size:10px;margin-right:6px">(رقم الضريبة: <?= e($settings['tax_number'] ?? 'غير محدد') ?>)</span>
        </td>
        <td class="value" style="font-weight:800"><?= number_format((float)$inv['tax_amount'], 2) ?> ر.س</td>
      </tr>
      <tr class="total-row">
        <td colspan="5" class="label">الإجمالي شامل الضريبة / Total incl. VAT</td>
        <td class="value" style="font-size:14px"><?= number_format((float)$inv['total'], 2) ?> ر.س</td>
      </tr>
    </tfoot>
  </table>

  <!-- QR + ملاحظات + بنك -->
  <div class="inv-bottom">
    <div class="notes-section">
      <?php if ($inv['notes'] ?? ''): ?>
      <div class="notes-title">ملاحظات / Notes:</div>
      <div class="notes-text"><?= nl2br(e($inv['notes'])) ?></div>
      <?php endif; ?>

      <?php if (($settings['bank_name'] ?? '') || ($settings['bank_iban'] ?? '')): ?>
      <div class="bank-section" style="margin-top:10px">
        <div class="bank-title">بيانات التحويل البنكي / Bank Transfer Details</div>
        <?php if ($settings['bank_name'] ?? ''): ?>
        <div class="bank-row"><strong>البنك:</strong> <?= e($settings['bank_name']) ?></div>
        <?php endif; ?>
        <?php if ($settings['bank_iban'] ?? ''): ?>
        <div class="bank-row"><strong>IBAN:</strong> <span style="font-family:monospace;direction:ltr"><?= e($settings['bank_iban']) ?></span></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($settings['invoice_footer'] ?? ''): ?>
      <div style="margin-top:10px;font-size:11px;color:#64748b;font-style:italic"><?= nl2br(e($settings['invoice_footer'])) ?></div>
      <?php endif; ?>
    </div>

    <?php if ($qr_data): ?>
    <div class="qr-section">
      <div id="qrcode"></div>
      <div class="qr-label">امسح لتحقق ZATCA<br>QR Code</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- تذييل -->
  <div class="inv-footer">
    <div class="inv-footer-text">
      تم إنشاء هذه الفاتورة إلكترونياً وفق متطلبات هيئة الزكاة والضريبة والجمارك
      <br>This invoice was electronically generated per ZATCA requirements
    </div>
    <div class="zatca-badge">✓ متوافق مع ZATCA — Phase 1</div>
  </div>

</div>

<?php if ($qr_data): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  new QRCode(document.getElementById('qrcode'), {
    text: <?= json_encode($qr_data) ?>,
    width: 100, height: 100,
    colorDark: '#0c1b36', colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
  });
});
</script>
<?php endif; ?>

<?= lh_foot($settings) ?>
</body>
</html>
