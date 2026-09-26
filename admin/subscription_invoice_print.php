<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/zatca_helper.php';
requireAdmin();

$id   = (int)($_GET['id'] ?? 0);
$sinv = $conn->query("SELECT * FROM subscription_invoices WHERE id=$id")->fetch_assoc();
if (!$sinv) { header("Location: subscription_invoices.php"); exit; }

$is_standard = ($sinv['invoice_type'] === 'standard');
$billing_lbl = ($sinv['billing_period'] === 'yearly') ? 'سنوي' : 'شهري';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>فاتورة اشتراك <?= e($sinv['invoice_number']) ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',Tahoma,Arial,sans-serif; font-size:12px; color:#1a1a1a; background:#fff; direction:rtl; }
.page { width:210mm; min-height:297mm; margin:0 auto; padding:12mm 14mm; }

.inv-head { display:flex; justify-content:space-between; align-items:flex-start; padding-bottom:14px; margin-bottom:16px; border-bottom:3px solid #0c1b36; }
.logo-name { font-size:26px; font-weight:900; color:#0c1b36; }
.logo-sub  { font-size:11px; color:#64748b; margin-top:2px; }
.inv-badge { background:#0c1b36; color:#e8c040; padding:5px 16px; border-radius:20px; font-size:12px; font-weight:700; text-align:center; }
.inv-badge small { display:block; color:#94a3b8; font-size:9.5px; font-weight:400; margin-top:2px; }
.inv-num { font-size:15px; font-weight:900; color:#0c1b36; text-align:center; margin-top:6px; font-family:monospace; }

.meta-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
.meta-box  { border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; }
.meta-ttl  { font-size:9.5px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; border-bottom:1px solid #f1f5f9; padding-bottom:5px; margin-bottom:7px; }
.meta-row  { display:flex; justify-content:space-between; margin-bottom:4px; font-size:11.5px; gap:8px; }
.meta-row .l { color:#64748b; flex-shrink:0; }
.meta-row .v { font-weight:600; text-align:left; direction:ltr; }

.parties { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
.party { border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; }
.party-lbl { display:inline-block; font-size:10px; font-weight:700; color:#fff; padding:3px 10px; border-radius:4px; margin-bottom:8px; }
.party-name { font-size:14px; font-weight:800; color:#0c1b36; margin-bottom:4px; }
.party-line { font-size:11px; color:#64748b; margin-bottom:2px; }
.vat-tag { font-size:11px; background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:4px; display:inline-block; margin-top:4px; font-weight:700; }

.inv-table { width:100%; border-collapse:collapse; margin-bottom:16px; font-size:12px; }
.inv-table thead tr { background:#0c1b36; color:#fff; }
.inv-table th { padding:8px 12px; text-align:right; font-weight:600; }
.inv-table td { padding:8px 12px; border-bottom:1px solid #f1f5f9; }
.inv-table tbody tr:nth-child(even) { background:#f8fafc; }
.inv-table .num { text-align:center; }
.inv-table .rtl { text-align:left; direction:ltr; font-weight:600; }

tfoot .sub-row td { background:#f8fafc; }
tfoot .vat-row td { background:#fef3c7; color:#92400e; font-weight:700; }
tfoot .tot-row td { background:#0c1b36; color:#fff; font-size:14px; font-weight:900; }

.bottom { display:flex; justify-content:space-between; align-items:flex-start; margin-top:16px; }
.qr-wrap { text-align:center; }
.qr-lbl { font-size:9px; color:#64748b; margin-top:4px; }
.notes-wrap { flex:1; margin-right:20px; }
.notes-ttl { font-size:10px; font-weight:700; color:#64748b; margin-bottom:4px; }

.inv-footer { margin-top:20px; border-top:1px solid #e2e8f0; padding-top:10px; display:flex; justify-content:space-between; align-items:center; }
.footer-txt { font-size:9.5px; color:#94a3b8; line-height:1.6; }
.zatca-seal { font-size:10px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:4px 12px; border-radius:20px; font-weight:700; }

@media print {
  body { background:#fff; }
  .page { padding:8mm 10mm; margin:0; width:100%; }
  .no-print { display:none !important; }
}
</style>
</head>
<body>

<div class="no-print" style="background:#0c1b36;padding:10px 20px;display:flex;justify-content:space-between;align-items:center">
  <span style="color:#e8c040;font-weight:700;font-size:13px">
    <i class="fas fa-file-invoice-dollar" style="margin-left:8px"></i>فاتورة اشتراك — <?= e($sinv['invoice_number']) ?>
  </span>
  <div style="display:flex;gap:10px">
    <a href="subscription_invoices.php" style="color:#94a3b8;text-decoration:none;font-size:12px">← عودة</a>
    <button onclick="window.print()" style="background:#e8c040;color:#0c1b36;border:none;padding:6px 16px;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px">
      🖨 طباعة / PDF
    </button>
  </div>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<div class="page">

  <!-- رأس الفاتورة -->
  <div class="inv-head">
    <div>
      <div class="logo-name"><?= e($sinv['seller_name']) ?></div>
      <?php if ($sinv['seller_address']): ?>
      <div class="logo-sub"><?= e($sinv['seller_address']) ?></div>
      <?php endif; ?>
      <?php if ($sinv['seller_vat']): ?>
      <div style="font-size:11px;margin-top:4px">
        <span style="color:#64748b">الرقم الضريبي:</span>
        <strong style="font-family:monospace"><?= e($sinv['seller_vat']) ?></strong>
      </div>
      <?php endif; ?>
      <?php if ($sinv['seller_cr']): ?>
      <div style="font-size:11px">
        <span style="color:#64748b">السجل التجاري:</span>
        <strong style="font-family:monospace"><?= e($sinv['seller_cr']) ?></strong>
      </div>
      <?php endif; ?>
    </div>
    <div>
      <div class="inv-badge">
        <?= $is_standard ? 'فاتورة ضريبية' : 'فاتورة ضريبية مبسّطة' ?>
        <small><?= $is_standard ? 'Tax Invoice' : 'Simplified Tax Invoice' ?></small>
      </div>
      <div class="inv-num"><?= e($sinv['invoice_number']) ?></div>
    </div>
  </div>

  <!-- بيانات الفاتورة -->
  <div class="meta-grid">
    <div class="meta-box">
      <div class="meta-ttl">بيانات الفاتورة — Invoice Details</div>
      <div class="meta-row"><span class="l">رقم الفاتورة:</span><span class="v"><?= e($sinv['invoice_number']) ?></span></div>
      <div class="meta-row"><span class="l">تاريخ الإصدار:</span><span class="v"><?= date('Y/m/d', strtotime($sinv['issue_date'])) ?></span></div>
      <div class="meta-row"><span class="l">تاريخ التوريد:</span><span class="v"><?= date('Y/m/d', strtotime($sinv['supply_date'])) ?></span></div>
      <div class="meta-row"><span class="l">دورة الفوترة:</span><span class="v" style="direction:rtl"><?= $billing_lbl ?></span></div>
      <div class="meta-row"><span class="l">نوع الفاتورة:</span><span class="v" style="direction:rtl"><?= $is_standard ? 'ضريبية (B2B)' : 'مبسّطة (B2C)' ?></span></div>
      <?php if ($sinv['uuid']): ?>
      <div class="meta-row"><span class="l">UUID:</span><span class="v" style="font-size:8.5px"><?= e($sinv['uuid']) ?></span></div>
      <?php endif; ?>
    </div>
    <div class="meta-box">
      <div class="meta-ttl">ملخص المبالغ — Amount Summary</div>
      <div class="meta-row"><span class="l">المبلغ قبل الضريبة:</span><span class="v"><?= number_format($sinv['subtotal'], 2) ?> ر.س</span></div>
      <?php if ($sinv['discount'] > 0): ?>
      <div class="meta-row"><span class="l">الخصم:</span><span class="v" style="color:#dc2626">- <?= number_format($sinv['discount'], 2) ?> ر.س</span></div>
      <?php endif; ?>
      <div class="meta-row" style="background:#fef3c7;border-radius:4px;padding:3px 6px;margin:2px 0">
        <span class="l" style="color:#92400e">ضريبة القيمة المضافة <?= $sinv['tax_rate'] ?>%:</span>
        <span class="v" style="color:#92400e;font-weight:800"><?= number_format($sinv['tax_amount'], 2) ?> ر.س</span>
      </div>
      <div class="meta-row" style="font-size:14px">
        <span class="l" style="font-weight:800;color:#0c1b36">الإجمالي:</span>
        <span class="v" style="font-weight:900;color:#0c1b36;font-size:16px"><?= number_format($sinv['total'], 2) ?> ر.س</span>
      </div>
    </div>
  </div>

  <!-- الأطراف -->
  <div class="parties">
    <div class="party">
      <span class="party-lbl" style="background:#0c1b36">المورد — Supplier</span>
      <div class="party-name"><?= e($sinv['seller_name']) ?></div>
      <?php if ($sinv['seller_address']): ?><div class="party-line"><?= e($sinv['seller_address']) ?></div><?php endif; ?>
      <?php if ($sinv['seller_vat']): ?><div class="vat-tag">VAT: <?= e($sinv['seller_vat']) ?></div><?php endif; ?>
    </div>
    <div class="party">
      <span class="party-lbl" style="background:#1a3a6e">العميل — Customer</span>
      <div class="party-name"><?= e($sinv['buyer_name'] ?: '—') ?></div>
      <?php if ($sinv['buyer_address']): ?><div class="party-line"><?= e($sinv['buyer_address']) ?></div><?php endif; ?>
      <?php if ($sinv['buyer_vat']): ?><div class="vat-tag">VAT: <?= e($sinv['buyer_vat']) ?></div><?php endif; ?>
      <?php if ($sinv['buyer_cr']): ?><div class="vat-tag" style="background:#e0f2fe;color:#0369a1">CR: <?= e($sinv['buyer_cr']) ?></div><?php endif; ?>
    </div>
  </div>

  <!-- بنود الفاتورة -->
  <table class="inv-table">
    <thead>
      <tr>
        <th class="num" style="width:36px">#</th>
        <th>البيان / Description</th>
        <th style="width:80px;text-align:center">الكمية</th>
        <th style="width:110px;text-align:left;direction:ltr">سعر الوحدة</th>
        <th style="width:90px;text-align:left;direction:ltr">الضريبة 15%</th>
        <th style="width:110px;text-align:left;direction:ltr">الإجمالي</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $desc = 'اشتراك ' . e($sinv['package_name'] ?? '') . ' — ' . $billing_lbl;
      $vat_line = round($sinv['subtotal'] * 0.15, 2);
      $tot_line = $sinv['subtotal'] + $vat_line;
      ?>
      <tr>
        <td class="num">1</td>
        <td><?= $desc ?><br><span style="font-size:10px;color:#64748b">فترة: <?= date('Y/m/d', strtotime($sinv['supply_date'])) ?> — <?= ($sinv['billing_period']==='yearly') ? date('Y/m/d', strtotime($sinv['supply_date'].' +1 year')) : date('Y/m/d', strtotime($sinv['supply_date'].' +1 month')) ?></span></td>
        <td class="num">1</td>
        <td class="rtl"><?= number_format($sinv['subtotal'], 2) ?> ر.س</td>
        <td class="rtl" style="color:#92400e"><?= number_format($sinv['tax_amount'], 2) ?> ر.س</td>
        <td class="rtl"><?= number_format($sinv['total'], 2) ?> ر.س</td>
      </tr>
    </tbody>
    <tfoot>
      <tr class="sub-row">
        <td colspan="5" style="text-align:right;color:#64748b;font-size:11px">المجموع الفرعي قبل الضريبة / Subtotal excl. VAT</td>
        <td class="rtl"><?= number_format($sinv['subtotal'], 2) ?> ر.س</td>
      </tr>
      <tr class="vat-row">
        <td colspan="5" style="text-align:right">
          ضريبة القيمة المضافة <?= $sinv['tax_rate'] ?>% / VAT
          <?php if ($sinv['seller_vat']): ?><span style="font-size:10px;font-weight:400;margin-right:6px">(VAT No: <?= e($sinv['seller_vat']) ?>)</span><?php endif; ?>
        </td>
        <td class="rtl"><?= number_format($sinv['tax_amount'], 2) ?> ر.س</td>
      </tr>
      <tr class="tot-row">
        <td colspan="5" style="text-align:right">الإجمالي شامل الضريبة / Total incl. VAT</td>
        <td class="rtl"><?= number_format($sinv['total'], 2) ?> ر.س</td>
      </tr>
    </tfoot>
  </table>

  <!-- QR + ملاحظات -->
  <div class="bottom">
    <div class="notes-wrap">
      <?php if ($sinv['notes']): ?>
      <div class="notes-ttl">ملاحظات / Notes:</div>
      <div style="font-size:11px;color:#374151;line-height:1.7"><?= nl2br(e($sinv['notes'])) ?></div>
      <?php endif; ?>
      <div style="margin-top:10px;padding:10px 14px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;font-size:11px">
        <div style="font-weight:700;color:#0c1b36;margin-bottom:6px">معلومات الدفع / Payment Info</div>
        <div style="color:#64748b">يُرجى الإشارة إلى رقم الفاتورة <strong><?= e($sinv['invoice_number']) ?></strong> عند التحويل</div>
        <div style="color:#64748b;margin-top:2px">Please reference invoice number when making payment</div>
      </div>
    </div>

    <?php if ($sinv['qr_data']): ?>
    <div class="qr-wrap">
      <div id="qrcode"></div>
      <div class="qr-lbl">امسح للتحقق<br>QR Code — ZATCA</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- تذييل -->
  <div class="inv-footer">
    <div class="footer-txt">
      تم إنشاء هذه الفاتورة إلكترونياً وفق متطلبات هيئة الزكاة والضريبة والجمارك — المرحلة الأولى (التوليد)<br>
      This invoice was electronically generated per ZATCA e-invoicing requirements — Phase 1 (Generation)
    </div>
    <div class="zatca-seal">✓ ZATCA Compliant — Phase 1</div>
  </div>

</div>

<?php if ($sinv['qr_data']): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  new QRCode(document.getElementById('qrcode'), {
    text: atob('<?= $sinv['qr_data'] ?>'),
    width: 110, height: 110,
    colorDark: '#0c1b36', colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
  });
});
</script>
<?php endif; ?>
</body>
</html>
