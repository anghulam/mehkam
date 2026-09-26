<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('finance','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'الشؤون المالية';
$oid = (int)$_SESSION['office_id'];

if (!hasFeature($conn, $oid, 'has_finance')) {
    header("Location: profile.php?tab=upgrade&feature=finance"); exit;
}

// أعمدة قد تكون ناقصة على قواعد قديمة
foreach ([
    "ALTER TABLE invoices ADD COLUMN direction ENUM('income','expense') DEFAULT 'income'",
    "ALTER TABLE invoices ADD COLUMN paid_date DATE DEFAULT NULL",
    "ALTER TABLE invoices ADD COLUMN discount_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed'",
    "ALTER TABLE invoices ADD COLUMN discount_value DECIMAL(12,2) NOT NULL DEFAULT 0",
] as $_c) { try { $conn->query($_c); } catch (\Exception $e) {} }

/* ── فلتر ── */
$dir_f = $conn->real_escape_string($_GET['dir_f'] ?? '');
$from  = $conn->real_escape_string($_GET['from']  ?? '');
$to    = $conn->real_escape_string($_GET['to']    ?? '');

// موظّف "نطاق بيانات مقيَّد" يرى هنا أرقام قضاياه المُسندة فقط، لا أرقام المكتب كاملة
$where = "inv.office_id=$oid AND inv.status='paid'" . finScope('inv');
if ($dir_f) $where .= " AND inv.direction='$dir_f'";
if ($from)  $where .= " AND COALESCE(inv.paid_date,inv.created_at)>='$from'";
if ($to)    $where .= " AND COALESCE(inv.paid_date,inv.created_at)<='$to'";

$invoices_list = $conn->query("
    SELECT inv.*, cl.full_name client_name, ca.case_number
    FROM invoices inv
    LEFT JOIN clients cl ON inv.client_id = cl.id
    LEFT JOIN cases   ca ON inv.case_id   = ca.id
    WHERE $where
    ORDER BY COALESCE(inv.paid_date, inv.created_at) DESC, inv.id DESC
");

/* ── إحصائيات ── */
$_fs = finScope();
$total_income  = (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE office_id=$oid AND status='paid' AND (direction='income' OR direction IS NULL)$_fs")->fetch_assoc()['s'];
$total_expense = (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE office_id=$oid AND status='paid' AND direction='expense'$_fs")->fetch_assoc()['s'];
$net           = $total_income - $total_expense; // صافي شامل الضريبة (تدفق نقدي)

// ضريبة القيمة المضافة
$output_vat = (float)$conn->query("SELECT IFNULL(SUM(tax_amount),0) s FROM invoices WHERE office_id=$oid AND status='paid' AND (direction='income' OR direction IS NULL)$_fs")->fetch_assoc()['s'];
$input_vat  = (float)$conn->query("SELECT IFNULL(SUM(tax_amount),0) s FROM invoices WHERE office_id=$oid AND status='paid' AND direction='expense'$_fs")->fetch_assoc()['s'];
$vat_due    = $output_vat - $input_vat;            // مستحقة للهيئة (إن كانت موجبة)
$net_after_tax = ($total_income - $output_vat) - ($total_expense - $input_vat); // صافي ربح تشغيلي بدون الضريبة
$month_income  = (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE office_id=$oid AND status='paid' AND (direction='income' OR direction IS NULL) AND MONTH(COALESCE(paid_date,created_at))=MONTH(CURDATE()) AND YEAR(COALESCE(paid_date,created_at))=YEAR(CURDATE())$_fs")->fetch_assoc()['s'];
$pending_total = (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE office_id=$oid AND status IN('sent','overdue') AND (direction='income' OR direction IS NULL)$_fs")->fetch_assoc()['s'];

/* ── قضايا بأتعاب معلقة ── */
$pending_cases = $conn->query("
    SELECT c.id, c.case_number, c.title, c.fees, cl.full_name client_name,
        IFNULL((
            SELECT SUM(inv2.total) FROM invoices inv2
            WHERE inv2.case_id=c.id AND inv2.status='paid'
              AND (inv2.direction='income' OR inv2.direction IS NULL)
              AND inv2.office_id=c.office_id
        ),0) AS collected
    FROM cases c
    LEFT JOIN clients cl ON c.client_id=cl.id
    WHERE c.office_id=$oid AND c.fees>0 AND c.status='active'" . caseScope('c') . "
    HAVING c.fees > collected
    ORDER BY (c.fees - collected) DESC
    LIMIT 6
");

include '../includes/office_header.php';
?>

<!-- Page Header -->
<div class="mk-page-hdr">
  <div>
    <div class="mk-page-title"><i class="fas fa-coins"></i> الشؤون المالية</div>
    <div class="mk-page-sub">تقرير مالي مبني على الفواتير</div>
  </div>
  <div class="d-flex gap-2">
    <a href="zatca_report.php" class="btn btn-outline-warning">
      <i class="fas fa-landmark me-1"></i>تقرير ZATCA
    </a>
    <a href="invoices.php" class="btn btn-primary">
      <i class="fas fa-file-invoice-dollar me-1"></i>إدارة الفواتير
    </a>
  </div>
</div>

<!-- إحصائيات -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card border-0 h-100" style="background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff">
      <div class="card-body">
        <div style="font-size:11px;opacity:.85"><i class="fas fa-arrow-circle-down me-1"></i>إجمالي الإيرادات</div>
        <div class="fw-bold mt-1" style="font-size:20px"><?= number_format($total_income) ?></div>
        <div style="font-size:11px;opacity:.75">ريال سعودي — فواتير مدفوعة</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card border-0 h-100" style="background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff">
      <div class="card-body">
        <div style="font-size:11px;opacity:.85"><i class="fas fa-arrow-circle-up me-1"></i>إجمالي المصروفات</div>
        <div class="fw-bold mt-1" style="font-size:20px"><?= number_format($total_expense) ?></div>
        <div style="font-size:11px;opacity:.75">ريال سعودي — فواتير مدفوعة</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card border-0 h-100" style="background:linear-gradient(135deg,#2563eb,#3b82f6);color:#fff">
      <div class="card-body">
        <div style="font-size:11px;opacity:.85"><i class="fas fa-balance-scale me-1"></i>صافي الربح <span style="opacity:.8">(بعد استبعاد الضريبة)</span></div>
        <div class="fw-bold mt-1" style="font-size:20px"><?= number_format($net_after_tax) ?></div>
        <div style="font-size:11px;opacity:.75"><?= $net_after_tax >= 0 ? 'ربح تشغيلي' : 'خسارة' ?> — الإجمالي مع الضريبة: <?= number_format($net) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card border-0 h-100" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff">
      <div class="card-body">
        <div style="font-size:11px;opacity:.85"><i class="fas fa-landmark me-1"></i>ضريبة القيمة المضافة المستحقة</div>
        <div class="fw-bold mt-1" style="font-size:20px"><?= number_format($vat_due) ?></div>
        <div style="font-size:11px;opacity:.75">
          <?= $vat_due >= 0 ? 'تُسدَّد للهيئة' : 'رصيد قابل للاسترداد' ?>
          — مخرجات <?= number_format($output_vat) ?> / مدخلات <?= number_format($input_vat) ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card border-0 h-100" style="background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff">
      <div class="card-body">
        <div style="font-size:11px;opacity:.85"><i class="fas fa-clock me-1"></i>إيرادات معلقة</div>
        <div class="fw-bold mt-1" style="font-size:20px"><?= number_format($pending_total) ?></div>
        <div style="font-size:11px;opacity:.75">فواتير مُرسلة / متأخرة</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- سجل الفواتير المالية -->
  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-body py-2">
        <form class="row g-2 align-items-center" method="GET">
          <div class="col-auto">
            <select name="dir_f" class="form-select form-select-sm">
              <option value="">الكل (إيراد + مصروف)</option>
              <option value="income"  <?= $dir_f==='income' ?'selected':'' ?>>إيرادات فقط</option>
              <option value="expense" <?= $dir_f==='expense'?'selected':'' ?>>مصروفات فقط</option>
            </select>
          </div>
          <div class="col-auto">
            <input type="date" name="from" class="form-control form-control-sm mk-plain-date" value="<?= e($_GET['from'] ?? '') ?>" placeholder="من">
          </div>
          <div class="col-auto">
            <input type="date" name="to" class="form-control form-control-sm mk-plain-date" value="<?= e($_GET['to'] ?? '') ?>" placeholder="إلى">
          </div>
          <div class="col-auto">
            <button class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>فلتر</button>
            <a href="finance.php" class="btn btn-sm btn-outline-secondary ms-1"><i class="fas fa-undo"></i></a>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fas fa-file-invoice-dollar me-2"></i>سجل الفواتير المدفوعة</span>
        <a href="invoices.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-external-link-alt me-1"></i>كل الفواتير</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th>التاريخ</th><th>النوع</th><th>رقم الفاتورة</th>
              <th>العنوان / الجهة</th><th>القضية</th><th>المبلغ</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$invoices_list || $invoices_list->num_rows === 0): ?>
            <tr><td colspan="7">
              <div class="mk-empty">
                <div class="mk-empty-icon"><i class="fas fa-coins"></i></div>
                <div class="mk-empty-title">لا توجد فواتير مدفوعة</div>
                <div class="mk-empty-desc">ستظهر الفواتير هنا عند تغيير حالتها إلى "مدفوعة"</div>
              </div>
            </td></tr>
            <?php else: ?>
            <?php while ($inv = $invoices_list->fetch_assoc()):
              $is_expense = ($inv['direction'] ?? 'income') === 'expense';
              $paid_on = $inv['paid_date'] ?? $inv['created_at'];
            ?>
            <tr>
              <td style="white-space:nowrap;font-size:13px"><?= $paid_on ? dDate($paid_on) : '—' ?></td>
              <td>
                <?php if ($is_expense): ?>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fas fa-arrow-circle-up me-1"></i>مصروف</span>
                <?php else: ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-arrow-circle-down me-1"></i>إيراد</span>
                <?php endif; ?>
              </td>
              <td class="fw-bold text-primary" style="font-size:13px"><?= e($inv['invoice_number']) ?></td>
              <td style="max-width:170px">
                <div class="text-truncate" style="font-size:13px" title="<?= e($inv['title']) ?>"><?= e($inv['title']) ?></div>
                <?php if ($inv['client_name']): ?>
                <small class="text-muted"><?= e($inv['client_name']) ?></small>
                <?php endif; ?>
              </td>
              <td style="font-size:12px">
                <?= $inv['case_number'] ? '<span class="badge bg-secondary-subtle text-secondary">'.e($inv['case_number']).'</span>' : '—' ?>
              </td>
              <td class="fw-bold <?= $is_expense ? 'text-danger' : 'text-success' ?>" style="white-space:nowrap">
                <?= $is_expense ? '−' : '+' ?><?= number_format($inv['total'], 2) ?> ر.س
              </td>
              <td>
                <a href="invoices.php?edit=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-primary" title="تعديل"><i class="fas fa-edit"></i></a>
                <a href="invoices.php?print=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-secondary" title="طباعة"><i class="fas fa-print"></i></a>
              </td>
            </tr>
            <?php endwhile; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- أتعاب معلقة -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="fas fa-hourglass-half me-2 text-warning"></i>أتعاب قضايا معلقة</div>
      <div class="card-body p-0">
        <div class="list-group list-group-flush">
        <?php
        $has_pending = false;
        while ($pc = $pending_cases->fetch_assoc()):
          $has_pending = true;
          $pending_amt = $pc['fees'] - $pc['collected'];
          $pct = $pc['fees'] > 0 ? round($pc['collected'] / $pc['fees'] * 100) : 0;
        ?>
          <div class="list-group-item px-3 py-2">
            <div class="fw-semibold" style="font-size:13px"><?= e($pc['title']) ?></div>
            <div class="d-flex justify-content-between mt-1 align-items-center">
              <small class="text-muted"><?= e($pc['client_name']) ?> · <?= e($pc['case_number']) ?></small>
              <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= number_format($pending_amt) ?> ر.س</span>
            </div>
            <div class="progress mt-1" style="height:5px">
              <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="d-flex justify-content-between mt-1">
              <small class="text-muted"><?= $pct ?>% محصَّل</small>
              <a href="invoices.php" class="text-primary" style="font-size:11px"><i class="fas fa-file-invoice-dollar"></i> إصدار فاتورة</a>
            </div>
          </div>
        <?php endwhile; ?>
        <?php if (!$has_pending): ?>
          <div class="list-group-item text-center py-4 text-muted">
            <i class="fas fa-check-circle text-success d-block mb-1" style="font-size:22px"></i>
            لا توجد أتعاب معلقة
          </div>
        <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ملخص الشهر الحالي -->
    <div class="card mt-3">
      <div class="card-header"><i class="fas fa-calendar-alt me-2 text-primary"></i>ملخص الشهر الحالي</div>
      <div class="card-body">
        <?php
        $m_expense = (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE office_id=$oid AND status='paid' AND direction='expense' AND MONTH(COALESCE(paid_date,created_at))=MONTH(CURDATE()) AND YEAR(COALESCE(paid_date,created_at))=YEAR(CURDATE())$_fs")->fetch_assoc()['s'];
        $m_net = $month_income - $m_expense;
        ?>
        <div class="d-flex justify-content-between py-1 border-bottom">
          <span class="text-muted" style="font-size:13px">إيرادات</span>
          <span class="text-success fw-semibold"><?= number_format($month_income) ?> ر.س</span>
        </div>
        <div class="d-flex justify-content-between py-1 border-bottom">
          <span class="text-muted" style="font-size:13px">مصروفات</span>
          <span class="text-danger fw-semibold"><?= number_format($m_expense) ?> ر.س</span>
        </div>
        <?php
        $m_out_vat = (float)$conn->query("SELECT IFNULL(SUM(tax_amount),0) s FROM invoices WHERE office_id=$oid AND status='paid' AND (direction='income' OR direction IS NULL) AND MONTH(COALESCE(paid_date,created_at))=MONTH(CURDATE()) AND YEAR(COALESCE(paid_date,created_at))=YEAR(CURDATE())$_fs")->fetch_assoc()['s'];
        $m_in_vat  = (float)$conn->query("SELECT IFNULL(SUM(tax_amount),0) s FROM invoices WHERE office_id=$oid AND status='paid' AND direction='expense' AND MONTH(COALESCE(paid_date,created_at))=MONTH(CURDATE()) AND YEAR(COALESCE(paid_date,created_at))=YEAR(CURDATE())$_fs")->fetch_assoc()['s'];
        ?>
        <div class="d-flex justify-content-between py-1 border-bottom">
          <span class="text-muted" style="font-size:13px">ضريبة مستحقة للهيئة</span>
          <span class="fw-semibold" style="color:#7c3aed"><?= number_format($m_out_vat - $m_in_vat) ?> ر.س</span>
        </div>
        <div class="d-flex justify-content-between py-1 mt-1">
          <span class="fw-bold" style="font-size:13px">صافي الربح (بدون الضريبة)</span>
          <span class="fw-bold <?= ($m_net - ($m_out_vat - $m_in_vat)) >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($m_net - ($m_out_vat - $m_in_vat)) ?> ر.س</span>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include '../includes/office_footer.php'; ?>
