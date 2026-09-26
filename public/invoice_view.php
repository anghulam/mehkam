<?php
/**
 * public/invoice_view.php — نسخة العميل من الفاتورة (بدون تسجيل دخول)
 * الوصول عبر رمز غير قابل للتخمين: invoice_view.php?t=<public_token>
 * تعرض بيانات الفاتورة الأساسية ورمز QR المتوافق مع ZATCA.
 */
require_once '../config/db.php';
require_once '../includes/zatca_helper.php';

$tok = preg_replace('/[^a-f0-9]/i', '', $_GET['t'] ?? '');
$inv = null;
if (strlen($tok) >= 16) {
    $stmt = $conn->prepare("SELECT * FROM invoices WHERE public_token=? LIMIT 1");
    $stmt->bind_param('s', $tok);
    $stmt->execute();
    $inv = $stmt->get_result()->fetch_assoc();
}
if (!$inv) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:Tahoma;text-align:center;margin-top:80px;color:#64748b">الفاتورة غير موجودة أو الرابط غير صحيح.</div>';
    exit;
}

$oid      = (int) $inv['office_id'];
$office   = $conn->query("SELECT name, phone, owner_name FROM offices WHERE id=$oid")->fetch_assoc() ?: [];
$settings = $conn->query("SELECT tax_number, cr_number, address, office_logo, invoice_footer FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?: [];
$client   = $inv['client_id'] ? $conn->query("SELECT full_name, phone, na_building, na_street, na_district, na_city, na_postal, na_additional, na_short FROM clients WHERE id=" . (int) $inv['client_id'])->fetch_assoc() : null;
$na_client = '';
if ($client) {
    $g = fn($k) => trim((string)($client[$k] ?? ''));
    $p1 = trim($g('na_building') . ' ' . $g('na_street'));
    $p2 = implode('، ', array_filter([$g('na_district'), trim($g('na_city') . ' ' . $g('na_postal'))]));
    $na_client = implode('، ', array_filter([$p1, $p2, $g('na_short') ? 'العنوان المختصر: ' . $g('na_short') : '']));
}

$qr_data = $inv['qr_data'] ?? '';
if (!$qr_data && !empty($settings['tax_number'])) {
    $qr_data = zatca_qr($office['name'] ?? '', $settings['tax_number'], (float) $inv['total'], (float) $inv['tax_amount'],
        ($inv['issue_date'] ?? date('Y-m-d')) . 'T00:00:00Z');
}
$items = json_decode($inv['items'] ?? '[]', true) ?: [];
$logo  = $settings['office_logo'] ?? '';
$s_map = ['draft' => 'مسودة', 'sent' => 'مُرسلة', 'paid' => 'مدفوعة', 'overdue' => 'متأخرة', 'cancelled' => 'ملغاة'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>فاتورة <?= htmlspecialchars($inv['invoice_number']) ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Segoe UI',Tahoma,Arial,sans-serif;background:#eef2f7;color:#1e293b;direction:rtl;padding:20px;line-height:1.7}
  .card{max-width:620px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 6px 30px rgba(0,0,0,.08);overflow:hidden}
  .hd{background:linear-gradient(135deg,#0c1b36,#1e3a60);color:#fff;padding:20px 24px;display:flex;justify-content:space-between;align-items:center;gap:16px}
  .hd img{height:52px;max-width:120px;object-fit:contain;background:#fff;border-radius:8px;padding:4px}
  .hd h1{font-size:18px;font-weight:800}
  .hd small{opacity:.7;font-size:12px}
  .bd{padding:22px 24px}
  .row2{display:flex;justify-content:space-between;font-size:13.5px;padding:7px 0;border-bottom:1px solid #f1f5f9}
  .row2 span:first-child{color:#64748b}
  .row2 span:last-child{font-weight:600}
  .tot{background:#0c1b36;color:#fff;border-radius:10px;padding:12px 16px;display:flex;justify-content:space-between;font-size:17px;font-weight:800;margin-top:14px}
  .items{width:100%;border-collapse:collapse;margin:14px 0;font-size:12.5px}
  .items th{background:#f1f5f9;padding:7px 8px;text-align:right}
  .items td{padding:7px 8px;border-bottom:1px solid #f1f5f9}
  .qrwrap{text-align:center;margin-top:18px;padding-top:16px;border-top:1px dashed #cbd5e1}
  .qrwrap #qr{display:inline-block;padding:6px;border:1px solid #e2e8f0;border-radius:8px}
  .qrwrap div{font-size:10.5px;color:#94a3b8;margin-top:6px}
  .badge{display:inline-block;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700}
  .badge.paid{background:#dcfce7;color:#166534}.badge.sent{background:#dbeafe;color:#1e40af}
  .badge.overdue{background:#fee2e2;color:#991b1b}.badge.draft,.badge.cancelled{background:#f1f5f9;color:#64748b}
  .ft{font-size:11px;color:#94a3b8;text-align:center;padding:14px 24px;border-top:1px solid #f1f5f9}
</style>
</head>
<body>
<div class="card">
  <div class="hd">
    <div>
      <h1><?= htmlspecialchars($office['name'] ?? 'فاتورة') ?></h1>
      <small><?= htmlspecialchars($settings['address'] ?? '') ?></small>
      <?php if (!empty($settings['tax_number'])): ?><br><small>الرقم الضريبي: <?= htmlspecialchars($settings['tax_number']) ?></small><?php endif; ?>
    </div>
    <?php if ($logo && file_exists('../' . $logo)): ?><img src="../<?= htmlspecialchars($logo) ?>" alt=""><?php endif; ?>
  </div>
  <div class="bd">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <div style="font-size:16px;font-weight:800">فاتورة <?= htmlspecialchars($inv['invoice_number']) ?></div>
      <span class="badge <?= htmlspecialchars($inv['status']) ?>"><?= $s_map[$inv['status']] ?? $inv['status'] ?></span>
    </div>

    <div class="row2"><span>الموضوع</span><span><?= htmlspecialchars($inv['title']) ?></span></div>
    <?php if ($client): ?><div class="row2"><span>العميل</span><span><?= htmlspecialchars($client['full_name']) ?></span></div><?php endif; ?>
    <?php if (!empty($client['phone'])): ?><div class="row2"><span>جوال العميل</span><span dir="ltr"><?= htmlspecialchars($client['phone']) ?></span></div><?php endif; ?>
    <?php if ($na_client): ?><div class="row2"><span>العنوان الوطني</span><span><?= htmlspecialchars($na_client) ?></span></div><?php endif; ?>
    <div class="row2"><span>تاريخ الإصدار</span><span><?= htmlspecialchars($inv['issue_date'] ?: date('Y-m-d', strtotime($inv['created_at']))) ?></span></div>
    <?php if ($inv['due_date']): ?><div class="row2"><span>تاريخ الاستحقاق</span><span><?= htmlspecialchars($inv['due_date']) ?></span></div><?php endif; ?>

    <?php if ($items): ?>
    <table class="items">
      <thead><tr><th>البيان</th><th>الكمية</th><th>السعر</th><th>الإجمالي</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= htmlspecialchars($it['description'] ?? '') ?></td>
          <td><?= htmlspecialchars($it['qty'] ?? 1) ?></td>
          <td><?= number_format((float)($it['price'] ?? 0), 2) ?></td>
          <td><?= number_format((float)($it['qty'] ?? 1) * (float)($it['price'] ?? 0), 2) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <div class="row2"><span>المجموع قبل الضريبة</span><span><?= number_format((float)$inv['subtotal'], 2) ?> ر.س</span></div>
    <?php if ((float)$inv['discount'] > 0): ?>
    <div class="row2"><span>الخصم<?= ($inv['discount_type'] ?? '') === 'percent' ? ' (' . rtrim(rtrim(number_format((float)$inv['discount_value'], 2), '0'), '.') . '%)' : '' ?></span><span style="color:#dc2626">− <?= number_format((float)$inv['discount'], 2) ?> ر.س</span></div>
    <?php endif; ?>
    <div class="row2"><span>ضريبة القيمة المضافة (<?= rtrim(rtrim(number_format((float)$inv['tax_rate'], 2), '0'), '.') ?>%)</span><span><?= number_format((float)$inv['tax_amount'], 2) ?> ر.س</span></div>
    <div class="tot"><span>الإجمالي</span><span><?= number_format((float)$inv['total'], 2) ?> ر.س</span></div>

    <?php if ($qr_data): ?>
    <div class="qrwrap">
      <div id="qr"></div>
      <div>رمز الاستجابة السريعة للتحقق — ZATCA</div>
    </div>
    <?php endif; ?>
  </div>
  <div class="ft">
    <?= htmlspecialchars($settings['invoice_footer'] ?? '') ?>
    <?php if (!empty($settings['invoice_footer'])): ?><br><?php endif; ?>
    هذه نسخة إلكترونية من الفاتورة — مُولّدة عبر منصة مِحكام
  </div>
</div>

<?php if ($qr_data): ?>
<script>
new QRCode(document.getElementById('qr'), {
  text: <?= json_encode($qr_data) ?>,
  width: 128, height: 128,
  colorDark: '#0c1b36', colorLight: '#ffffff',
  correctLevel: QRCode.CorrectLevel.M
});
</script>
<?php endif; ?>
</body>
</html>
