<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/zatca_helper.php';
requireAdmin();
zatca_migrate($conn);
$page_title = 'الإيرادات والتحصيل';

// إنشاء الجدول إن لم يكن موجوداً
$conn->query("CREATE TABLE IF NOT EXISTS platform_revenue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT,
    package_id INT,
    type ENUM('subscription','renewal','upgrade','manual') DEFAULT 'manual',
    billing_period ENUM('monthly','yearly') DEFAULT 'monthly',
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
// إضافة الأعمدة لو كانت الجداول قديمة
try { $conn->query("ALTER TABLE platform_revenue ADD COLUMN billing_period ENUM('monthly','yearly') DEFAULT 'monthly' AFTER type"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE platform_revenue ADD COLUMN notes TEXT DEFAULT NULL"); } catch (\Exception $e) {}

// تسجيل إيراد جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'add_revenue') {
    $office_id    = (int)($_POST['office_id'] ?? 0) ?: null;
    $type         = in_array($_POST['type'] ?? '', ['subscription','renewal','upgrade','manual']) ? $_POST['type'] : 'manual';
    $billing      = 'yearly'; // كل اشتراكات المنصة سنوية فقط
    $amount       = (float)($_POST['amount'] ?? 0);
    $payment_date = $conn->real_escape_string($_POST['payment_date'] ?? date('Y-m-d'));
    $notes        = $conn->real_escape_string($_POST['notes'] ?? '');
    $extend_sub   = !empty($_POST['extend_subscription']);

    $pkg_id = null;
    if ($office_id) {
        $pr = $conn->query("SELECT package_id FROM offices WHERE id=$office_id LIMIT 1");
        if ($pr) $pkg_id = $pr->fetch_assoc()['package_id'] ?? null;
    }

    $oid_sql = $office_id ? $office_id : 'NULL';
    $pid_sql = $pkg_id   ? $pkg_id   : 'NULL';

    $conn->query("INSERT INTO platform_revenue (office_id, package_id, type, billing_period, amount, payment_date, notes)
        VALUES ($oid_sql, $pid_sql, '$type', '$billing', $amount, '$payment_date', '$notes')");

    // تمديد الاشتراك يدوياً
    if ($extend_sub && $office_id) {
        $months = 12;
        $conn->query("UPDATE offices
            SET subscription_end = DATE_ADD(
                GREATEST(IFNULL(subscription_end, CURDATE()), CURDATE()),
                INTERVAL $months MONTH
            ) WHERE id=$office_id");
    }

    // توليد فاتورة ZATCA تلقائياً للدفعات المرتبطة بمكتب
    $new_rev_id = $conn->insert_id;
    if ($office_id && $new_rev_id && !in_array($type, ['manual'])) {
        $off  = $conn->query("SELECT o.*, os.tax_number buyer_vat, os.cr_number buyer_cr, os.address buyer_addr FROM offices o LEFT JOIN office_settings os ON o.id=os.office_id WHERE o.id=$office_id")->fetch_assoc();
        $sn   = sc($conn, 'site_name', 'مِحكام');
        $pvat_r = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='platform_vat' LIMIT 1");
        $pvat = $pvat_r ? ($pvat_r->fetch_assoc()['setting_value'] ?? '') : '';
        $pcr_r  = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='platform_cr' LIMIT 1");
        $pcr  = $pcr_r ? ($pcr_r->fetch_assoc()['setting_value'] ?? '') : '';
        $paddr = sc($conn, 'contact_address', '');

        $sub_amt  = round($amount / 1.15, 2);
        $tax_amt  = round($amount - $sub_amt, 2);
        $inv_type = ($off['buyer_vat'] ?? '') ? 'standard' : 'simplified';
        $qr       = $pvat ? zatca_qr($sn, $pvat, $amount, $tax_amt, $payment_date.'T00:00:00Z') : '';

        $inv_num  = $conn->real_escape_string(next_sub_invoice_num($conn));
        $uuid_v   = $conn->real_escape_string(zatca_uuid());
        $sn_e     = $conn->real_escape_string($sn);
        $pvat_e   = $conn->real_escape_string($pvat);
        $pcr_e    = $conn->real_escape_string($pcr);
        $paddr_e  = $conn->real_escape_string($paddr);
        $bn_e     = $conn->real_escape_string($off['name'] ?? '');
        $bvat_e   = $conn->real_escape_string($off['buyer_vat'] ?? '');
        $bcr_e    = $conn->real_escape_string($off['buyer_cr'] ?? '');
        $baddr_e  = $conn->real_escape_string($off['buyer_addr'] ?? '');
        $pkgn_e   = $conn->real_escape_string(($pkg_id ? ($conn->query("SELECT name FROM packages WHERE id=$pkg_id")->fetch_assoc()['name'] ?? '') : ''));
        $qr_e     = $conn->real_escape_string($qr);
        $itype_e  = $conn->real_escape_string($inv_type);
        $bill_e   = $conn->real_escape_string($billing);

        $conn->query("INSERT IGNORE INTO subscription_invoices
            (invoice_number,uuid,office_id,revenue_id,invoice_type,
             issue_date,supply_date,
             seller_name,seller_vat,seller_cr,seller_address,
             buyer_name,buyer_vat,buyer_cr,buyer_address,
             package_name,billing_period,subtotal,discount,tax_rate,tax_amount,total,qr_data,notes)
            VALUES
            ('$inv_num','$uuid_v',$office_id,$new_rev_id,'$itype_e',
             '$payment_date','$payment_date',
             '$sn_e','$pvat_e','$pcr_e','$paddr_e',
             '$bn_e','$bvat_e','$bcr_e','$baddr_e',
             '$pkgn_e','$bill_e',$sub_amt,0,15.00,$tax_amt,$amount,'$qr_e','$notes')");
    }

    header("Location: revenue.php?msg=added"); exit;
}

// حذف إيراد
if (isset($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    $conn->query("DELETE FROM platform_revenue WHERE id=$did");
    header("Location: revenue.php?msg=deleted"); exit;
}

// إحصائيات
$total   = (float)$conn->query("SELECT IFNULL(SUM(amount),0) s FROM platform_revenue")->fetch_assoc()['s'];
$month   = (float)$conn->query("SELECT IFNULL(SUM(amount),0) s FROM platform_revenue WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetch_assoc()['s'];
$sub_rev = (float)$conn->query("SELECT IFNULL(SUM(amount),0) s FROM platform_revenue WHERE type='subscription'")->fetch_assoc()['s'];
$ren_rev = (float)$conn->query("SELECT IFNULL(SUM(amount),0) s FROM platform_revenue WHERE type IN('renewal','upgrade')")->fetch_assoc()['s'];

// إيرادات حسب الباقة
$by_pkg = $conn->query("SELECT p.name, IFNULL(SUM(pr.amount),0) total
    FROM packages p LEFT JOIN platform_revenue pr ON p.id=pr.package_id
    GROUP BY p.id ORDER BY total DESC");

// قائمة المكاتب
$offices_list = $conn->query("SELECT id, name FROM offices ORDER BY name");

// الفلتر
$where = "1=1";
if (!empty($_GET['from']))     $where .= " AND pr.payment_date >= '".$conn->real_escape_string($_GET['from'])."'";
if (!empty($_GET['to']))       $where .= " AND pr.payment_date <= '".$conn->real_escape_string($_GET['to'])."'";
if (!empty($_GET['type_f']))   $where .= " AND pr.type='".$conn->real_escape_string($_GET['type_f'])."'";
if (!empty($_GET['office_f'])) $where .= " AND pr.office_id=".(int)$_GET['office_f'];

$revs = $conn->query("
    SELECT pr.*, o.name office_name, p.name pkg_name
    FROM platform_revenue pr
    LEFT JOIN offices o ON pr.office_id=o.id
    LEFT JOIN packages p ON pr.package_id=p.id
    WHERE $where
    ORDER BY pr.payment_date DESC, pr.created_at DESC
");

include '../includes/admin_header.php';
?>

<style>
.rev-stat-card { border-radius:14px; border:1.5px solid #f1f5f9; transition:.15s; }
.rev-stat-card:hover { border-color:#e2e8f0; box-shadow:0 4px 18px rgba(0,0,0,.07); }
.rev-type-pill { display:flex; align-items:center; gap:6px; border:1.5px solid #e2e8f0; border-radius:8px; padding:7px 14px; cursor:pointer; font-size:13px; transition:.15s; user-select:none; }
.rev-type-pill:hover { border-color:#94a3b8; }
.rev-type-pill.selected { border-color:#16a34a; background:#f0fdf4; font-weight:600; }
.type-badge { font-size:11px; padding:2px 8px; border-radius:20px; font-weight:600; }
</style>

<?php if(isset($_GET['msg'])): ?>
<div class="alert alert-<?= $_GET['msg']==='added'?'success':'warning' ?> alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
  <i class="fas fa-<?= $_GET['msg']==='added'?'check-circle':'trash-alt' ?>"></i>
  <?= ['added'=>'تم تسجيل الإيراد بنجاح','deleted'=>'تم حذف السجل'][$_GET['msg']] ?? '' ?>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- رأس الصفحة -->
<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h5 class="mb-0 fw-bold"><i class="fas fa-coins me-2 text-warning"></i>الإيرادات والتحصيل</h5>
    <div class="text-muted mt-1" style="font-size:13px">سجّل وتابع إيرادات المنصة</div>
  </div>
  <button class="btn btn-success px-4" id="btnAddRevenue">
    <i class="fas fa-plus me-2"></i>تسجيل دفعة
  </button>
</div>

<!-- بطاقات الإحصائيات -->
<div class="row g-3 mb-4">
  <?php foreach([
    ['إجمالي الإيرادات',    $total,   'coins',            'warning', '#fef9c3'],
    ['هذا الشهر',           $month,   'calendar-check',   'success', '#f0fdf4'],
    ['اشتراكات جديدة',      $sub_rev, 'user-plus',        'primary', '#eff6ff'],
    ['تجديدات وترقيات',     $ren_rev, 'sync',             'info',    '#f0f9ff'],
  ] as [$lbl,$val,$ic,$col,$bg]): ?>
  <div class="col-6 col-lg-3">
    <div class="card rev-stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="rounded-3 d-flex align-items-center justify-content-center text-<?= $col ?>"
             style="width:48px;height:48px;font-size:20px;flex-shrink:0;background:<?= $bg ?>">
          <i class="fas fa-<?= $ic ?>"></i>
        </div>
        <div>
          <div class="text-muted" style="font-size:11px;margin-bottom:2px"><?= $lbl ?></div>
          <div class="fw-bold" style="font-size:18px"><?= number_format($val) ?> <small class="text-muted fw-normal" style="font-size:11px">ر.س</small></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- المحتوى الرئيسي -->
<div class="row g-3">

  <!-- جدول الإيرادات -->
  <div class="col-lg-8">
    <div class="card">

      <!-- رأس الجدول + فلتر -->
      <div class="card-header border-bottom-0 pb-0">
        <div class="fw-semibold mb-3"><i class="fas fa-list-ul me-2 text-primary"></i>سجل الإيرادات</div>
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-end">
          <div style="flex:1 1 190px;min-width:190px">
            <label class="form-label mb-1" style="font-size:11px;color:#64748b">المكتب</label>
            <select name="office_f" class="form-select form-select-sm">
              <option value="">جميع المكاتب</option>
              <?php $offices_list->data_seek(0); while($ol=$offices_list->fetch_assoc()): ?>
              <option value="<?= $ol['id'] ?>" <?= ($_GET['office_f']??'')==$ol['id']?'selected':'' ?>><?= e($ol['name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div style="flex:1 1 150px;min-width:150px">
            <label class="form-label mb-1" style="font-size:11px;color:#64748b">النوع</label>
            <select name="type_f" class="form-select form-select-sm">
              <option value="">كل الأنواع</option>
              <option value="subscription" <?= ($_GET['type_f']??'')==='subscription'?'selected':'' ?>>اشتراك</option>
              <option value="renewal"      <?= ($_GET['type_f']??'')==='renewal'?'selected':'' ?>>تجديد</option>
              <option value="upgrade"      <?= ($_GET['type_f']??'')==='upgrade'?'selected':'' ?>>ترقية</option>
              <option value="manual"       <?= ($_GET['type_f']??'')==='manual'?'selected':'' ?>>يدوي</option>
            </select>
          </div>
          <div style="flex:1 1 240px;min-width:240px">
            <label class="form-label mb-1" style="font-size:11px;color:#64748b">من تاريخ</label>
            <input type="date" name="from" class="form-control form-control-sm mk-plain-date" value="<?= e($_GET['from']??'') ?>" placeholder="من تاريخ">
          </div>
          <div style="flex:1 1 240px;min-width:240px">
            <label class="form-label mb-1" style="font-size:11px;color:#64748b">إلى تاريخ</label>
            <input type="date" name="to" class="form-control form-control-sm mk-plain-date" value="<?= e($_GET['to']??'') ?>" placeholder="إلى تاريخ">
          </div>
          <div class="d-flex gap-1">
            <button class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>بحث</button>
            <a href="revenue.php" class="btn btn-outline-secondary btn-sm">إعادة</a>
          </div>
        </form>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>المكتب</th>
                <th>الباقة</th>
                <th>النوع</th>
                <th>المبلغ</th>
                <th>التاريخ</th>
                <th>ملاحظة</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php
            $has_rows = false;
            while ($r = $revs->fetch_assoc()):
              $has_rows = true;
              $type_map = [
                'subscription' => ['اشتراك', 'primary'],
                'renewal'      => ['تجديد',  'info'],
                'upgrade'      => ['ترقية',  'warning'],
                'manual'       => ['يدوي',   'secondary'],
              ];
              [$tlbl,$tcol] = $type_map[$r['type']] ?? [$r['type'],'secondary'];
            ?>
            <tr>
              <td class="fw-semibold"><?= e($r['office_name'] ?? '—') ?></td>
              <td>
                <?php if($r['pkg_name']): ?>
                <span class="badge bg-primary bg-opacity-10 text-primary"><?= e($r['pkg_name']) ?></span>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <td><span class="badge bg-<?= $tcol ?> <?= $tcol==='warning'?'text-dark':'' ?> type-badge"><?= $tlbl ?></span></td>
              <td><span class="fw-bold text-success"><?= number_format($r['amount']) ?></span> <small class="text-muted">ر.س</small></td>
              <td style="white-space:nowrap;font-size:13px"><?= date('Y/m/d', strtotime($r['payment_date'])) ?></td>
              <td style="max-width:130px;font-size:12px;color:#94a3b8"><?= e($r['notes'] ?? '') ?></td>
              <td>
                <a href="revenue.php?delete=<?= $r['id'] ?>"
                   class="btn btn-sm btn-outline-danger py-0 px-2"
                   onclick="return confirm('حذف هذا السجل نهائياً؟')">
                  <i class="fas fa-trash" style="font-size:11px"></i>
                </a>
              </td>
            </tr>
            <?php endwhile; ?>
            <?php if (!$has_rows): ?>
            <tr>
              <td colspan="7" class="text-center py-5">
                <div class="text-muted">
                  <i class="fas fa-receipt fa-2x mb-3 d-block opacity-25"></i>
                  لا توجد إيرادات مسجلة
                </div>
              </td>
            </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- جانب: حسب الباقة -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">
        <i class="fas fa-chart-bar me-2 text-primary"></i>توزيع حسب الباقة
      </div>
      <div class="card-body">
        <?php
        $colors = ['#2563eb','#7c3aed','#059669','#d97706'];
        $ci = 0; $any = false;
        while ($bp = $by_pkg->fetch_assoc()):
          $any = true;
          $pct = $total > 0 ? round($bp['total'] / $total * 100) : 0;
        ?>
        <div class="mb-4">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span style="font-size:13px;font-weight:500"><?= e($bp['name']) ?></span>
            <span style="font-size:13px;font-weight:700;color:<?= $colors[$ci%4] ?>"><?= number_format($bp['total']) ?> ر.س</span>
          </div>
          <div class="progress" style="height:7px;border-radius:10px;background:#f1f5f9">
            <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $colors[$ci%4] ?>;border-radius:10px;transition:.4s"></div>
          </div>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px"><?= $pct ?>% من الإجمالي</div>
        </div>
        <?php $ci++; endwhile;
        if (!$any): ?>
        <div class="text-center py-4 text-muted">
          <i class="fas fa-chart-bar fa-2x mb-2 d-block opacity-25"></i>
          <div style="font-size:13px">لا توجد بيانات بعد</div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<!-- ======= MODAL تسجيل إيراد يدوي ======= -->
<div class="modal fade" id="addRevenueModal" tabindex="-1" aria-labelledby="addRevenueLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addRevenueLabel">
          <i class="fas fa-plus-circle me-2 text-success"></i>تسجيل دفعة يدوياً
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="addRevenueForm">
        <input type="hidden" name="form_type" value="add_revenue">
        <div class="modal-body">

          <!-- نوع الإيراد -->
          <div class="mb-3">
            <label class="form-label fw-semibold mb-2">نوع الإيراد</label>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach([
                ['subscription','اشتراك جديد','user-plus','primary'],
                ['renewal',     'تجديد',       'sync',     'info'],
                ['upgrade',     'ترقية',        'arrow-up', 'warning'],
                ['manual',      'يدوي',         'pen',      'secondary'],
              ] as [$val,$lbl,$ic,$col]): ?>
              <label class="rev-type-pill <?= $val==='manual'?'selected':'' ?>">
                <input type="radio" name="type" value="<?= $val ?>" class="d-none" <?= $val==='manual'?'checked':'' ?>>
                <i class="fas fa-<?= $ic ?> text-<?= $col ?>"></i>
                <span><?= $lbl ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- المكتب -->
          <div class="mb-3">
            <label class="form-label fw-semibold">المكتب <small class="text-muted fw-normal">(اختياري)</small></label>
            <select name="office_id" class="form-select" id="rev_office_id">
              <option value="">— غير مرتبط بمكتب —</option>
              <?php
              // احضار المكاتب مع تاريخ انتهاء الاشتراك والباقة
              $offices_full = $conn->query("SELECT o.id, o.name, o.subscription_end, p.price_yearly FROM offices o LEFT JOIN packages p ON o.package_id=p.id ORDER BY o.name");
              while($ol=$offices_full->fetch_assoc()): ?>
              <option value="<?= $ol['id'] ?>"
                      data-sub="<?= e($ol['subscription_end']??'') ?>"
                      data-py="<?= (float)($ol['price_yearly']??0) ?>">
                <?= e($ol['name']) ?>
              </option>
              <?php endwhile; ?>
            </select>
          </div>

          <input type="hidden" name="billing_period" value="yearly">

          <!-- المبلغ والتاريخ -->
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold">المبلغ (ر.س)</label>
              <div class="input-group">
                <input type="number" name="amount" id="rev_amount" class="form-control" placeholder="0" step="0.01" min="0.01" required>
                <span class="input-group-text">ر.س</span>
              </div>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">تاريخ الدفع</label>
              <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
          </div>

          <!-- تمديد الاشتراك -->
          <div id="extend_section" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;margin-bottom:12px;display:none">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="extend_subscription" id="rev_extend" checked>
              <label class="form-check-label fw-semibold" for="rev_extend" style="font-size:13px">
                <i class="fas fa-calendar-plus text-success me-1"></i>مدّد الاشتراك تلقائياً
              </label>
            </div>
            <div id="rev_sub_info" style="font-size:11px;color:#166534;margin-top:4px;margin-right:2rem"></div>
          </div>

          <!-- ملاحظة -->
          <div class="mb-1">
            <label class="form-label fw-semibold">ملاحظة <small class="text-muted fw-normal">(اختياري)</small></label>
            <textarea name="notes" class="form-control" rows="2" placeholder="رقم الإيصال أو أي تفاصيل إضافية..."></textarea>
          </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-success px-4">
            <i class="fas fa-save me-1"></i>حفظ الإيراد
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.rev-billing-opt { transition:.15s; }
.rev-billing-opt:hover { border-color:#94a3b8 !important; }
.selected-billing { border-color:#2563eb !important; background:#eff6ff; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

  // فتح المودال
  var btnAdd = document.getElementById('btnAddRevenue');
  var modalEl = document.getElementById('addRevenueModal');
  if (btnAdd && modalEl) {
    btnAdd.addEventListener('click', function () {
      new bootstrap.Modal(modalEl).show();
    });
  }

  // تمييز نوع الإيراد
  document.querySelectorAll('.rev-type-pill').forEach(function (pill) {
    pill.addEventListener('click', function () {
      document.querySelectorAll('.rev-type-pill').forEach(function (p) { p.classList.remove('selected'); });
      this.classList.add('selected');
      this.querySelector('input[type=radio]').checked = true;
    });
  });

  // عند اختيار مكتب
  document.getElementById('rev_office_id').addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    var py  = parseFloat(opt.getAttribute('data-py') || 0);
    if (py > 0) document.getElementById('rev_amount').value = py;
    var extSec = document.getElementById('extend_section');
    if (this.value) {
      extSec.style.display = '';
      updateSubInfo();
    } else {
      extSec.style.display = 'none';
    }
  });

  function updateSubInfo() {
    var sel = document.getElementById('rev_office_id');
    var opt = sel.options[sel.selectedIndex];
    var sub = opt ? opt.getAttribute('data-sub') : '';
    var info = document.getElementById('rev_sub_info');
    if (!info) return;
    info.textContent = sub
      ? 'الاشتراك الحالي ينتهي في ' + sub + ' — سيُمدَّد بسنة'
      : 'لا يوجد اشتراك نشط — سيُضاف سنة من اليوم';
  }

});
</script>

<?php include '../includes/admin_footer.php'; ?>
