<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/zatca_helper.php';
requireOffice();
$oid = (int)$_SESSION['office_id'];

// تأمين عمود direction
try { $conn->query("ALTER TABLE invoices ADD COLUMN direction ENUM('income','expense') DEFAULT 'income'"); } catch (\Exception $e) {}

/* ── فترة التقرير ── */
$period_type = $_GET['period_type'] ?? 'month'; // month | quarter | year
$year        = (int)($_GET['year']  ?? date('Y'));
$month       = (int)($_GET['month'] ?? date('n'));
$quarter     = (int)($_GET['quarter'] ?? ceil(date('n') / 3));

if ($period_type === 'month') {
    $date_from = sprintf('%04d-%02d-01', $year, $month);
    $date_to   = date('Y-m-t', strtotime($date_from));
    $period_label = date('F Y', strtotime($date_from));
    $period_ar = ['January'=>'يناير','February'=>'فبراير','March'=>'مارس',
                  'April'=>'أبريل','May'=>'مايو','June'=>'يونيو',
                  'July'=>'يوليو','August'=>'أغسطس','September'=>'سبتمبر',
                  'October'=>'أكتوبر','November'=>'نوفمبر','December'=>'ديسمبر'];
    $period_label = ($period_ar[date('F', strtotime($date_from))] ?? '') . ' ' . $year;
} elseif ($period_type === 'quarter') {
    $qm_start  = ($quarter - 1) * 3 + 1;
    $date_from = sprintf('%04d-%02d-01', $year, $qm_start);
    $date_to   = date('Y-m-t', mktime(0,0,0, $qm_start + 2, 1, $year));
    $period_label = "الربع {$quarter} — {$year}";
} else {
    $date_from = "{$year}-01-01";
    $date_to   = "{$year}-12-31";
    $period_label = "السنة المالية {$year}";
}

$date_from_esc = $conn->real_escape_string($date_from);
$date_to_esc   = $conn->real_escape_string($date_to);

/* ── بيانات المكتب ── */
$office   = $conn->query("SELECT * FROM offices WHERE id=$oid")->fetch_assoc();
$settings = $conn->query("SELECT * FROM office_settings WHERE office_id=$oid")->fetch_assoc() ?? [];
$vat_num  = $settings['tax_number'] ?? '';
$cr_num   = $settings['cr_number']  ?? '';

/* ── استعلامات الفواتير (كل الحالات ما عدا الملغاة) ── */
$inv_base = "FROM invoices WHERE office_id=$oid AND status != 'cancelled' AND issue_date BETWEEN '$date_from_esc' AND '$date_to_esc'";

// مبيعات: الإيرادات
$sales = $conn->query("SELECT id,invoice_number,issue_date,client_id,title,subtotal,tax_rate,tax_amount,total,status,invoice_type
    $inv_base AND (direction='income' OR direction IS NULL)
    ORDER BY issue_date ASC");

// مشتريات: المصروفات
$purchases = $conn->query("SELECT id,invoice_number,issue_date,client_id,title,subtotal,tax_rate,tax_amount,total,status,invoice_type
    $inv_base AND direction='expense'
    ORDER BY issue_date ASC");

// مجاميع المبيعات
$s_totals = $conn->query("SELECT COUNT(*) cnt, IFNULL(SUM(subtotal),0) subtotal, IFNULL(SUM(tax_amount),0) tax, IFNULL(SUM(total),0) total
    $inv_base AND (direction='income' OR direction IS NULL)")->fetch_assoc();

// مجاميع المشتريات
$p_totals = $conn->query("SELECT COUNT(*) cnt, IFNULL(SUM(subtotal),0) subtotal, IFNULL(SUM(tax_amount),0) tax, IFNULL(SUM(total),0) total
    $inv_base AND direction='expense'")->fetch_assoc();

$output_vat = (float)($s_totals['tax'] ?? 0);
$input_vat  = (float)($p_totals['tax'] ?? 0);
$net_vat    = $output_vat - $input_vat;

// سنوات متاحة للفلتر
$years_res = $conn->query("SELECT DISTINCT YEAR(issue_date) y FROM invoices WHERE office_id=$oid AND issue_date IS NOT NULL ORDER BY y DESC");
$years = [];
while ($yr = $years_res->fetch_assoc()) $years[] = $yr['y'];
if (!in_array($year, $years)) $years[] = $year;
rsort($years);

/* ── أسماء العملاء ── */
$clients_map = [];
$cr = $conn->query("SELECT id, full_name FROM clients WHERE office_id=$oid");
while ($cl = $cr->fetch_assoc()) $clients_map[$cl['id']] = $cl['full_name'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تقرير ZATCA — <?= e($office['name']) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  body { font-family:'Segoe UI',Tahoma,Arial,sans-serif; background:#f1f5f9; }
  .report-wrap { max-width:1050px; margin:0 auto; }

  /* كرت القيم */
  .vat-card { border-radius:10px; padding:18px 22px; color:#fff; }
  .vat-card .label { font-size:12px; opacity:.85; }
  .vat-card .amount { font-size:26px; font-weight:800; line-height:1.2; }
  .vat-card .sub { font-size:11px; opacity:.7; }

  /* جدول الفواتير */
  .inv-table th { background:#0f2040; color:#fff; font-size:12px; padding:8px 10px; }
  .inv-table td { font-size:12px; padding:7px 10px; vertical-align:middle; }
  .inv-table tfoot td { background:#f1f5f9; font-weight:700; font-size:12.5px; }

  /* إقرار ZATCA */
  .vat-return { border:2px solid #0f2040; border-radius:10px; overflow:hidden; }
  .vat-return-head { background:#0f2040; color:#fff; padding:12px 18px; }
  .vr-row { display:flex; border-bottom:1px solid #e2e8f0; }
  .vr-row:last-child { border-bottom:none; }
  .vr-box { font-size:11px; font-weight:700; background:#e8f0fe; color:#1e3a8a;
            padding:6px 10px; min-width:40px; display:flex; align-items:center; justify-content:center; border-left:1px solid #e2e8f0; }
  .vr-label { flex:1; padding:8px 14px; font-size:13px; display:flex; align-items:center; }
  .vr-amount { min-width:160px; padding:8px 14px; text-align:left; direction:ltr; font-weight:700; font-size:13px;
               border-right:1px solid #e2e8f0; display:flex; align-items:center; justify-content:flex-end; }
  .vr-section-head { background:#f8fafc; font-weight:700; color:#0f2040; font-size:12px; }
  .vr-net { background:#fef3c7; }
  .vr-net .vr-label, .vr-net .vr-amount { font-size:15px; font-weight:800; color:#92400e; }
  .vr-net .vr-box { background:#fde68a; color:#92400e; }
  .vr-payable { background:#dcfce7; }
  .vr-payable .vr-label, .vr-payable .vr-amount { font-size:15px; font-weight:800; color:#166534; }
  .vr-payable .vr-box { background:#bbf7d0; color:#166534; }
  .vr-refund { background:#fee2e2; }
  .vr-refund .vr-label, .vr-refund .vr-amount { font-size:15px; font-weight:800; color:#991b1b; }

  /* طباعة */
  @media print {
    .no-print { display:none !important; }
    body { background:#fff; }
    .report-wrap { max-width:100%; }
    .card { border:1px solid #ccc !important; box-shadow:none !important; }
    .page-break { page-break-before:always; }
  }

  .status-paid     { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
  .status-sent     { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
  .status-draft    { background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; }
  .status-overdue  { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
</style>
</head>
<body>

<!-- شريط أدوات -->
<div class="no-print bg-white border-bottom py-2 px-3 mb-3 sticky-top shadow-sm">
  <div class="report-wrap d-flex align-items-center gap-2 flex-wrap">
    <a href="finance.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-right me-1"></i>الشؤون المالية</a>
    <span class="fw-bold text-primary"><i class="fas fa-landmark me-1"></i>تقرير ZATCA</span>
    <div class="ms-auto d-flex gap-2 flex-wrap">
      <?php $qs = http_build_query(['period_type'=>$period_type,'year'=>$year,'month'=>$month,'quarter'=>$quarter]); ?>
      <a href="zatca_pdf.php?<?= $qs ?>&view=1" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fas fa-file-pdf me-1"></i>معاينة PDF</a>
      <a href="zatca_pdf.php?<?= $qs ?>" class="btn btn-sm btn-primary"><i class="fas fa-download me-1"></i>تنزيل PDF</a>
    </div>
  </div>
</div>

<div class="report-wrap px-3 pb-5">

  <!-- فلتر الفترة -->
  <div class="card mb-3 no-print">
    <div class="card-body py-2">
      <form class="row g-2 align-items-end" method="GET">
        <div class="col-auto">
          <label class="form-label form-label-sm mb-1">نوع الفترة</label>
          <select name="period_type" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="month"   <?= $period_type==='month'  ?'selected':'' ?>>شهري</option>
            <option value="quarter" <?= $period_type==='quarter'?'selected':'' ?>>ربع سنوي</option>
            <option value="year"    <?= $period_type==='year'   ?'selected':'' ?>>سنوي</option>
          </select>
        </div>
        <?php if ($period_type === 'month'): ?>
        <div class="col-auto">
          <label class="form-label form-label-sm mb-1">الشهر</label>
          <select name="month" class="form-select form-select-sm">
            <?php $months=['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
            foreach ($months as $i=>$mn): ?>
            <option value="<?= $i+1 ?>" <?= $month===$i+1?'selected':'' ?>><?= $mn ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php elseif ($period_type === 'quarter'): ?>
        <div class="col-auto">
          <label class="form-label form-label-sm mb-1">الربع</label>
          <select name="quarter" class="form-select form-select-sm">
            <?php foreach([1=>'الربع الأول (يناير–مارس)',2=>'الربع الثاني (أبريل–يونيو)',3=>'الربع الثالث (يوليو–سبتمبر)',4=>'الربع الرابع (أكتوبر–ديسمبر)'] as $q=>$ql): ?>
            <option value="<?= $q ?>" <?= $quarter===$q?'selected':'' ?>><?= $ql ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-auto">
          <label class="form-label form-label-sm mb-1">السنة</label>
          <select name="year" class="form-select form-select-sm">
            <?php foreach ($years as $y): ?>
            <option value="<?= $y ?>" <?= $year===$y?'selected':'' ?>><?= $y ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>عرض</button>
        </div>
      </form>
    </div>
  </div>

  <!-- رأس التقرير -->
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div class="lh-hide">
          <div style="font-size:22px;font-weight:900;color:#0f2040"><?= e($office['name']) ?></div>
          <?php if ($vat_num): ?>
          <div class="mt-1"><span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:12px">
            <i class="fas fa-hashtag me-1"></i>الرقم الضريبي: <?= e($vat_num) ?>
          </span></div>
          <?php endif; ?>
          <?php if ($cr_num): ?>
          <div class="mt-1 text-muted" style="font-size:12px"><i class="fas fa-building me-1"></i>السجل التجاري: <?= e($cr_num) ?></div>
          <?php endif; ?>
        </div>
        <div class="text-end">
          <div style="font-size:13px;color:#64748b">إقرار ضريبة القيمة المضافة</div>
          <div style="font-size:16px;font-weight:700;color:#0f2040"><?= e($period_label) ?></div>
          <div style="font-size:11px;color:#94a3b8"><?= $date_from ?> إلى <?= $date_to ?></div>
          <div class="mt-1">
            <span class="badge bg-warning text-dark" style="font-size:11px">
              <i class="fas fa-qrcode me-1"></i>ZATCA Phase 1 — إقرار توليد
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- بطاقات الملخص -->
  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
      <div class="vat-card" style="background:linear-gradient(135deg,#16a34a,#22c55e)">
        <div class="label"><i class="fas fa-arrow-circle-down me-1"></i>ضريبة المخرجات</div>
        <div class="amount"><?= number_format($output_vat, 2) ?></div>
        <div class="sub">من <?= number_format((float)$s_totals['subtotal'], 2) ?> ريال مبيعات</div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="vat-card" style="background:linear-gradient(135deg,#dc2626,#ef4444)">
        <div class="label"><i class="fas fa-arrow-circle-up me-1"></i>ضريبة المدخلات</div>
        <div class="amount"><?= number_format($input_vat, 2) ?></div>
        <div class="sub">من <?= number_format((float)$p_totals['subtotal'], 2) ?> ريال مشتريات</div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="vat-card" style="background:linear-gradient(135deg,<?= $net_vat >= 0 ? '#d97706,#f59e0b' : '#7c3aed,#8b5cf6' ?>)">
        <div class="label"><i class="fas fa-balance-scale me-1"></i>صافي الضريبة</div>
        <div class="amount"><?= number_format(abs($net_vat), 2) ?></div>
        <div class="sub"><?= $net_vat >= 0 ? 'ضريبة مستحقة الدفع' : 'ضريبة مستحقة الاسترداد' ?></div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="vat-card" style="background:linear-gradient(135deg,#2563eb,#3b82f6)">
        <div class="label"><i class="fas fa-file-invoice me-1"></i>إجمالي الفواتير</div>
        <div class="amount"><?= (int)$s_totals['cnt'] + (int)$p_totals['cnt'] ?></div>
        <div class="sub"><?= $s_totals['cnt'] ?> مبيعات · <?= $p_totals['cnt'] ?> مشتريات</div>
      </div>
    </div>
  </div>

  <!-- نموذج الإقرار الضريبي -->
  <div class="card mb-3">
    <div class="card-header fw-bold" style="background:#0f2040;color:#fff;border:none">
      <i class="fas fa-file-alt me-2"></i>نموذج إقرار ضريبة القيمة المضافة — <?= e($period_label) ?>
    </div>
    <div class="card-body p-3">
      <div class="vat-return">
        <div class="vat-return-head">
          <div class="d-flex justify-content-between align-items-center">
            <span class="fw-bold" style="font-size:14px">VAT Return — إقرار ضريبة القيمة المضافة</span>
            <span style="font-size:12px;opacity:.8">المرحلة الأولى: التوليد | Phase 1: Generation</span>
          </div>
        </div>

        <!-- قسم المبيعات -->
        <div class="vr-row vr-section-head">
          <div class="vr-label" style="padding:6px 14px"><i class="fas fa-arrow-circle-down me-2 text-success"></i>القسم الأول: المبيعات والإيرادات</div>
        </div>
        <div class="vr-row">
          <div class="vr-box">1</div>
          <div class="vr-label">المبيعات المحلية الخاضعة للضريبة (15%) — قيمة التوريدات</div>
          <div class="vr-amount"><?= number_format((float)$s_totals['subtotal'], 2) ?> ر.س</div>
        </div>
        <div class="vr-row">
          <div class="vr-box">2</div>
          <div class="vr-label">ضريبة القيمة المضافة على المبيعات (ضريبة المخرجات)</div>
          <div class="vr-amount text-success"><?= number_format($output_vat, 2) ?> ر.س</div>
        </div>
        <div class="vr-row">
          <div class="vr-box">3</div>
          <div class="vr-label">إجمالي المبيعات شامل الضريبة</div>
          <div class="vr-amount"><?= number_format((float)$s_totals['total'], 2) ?> ر.س</div>
        </div>

        <!-- قسم المشتريات -->
        <div class="vr-row vr-section-head">
          <div class="vr-label" style="padding:6px 14px"><i class="fas fa-arrow-circle-up me-2 text-danger"></i>القسم الثاني: المشتريات والمصروفات</div>
        </div>
        <div class="vr-row">
          <div class="vr-box">4</div>
          <div class="vr-label">المشتريات المحلية الخاضعة للضريبة (15%) — قيمة التوريدات</div>
          <div class="vr-amount"><?= number_format((float)$p_totals['subtotal'], 2) ?> ر.س</div>
        </div>
        <div class="vr-row">
          <div class="vr-box">5</div>
          <div class="vr-label">ضريبة القيمة المضافة على المشتريات (ضريبة المدخلات المستحقة الخصم)</div>
          <div class="vr-amount text-danger"><?= number_format($input_vat, 2) ?> ر.س</div>
        </div>

        <!-- صافي الضريبة -->
        <div class="vr-row vr-section-head">
          <div class="vr-label" style="padding:6px 14px"><i class="fas fa-calculator me-2"></i>القسم الثالث: حساب الضريبة المستحقة</div>
        </div>
        <div class="vr-row">
          <div class="vr-box">6</div>
          <div class="vr-label">ضريبة المخرجات (2)</div>
          <div class="vr-amount"><?= number_format($output_vat, 2) ?> ر.س</div>
        </div>
        <div class="vr-row">
          <div class="vr-box">7</div>
          <div class="vr-label">ضريبة المدخلات المستحقة الخصم (5)</div>
          <div class="vr-amount">(<?= number_format($input_vat, 2) ?>) ر.س</div>
        </div>
        <?php if ($net_vat >= 0): ?>
        <div class="vr-row vr-payable">
          <div class="vr-box">8</div>
          <div class="vr-label"><i class="fas fa-check-circle me-1"></i>صافي الضريبة المستحقة الدفع للهيئة</div>
          <div class="vr-amount"><?= number_format($net_vat, 2) ?> ر.س</div>
        </div>
        <?php else: ?>
        <div class="vr-row vr-refund">
          <div class="vr-box">8</div>
          <div class="vr-label"><i class="fas fa-undo me-1"></i>فائض ضريبة مدخلات — مستحق الاسترداد</div>
          <div class="vr-amount"><?= number_format(abs($net_vat), 2) ?> ر.س</div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- جدول فواتير المبيعات -->
  <div class="card mb-3 <?= $sales->num_rows === 0 ? '' : '' ?>">
    <div class="card-header d-flex justify-content-between align-items-center"
         style="background:#16a34a;color:#fff;border:none">
      <span><i class="fas fa-arrow-circle-down me-2"></i>فواتير المبيعات والإيرادات (<?= $sales->num_rows ?>)</span>
      <span style="font-size:12px">الإجمالي شامل الضريبة: <?= number_format((float)$s_totals['total'], 2) ?> ر.س</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 inv-table">
          <thead><tr>
            <th>#</th><th>رقم الفاتورة</th><th>تاريخ الإصدار</th><th>العميل / الجهة</th>
            <th>البيان</th><th class="text-end">قبل الضريبة</th>
            <th class="text-end">ض.ق.م 15%</th><th class="text-end">الإجمالي</th><th>الحالة</th>
          </tr></thead>
          <tbody>
          <?php
          $r = 0;
          if ($sales->num_rows === 0):
          ?>
          <tr><td colspan="9" class="text-center py-3 text-muted">لا توجد فواتير إيرادات في هذه الفترة</td></tr>
          <?php else: while ($inv = $sales->fetch_assoc()): $r++; ?>
          <tr>
            <td class="text-muted"><?= $r ?></td>
            <td class="fw-bold text-primary"><?= e($inv['invoice_number']) ?></td>
            <td style="white-space:nowrap"><?= $inv['issue_date'] ? dDate($inv['issue_date']) : '—' ?></td>
            <td style="max-width:140px"><div class="text-truncate"><?= e($clients_map[$inv['client_id']] ?? '—') ?></div></td>
            <td style="max-width:160px"><div class="text-truncate text-muted" style="font-size:11px"><?= e($inv['title']) ?></div></td>
            <td class="text-end"><?= number_format((float)$inv['subtotal'], 2) ?></td>
            <td class="text-end text-success fw-semibold"><?= number_format((float)$inv['tax_amount'], 2) ?></td>
            <td class="text-end fw-bold"><?= number_format((float)$inv['total'], 2) ?></td>
            <td>
              <?php
              $stmap = ['paid'=>['مدفوعة','status-paid'],'sent'=>['مُرسلة','status-sent'],
                        'draft'=>['مسودة','status-draft'],'overdue'=>['متأخرة','status-overdue']];
              $st = $stmap[$inv['status']] ?? [$inv['status'],'status-draft'];
              ?>
              <span class="badge <?= $st[1] ?>" style="font-size:10px"><?= $st[0] ?></span>
            </td>
          </tr>
          <?php endwhile; endif; ?>
          </tbody>
          <?php if ($sales->num_rows > 0): ?>
          <tfoot><tr>
            <td colspan="5" class="text-end">الإجمالي (<?= $s_totals['cnt'] ?> فاتورة)</td>
            <td class="text-end"><?= number_format((float)$s_totals['subtotal'], 2) ?></td>
            <td class="text-end text-success"><?= number_format($output_vat, 2) ?></td>
            <td class="text-end"><?= number_format((float)$s_totals['total'], 2) ?></td>
            <td></td>
          </tr></tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>

  <!-- جدول فواتير المشتريات -->
  <div class="card mb-3 page-break">
    <div class="card-header d-flex justify-content-between align-items-center"
         style="background:#dc2626;color:#fff;border:none">
      <span><i class="fas fa-arrow-circle-up me-2"></i>فواتير المشتريات والمصروفات (<?= $purchases->num_rows ?>)</span>
      <span style="font-size:12px">الإجمالي شامل الضريبة: <?= number_format((float)$p_totals['total'], 2) ?> ر.س</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 inv-table">
          <thead><tr>
            <th>#</th><th>رقم الفاتورة</th><th>تاريخ الإصدار</th><th>المورد / الجهة</th>
            <th>البيان</th><th class="text-end">قبل الضريبة</th>
            <th class="text-end">ض.ق.م 15%</th><th class="text-end">الإجمالي</th><th>الحالة</th>
          </tr></thead>
          <tbody>
          <?php
          $r = 0;
          if ($purchases->num_rows === 0):
          ?>
          <tr><td colspan="9" class="text-center py-3 text-muted">لا توجد فواتير مصروفات في هذه الفترة</td></tr>
          <?php else: while ($inv = $purchases->fetch_assoc()): $r++; ?>
          <tr>
            <td class="text-muted"><?= $r ?></td>
            <td class="fw-bold text-danger"><?= e($inv['invoice_number']) ?></td>
            <td style="white-space:nowrap"><?= $inv['issue_date'] ? dDate($inv['issue_date']) : '—' ?></td>
            <td style="max-width:140px"><div class="text-truncate"><?= e($clients_map[$inv['client_id']] ?? '—') ?></div></td>
            <td style="max-width:160px"><div class="text-truncate text-muted" style="font-size:11px"><?= e($inv['title']) ?></div></td>
            <td class="text-end"><?= number_format((float)$inv['subtotal'], 2) ?></td>
            <td class="text-end text-danger fw-semibold"><?= number_format((float)$inv['tax_amount'], 2) ?></td>
            <td class="text-end fw-bold"><?= number_format((float)$inv['total'], 2) ?></td>
            <td>
              <?php $st = $stmap[$inv['status']] ?? [$inv['status'],'status-draft']; ?>
              <span class="badge <?= $st[1] ?>" style="font-size:10px"><?= $st[0] ?></span>
            </td>
          </tr>
          <?php endwhile; endif; ?>
          </tbody>
          <?php if ($purchases->num_rows > 0): ?>
          <tfoot><tr>
            <td colspan="5" class="text-end">الإجمالي (<?= $p_totals['cnt'] ?> فاتورة)</td>
            <td class="text-end"><?= number_format((float)$p_totals['subtotal'], 2) ?></td>
            <td class="text-end text-danger"><?= number_format($input_vat, 2) ?></td>
            <td class="text-end"><?= number_format((float)$p_totals['total'], 2) ?></td>
            <td></td>
          </tr></tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>

  <!-- تذييل التقرير -->
  <div class="card">
    <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2" style="font-size:11px;color:#64748b">
      <div>
        <i class="fas fa-check-circle text-success me-1"></i>
        هذا التقرير مُولَّد تلقائياً من بيانات الفواتير المُدخلة في النظام —
        <strong>المرحلة الأولى من الفوترة الإلكترونية ZATCA (التوليد)</strong>
      </div>
      <div>
        تاريخ الإصدار: <?= date('d/m/Y H:i') ?>
        <?php if ($vat_num): ?> · ر.ض: <?= e($vat_num) ?> <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /report-wrap -->
</body>
</html>
