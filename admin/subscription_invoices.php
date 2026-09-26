<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/zatca_helper.php';
requireAdmin();
$page_title = 'فواتير الاشتراك';

// تهيئة جداول ZATCA
zatca_migrate($conn);

// ── إعدادات المنصة (البائع)
function platform_vat($conn) {
    $r = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='platform_vat' LIMIT 1");
    return $r ? ($r->fetch_assoc()['setting_value'] ?? '') : '';
}
function platform_cr($conn) {
    $r = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='platform_cr' LIMIT 1");
    return $r ? ($r->fetch_assoc()['setting_value'] ?? '') : '';
}

// ── حذف فاتورة
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $conn->query("DELETE FROM subscription_invoices WHERE id=".(int)$_GET['delete']);
    header("Location: subscription_invoices.php?msg=deleted"); exit;
}

// ── توليد فاتورة يدوياً لدفعة موجودة
if (isset($_GET['gen']) && is_numeric($_GET['gen'])) {
    $rev_id = (int)$_GET['gen'];
    $rev = $conn->query("
        SELECT pr.*, o.name office_name, o.phone office_phone, o.email office_email,
               p.name pkg_name
        FROM platform_revenue pr
        LEFT JOIN offices o ON pr.office_id = o.id
        LEFT JOIN packages p ON pr.package_id = p.id
        WHERE pr.id=$rev_id
    ")->fetch_assoc();

    if ($rev && $rev['office_id']) {
        // هل صدرت فاتورة لهذه الدفعة مسبقاً؟
        $exists = $conn->query("SELECT id FROM subscription_invoices WHERE revenue_id=$rev_id")->num_rows;
        if (!$exists) {
            $oid  = (int)$rev['office_id'];
            $os   = $conn->query("SELECT * FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?? [];
            $sn   = sc($conn,'site_name','مِحكام');
            $pvat = platform_vat($conn);
            $pcr  = platform_cr($conn);
            $paddr = sc($conn,'contact_address','الرياض، المملكة العربية السعودية');

            $subtotal = round((float)$rev['amount'] / 1.15, 2);
            $tax_amt  = round((float)$rev['amount'] - $subtotal, 2);
            $total    = (float)$rev['amount'];
            $billing  = $rev['billing_period'] ?? 'monthly';
            $inv_type = ($os['tax_number'] ?? '') ? 'standard' : 'simplified';

            $qr = $pvat ? zatca_qr($sn, $pvat, $total, $tax_amt, ($rev['payment_date']??date('Y-m-d')).'T00:00:00Z') : '';

            $inv_num  = $conn->real_escape_string(next_sub_invoice_num($conn));
            $uuid     = $conn->real_escape_string(zatca_uuid());
            $s_name   = $conn->real_escape_string($sn);
            $s_vat    = $conn->real_escape_string($pvat);
            $s_cr     = $conn->real_escape_string($pcr);
            $s_addr   = $conn->real_escape_string($paddr);
            $b_name   = $conn->real_escape_string($rev['office_name'] ?? '');
            $b_vat    = $conn->real_escape_string($os['tax_number'] ?? '');
            $b_cr     = $conn->real_escape_string($os['cr_number'] ?? '');
            $b_addr   = $conn->real_escape_string($os['address'] ?? '');
            $pkg      = $conn->real_escape_string($rev['pkg_name'] ?? 'اشتراك');
            $notes    = $conn->real_escape_string($rev['notes'] ?? '');
            $idate    = $conn->real_escape_string($rev['payment_date'] ?? date('Y-m-d'));
            $qr_esc   = $conn->real_escape_string($qr);
            $itype    = $conn->real_escape_string($inv_type);
            $bill_esc = $conn->real_escape_string($billing);

            $conn->query("INSERT INTO subscription_invoices
                (invoice_number,uuid,office_id,revenue_id,invoice_type,
                 issue_date,supply_date,
                 seller_name,seller_vat,seller_cr,seller_address,
                 buyer_name,buyer_vat,buyer_cr,buyer_address,
                 package_name,billing_period,
                 subtotal,discount,tax_rate,tax_amount,total,
                 qr_data,notes)
                VALUES
                ('$inv_num','$uuid',$oid,$rev_id,'$itype',
                 '$idate','$idate',
                 '$s_name','$s_vat','$s_cr','$s_addr',
                 '$b_name','$b_vat','$b_cr','$b_addr',
                 '$pkg','$bill_esc',
                 $subtotal,0,15.00,$tax_amt,$total,
                 '$qr_esc','$notes')");
            header("Location: subscription_invoices.php?msg=generated&inv=".$inv_num); exit;
        }
    }
    header("Location: subscription_invoices.php?msg=exists"); exit;
}

// ── طباعة فاتورة
if (isset($_GET['print']) && is_numeric($_GET['print'])) {
    $sinv = $conn->query("SELECT * FROM subscription_invoices WHERE id=".(int)$_GET['print'])->fetch_assoc();
    if (!$sinv) { header("Location: subscription_invoices.php"); exit; }
    // تمرير للصفحة المنفصلة
    header("Location: subscription_invoice_print.php?id=".(int)$_GET['print']); exit;
}

// ── إحصائيات
$total_count  = (int)$conn->query("SELECT COUNT(*) c FROM subscription_invoices")->fetch_assoc()['c'];
$total_amount = (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM subscription_invoices")->fetch_assoc()['s'];
$month_amount = (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM subscription_invoices WHERE MONTH(issue_date)=MONTH(CURDATE()) AND YEAR(issue_date)=YEAR(CURDATE())")->fetch_assoc()['s'];
$no_invoice   = (int)$conn->query("SELECT COUNT(*) c FROM platform_revenue pr WHERE pr.office_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM subscription_invoices si WHERE si.revenue_id=pr.id)")->fetch_assoc()['c'];

// ── قائمة الفواتير
$where = "1=1";
if (!empty($_GET['q'])) {
    $q = $conn->real_escape_string($_GET['q']);
    $where .= " AND (si.invoice_number LIKE '%$q%' OR si.buyer_name LIKE '%$q%')";
}
$sinvs = $conn->query("
    SELECT si.*, o.name office_name_live
    FROM subscription_invoices si
    LEFT JOIN offices o ON si.office_id = o.id
    WHERE $where
    ORDER BY si.created_at DESC
");

// ── دفعات بدون فواتير
$ungenerated = $conn->query("
    SELECT pr.*, o.name office_name, p.name pkg_name
    FROM platform_revenue pr
    LEFT JOIN offices o ON pr.office_id = o.id
    LEFT JOIN packages p ON pr.package_id = p.id
    WHERE pr.office_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM subscription_invoices si WHERE si.revenue_id = pr.id)
    ORDER BY pr.payment_date DESC
    LIMIT 20
");

include '../includes/admin_header.php';
?>

<?php
$msgs = [
  'generated' => ['success','check-circle','تم توليد فاتورة '.e($_GET['inv']??'').' بنجاح'],
  'deleted'   => ['warning','trash-alt','تم حذف الفاتورة'],
  'exists'    => ['info','info-circle','فاتورة لهذه الدفعة موجودة مسبقاً'],
];
if (isset($_GET['msg']) && isset($msgs[$_GET['msg']])):
  [$ac,$ai,$at] = $msgs[$_GET['msg']];
?>
<div class="alert alert-<?=$ac?> alert-dismissible fade show d-flex align-items-center gap-2 py-2">
  <i class="fas fa-<?=$ai?>"></i><div><?=$at?></div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- رأس الصفحة -->
<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h5 class="mb-0 fw-bold"><i class="fas fa-file-invoice-dollar me-2 text-warning"></i>فواتير الاشتراك</h5>
    <small class="text-muted">فواتير ضريبية ZATCA صادرة عن المنصة للمكاتب</small>
  </div>
  <a href="settings.php#zatca" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-cog me-1"></i>إعدادات ZATCA
  </a>
</div>

<!-- إحصائيات -->
<div class="row g-3 mb-4">
  <?php foreach([
    ['إجمالي الفواتير', $total_count,               'file-invoice-dollar', 'primary', '#eff6ff'],
    ['إجمالي المبالغ',  number_format($total_amount).' ر.س', 'coins',    'warning', '#fefce8'],
    ['هذا الشهر',       number_format($month_amount).' ر.س', 'calendar', 'success', '#f0fdf4'],
    ['دفعات بدون فاتورة',$no_invoice,               'exclamation-triangle','danger',  '#fef2f2'],
  ] as [$lbl,$val,$ic,$col,$bg]): ?>
  <div class="col-6 col-lg-3">
    <div class="card" style="border:none;box-shadow:0 2px 12px rgba(0,0,0,.06)">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center text-<?=$col?>"
             style="width:48px;height:48px;font-size:20px;flex-shrink:0;background:<?=$bg?>">
          <i class="fas fa-<?=$ic?>"></i>
        </div>
        <div>
          <div class="text-muted" style="font-size:11px;margin-bottom:2px"><?=$lbl?></div>
          <div class="fw-bold" style="font-size:17px"><?=$val?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- دفعات بدون فاتورة -->
<?php if ($ungenerated && $ungenerated->num_rows > 0): ?>
<div class="card mb-4" style="border:2px solid #fde68a">
  <div class="card-header" style="background:#fefce8;border-bottom:1px solid #fde68a">
    <div class="d-flex align-items-center gap-2">
      <i class="fas fa-exclamation-triangle text-warning"></i>
      <span class="fw-semibold">دفعات لم تصدر لها فاتورة بعد</span>
      <span class="badge bg-warning text-dark"><?= $no_invoice ?></span>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th><th>المكتب</th><th>الباقة</th><th>المبلغ</th><th>تاريخ الدفع</th><th>النوع</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php while ($r = $ungenerated->fetch_assoc()): ?>
        <tr>
          <td class="text-muted" style="font-size:11px"><?=$r['id']?></td>
          <td class="fw-semibold"><?=e($r['office_name']??'—')?></td>
          <td><?=e($r['pkg_name']??'—')?></td>
          <td class="fw-bold text-success"><?=number_format($r['amount'])?> ر.س</td>
          <td style="font-size:12px"><?=date('Y/m/d',strtotime($r['payment_date']))?></td>
          <td>
            <?php $tm=['subscription'=>['اشتراك','primary'],'renewal'=>['تجديد','info'],'upgrade'=>['ترقية','warning'],'manual'=>['يدوي','secondary']]; [$tl,$tc]=$tm[$r['type']]??['—','secondary']; ?>
            <span class="badge bg-<?=$tc?>"><?=$tl?></span>
          </td>
          <td>
            <a href="subscription_invoices.php?gen=<?=$r['id']?>" class="btn btn-sm btn-warning text-dark fw-bold"
               onclick="return confirm('توليد فاتورة ZATCA لهذه الدفعة؟')">
              <i class="fas fa-file-invoice me-1"></i>توليد فاتورة
            </a>
          </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- بحث -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form class="d-flex gap-2" method="GET">
      <input type="text" name="q" class="form-control form-control-sm" style="max-width:300px"
             placeholder="بحث برقم الفاتورة أو اسم المكتب..." value="<?=e($_GET['q']??'')?>">
      <button class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
      <a href="subscription_invoices.php" class="btn btn-outline-secondary btn-sm">إعادة</a>
    </form>
  </div>
</div>

<!-- جدول الفواتير -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>رقم الفاتورة</th>
            <th>المكتب</th>
            <th>الباقة</th>
            <th>نوع الفاتورة</th>
            <th>المبلغ قبل الضريبة</th>
            <th>ضريبة 15%</th>
            <th>الإجمالي</th>
            <th>تاريخ الإصدار</th>
            <th>QR</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$sinvs || $sinvs->num_rows === 0): ?>
        <tr>
          <td colspan="10" class="text-center py-5 text-muted">
            <i class="fas fa-file-invoice fa-2x mb-2 d-block opacity-25"></i>
            لا توجد فواتير اشتراك بعد
          </td>
        </tr>
        <?php else: while ($si = $sinvs->fetch_assoc()): ?>
        <tr>
          <td class="fw-bold text-primary" style="font-family:monospace;font-size:13px"><?=e($si['invoice_number'])?></td>
          <td>
            <div class="fw-semibold"><?=e($si['buyer_name']??$si['office_name_live']??'—')?></div>
            <?php if ($si['buyer_vat']): ?>
            <div style="font-size:10px;color:#92400e;background:#fef3c7;padding:1px 5px;border-radius:3px;display:inline-block">VAT: <?=e($si['buyer_vat'])?></div>
            <?php endif; ?>
          </td>
          <td><?=e($si['package_name']??'—')?></td>
          <td>
            <span class="badge <?=$si['invoice_type']==='standard'?'bg-primary':'bg-info text-dark'?>">
              <?=$si['invoice_type']==='standard'?'ضريبية B2B':'مبسّطة B2C'?>
            </span>
          </td>
          <td style="font-size:13px"><?=number_format($si['subtotal'],2)?> <small class="text-muted">ر.س</small></td>
          <td style="font-size:13px;color:#92400e"><?=number_format($si['tax_amount'],2)?> <small>ر.س</small></td>
          <td class="fw-bold" style="font-size:14px;color:#059669"><?=number_format($si['total'],2)?> <small class="text-muted fw-normal">ر.س</small></td>
          <td style="font-size:12px;white-space:nowrap"><?=date('Y/m/d',strtotime($si['issue_date']))?></td>
          <td>
            <?php if ($si['qr_data']): ?>
            <span class="badge bg-success-subtle text-success" title="QR Code موجود">
              <i class="fas fa-qrcode me-1"></i>✓
            </span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="d-flex gap-1">
              <a href="subscription_invoice_print.php?id=<?=$si['id']?>" target="_blank"
                 class="btn btn-sm btn-outline-primary" title="طباعة / PDF">
                <i class="fas fa-print"></i>
              </a>
              <a href="subscription_invoices.php?delete=<?=$si['id']?>"
                 class="btn btn-sm btn-outline-danger"
                 onclick="return confirm('حذف هذه الفاتورة نهائياً؟')">
                <i class="fas fa-trash"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
