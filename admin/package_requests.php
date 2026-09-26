<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/zatca_helper.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/module_helper.php';
requireAdmin();
zatca_migrate($conn);
$page_title = 'طلبات تغيير الباقة';

/* ── إنشاء الجدول إن لم يكن موجوداً ── */
$conn->query("CREATE TABLE IF NOT EXISTS package_requests (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    office_id            INT NOT NULL,
    current_package_id   INT DEFAULT NULL,
    requested_package_id INT NOT NULL DEFAULT 0,
    reason               TEXT,
    commitment           TINYINT(1) DEFAULT 0,
    status               ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_note           TEXT,
    payment_proof        VARCHAR(500) DEFAULT NULL,
    is_custom            TINYINT(1) DEFAULT 0,
    custom_features      JSON DEFAULT NULL,
    custom_limits        JSON DEFAULT NULL,
    custom_price_monthly DECIMAL(10,2) DEFAULT 0,
    admin_final_price    DECIMAL(10,2) DEFAULT NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at         DATETIME DEFAULT NULL,
    INDEX (office_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $conn->query("ALTER TABLE package_requests ADD COLUMN payment_proof VARCHAR(500) DEFAULT NULL"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE package_requests ADD COLUMN is_custom TINYINT(1) DEFAULT 0"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE package_requests ADD COLUMN custom_features JSON DEFAULT NULL"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE package_requests ADD COLUMN custom_limits JSON DEFAULT NULL"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE package_requests ADD COLUMN custom_price_monthly DECIMAL(10,2) DEFAULT 0"); } catch (\Exception $e) {}
try { $conn->query("ALTER TABLE package_requests ADD COLUMN admin_final_price DECIMAL(10,2) DEFAULT NULL"); } catch (\Exception $e) {}

/* ── موافقة (POST مع تسجيل دفعة اختياري) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_id'])) {
    $id             = (int)$_POST['approve_id'];
    $record_payment = !empty($_POST['record_payment']);
    $extend_sub     = !empty($_POST['extend_subscription']);
    $billing        = $_POST['billing'] ?? 'monthly';   // monthly | yearly
    $amount         = (float)($_POST['amount'] ?? 0);
    $payment_date   = $conn->real_escape_string($_POST['payment_date'] ?? date('Y-m-d'));
    $notes          = $conn->real_escape_string(trim($_POST['notes'] ?? ''));

    // حفظ السعر النهائي للباقة المخصصة
    $admin_final = !empty($_POST['admin_final_price']) ? (float)$_POST['admin_final_price'] : null;

    $req = $conn->query("SELECT * FROM package_requests WHERE id=$id")->fetch_assoc();
    if ($req && $req['status'] === 'pending') {
        $oid   = (int)$req['office_id'];
        $pkgid = (int)$req['requested_package_id'];

        // للباقة المخصصة: إنشاء باقة جديدة
        if (!empty($req['is_custom'])) {
            $cf = json_decode($req['custom_features'] ?? '{}', true) ?: [];
            $cl = json_decode($req['custom_limits']   ?? '{}', true) ?: [];
            $c_price = $admin_final ?? (float)($req['custom_price_monthly'] ?? 0);
            $c_users   = max(1, (int)($cl['users'] ?? 3));
            $c_cases   = max(1, (int)($cl['cases'] ?? 50));
            $c_storage = max(0, (int)($cl['storage_mb'] ?? 512));
            $c_price_y = round($c_price * 10, 2); // سنوي = 10 أشهر
            $off_name_r = $conn->query("SELECT name FROM offices WHERE id=$oid")->fetch_assoc();
            $pkg_label  = $conn->real_escape_string('مخصصة — ' . ($off_name_r['name'] ?? 'مكتب'));
            $conn->query("INSERT INTO packages (name,price_monthly,price_yearly,max_users,max_cases,is_active)
                VALUES ('$pkg_label',$c_price,$c_price_y,$c_users,$c_cases,1)");
            $pkgid = (int)$conn->insert_id;
            // إدخال الميزات
            foreach ($cf as $fkey => $enabled) {
                if ($enabled && strpos($fkey, 'mod_') !== 0) {
                    $fke = $conn->real_escape_string($fkey);
                    $conn->query("INSERT IGNORE INTO package_features (package_id,feature_key,feature_value)
                        VALUES ($pkgid,'$fke','1')");
                }
            }
            // الموديولات الإضافية المختارة تُفعَّل لهذا المكتب مباشرة
            custom_pkg_enable_modules($conn, $oid, $cf, (int)($_SESSION['user_id'] ?? 0), $pkgid);
            // التخزين
            $conn->query("INSERT IGNORE INTO package_features (package_id,feature_key,feature_value)
                VALUES ($pkgid,'storage_mb','$c_storage')");
            // حفظ السعر النهائي في السجل
            if ($admin_final !== null) {
                $conn->query("UPDATE package_requests SET admin_final_price=$admin_final WHERE id=$id");
                // تحديث المبلغ للدفع أيضاً
                if (!$record_payment) $amount = $admin_final;
            }
        }

        // تغيير الباقة
        $conn->query("UPDATE offices SET package_id=$pkgid WHERE id=$oid");

        // تمديد الاشتراك
        if ($extend_sub) {
            $months = ($billing === 'yearly') ? 12 : 1;
            $conn->query("UPDATE offices
                SET subscription_end = DATE_ADD(
                    GREATEST(IFNULL(subscription_end, CURDATE()), CURDATE()),
                    INTERVAL $months MONTH
                ) WHERE id=$oid");
        }

        // تسجيل الدفعة في platform_revenue
        if ($record_payment && $amount > 0) {
            $pay_type  = $req['current_package_id'] ? 'renewal' : 'subscription';
            $auto_note = $conn->real_escape_string('موافقة تلقائية على طلب #'.$id . ($notes ? ' — '.$notes : ''));
            $billing_e = $conn->real_escape_string($billing);
            $conn->query("INSERT INTO platform_revenue (office_id, package_id, type, billing_period, amount, payment_date, notes)
                VALUES ($oid, $pkgid, '$pay_type', '$billing_e', $amount, '$payment_date', '$auto_note')");
            $rev_id = $conn->insert_id;

            // توليد فاتورة ZATCA تلقائياً
            $off  = $conn->query("SELECT o.name, o.phone, os.tax_number bvat, os.cr_number bcr, os.address baddr FROM offices o LEFT JOIN office_settings os ON o.id=os.office_id WHERE o.id=$oid")->fetch_assoc();
            $sn   = sc($conn,'site_name','مِحكام');
            $pvr  = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='platform_vat' LIMIT 1");
            $pvat = $pvr ? ($pvr->fetch_assoc()['setting_value'] ?? '') : '';
            $pcr_r = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='platform_cr' LIMIT 1");
            $pcr  = $pcr_r ? ($pcr_r->fetch_assoc()['setting_value'] ?? '') : '';
            $paddr = sc($conn,'contact_address','');
            $pkg_name_r = $conn->query("SELECT name FROM packages WHERE id=$pkgid")->fetch_assoc();
            $pkgn = $pkg_name_r['name'] ?? '';

            $sub_amt  = round($amount / 1.15, 2);
            $tax_amt  = round($amount - $sub_amt, 2);
            $inv_type = ($off['bvat'] ?? '') ? 'standard' : 'simplified';
            $qr       = $pvat ? zatca_qr($sn, $pvat, $amount, $tax_amt, $payment_date.'T00:00:00Z') : '';

            $inv_num_s = $conn->real_escape_string(next_sub_invoice_num($conn));
            $uuid_s    = $conn->real_escape_string(zatca_uuid());
            $vars = [
                'sn_e' => $sn, 'pvat_e' => $pvat, 'pcr_e' => $pcr, 'paddr_e' => $paddr,
                'bn_e' => $off['name']??'', 'bvat_e' => $off['bvat']??'', 'bcr_e' => $off['bcr']??'',
                'baddr_e' => $off['baddr']??'', 'pkgn_e' => $pkgn, 'qr_e' => $qr,
                'itype_e' => $inv_type, 'bill_e' => $billing,
            ];
            foreach ($vars as $k => &$v) $v = $conn->real_escape_string($v);

            $conn->query("INSERT IGNORE INTO subscription_invoices
                (invoice_number,uuid,office_id,revenue_id,invoice_type,
                 issue_date,supply_date,
                 seller_name,seller_vat,seller_cr,seller_address,
                 buyer_name,buyer_vat,buyer_cr,buyer_address,
                 package_name,billing_period,subtotal,discount,tax_rate,tax_amount,total,qr_data,notes)
                VALUES
                ('{$inv_num_s}','{$uuid_s}',$oid,$rev_id,'{$vars['itype_e']}',
                 '$payment_date','$payment_date',
                 '{$vars['sn_e']}','{$vars['pvat_e']}','{$vars['pcr_e']}','{$vars['paddr_e']}',
                 '{$vars['bn_e']}','{$vars['bvat_e']}','{$vars['bcr_e']}','{$vars['baddr_e']}',
                 '{$vars['pkgn_e']}','{$vars['bill_e']}',
                 $sub_amt,0,15.00,$tax_amt,$amount,'{$vars['qr_e']}','$auto_note')");

            // عمولة الأفلييت — تُحتسب من أول دفعة فقط لهذا المكتب (آمن التكرار)
            affiliate_credit_conversion($conn, $oid, $amount);
        }

        // تحديث حالة الطلب
        $conn->query("UPDATE package_requests SET status='approved', processed_at=NOW() WHERE id=$id");

        // إشعار للمكتب
        $pkg_name = !empty($req['is_custom']) ? 'المخصصة' : ($conn->query("SELECT name FROM packages WHERE id=$pkgid")->fetch_assoc()['name'] ?? 'الجديدة');
        $pn = $conn->real_escape_string($pkg_name);
        $pay_msg = $record_payment && $amount > 0
            ? " وتم تسجيل الدفعة بمبلغ ".number_format($amount)." ر.س."
            : ".";
        $notif_msg = $conn->real_escape_string("تمت الموافقة على طلب تغيير الباقة إلى باقة $pkg_name$pay_msg يمكنك الاستمتاع بالمزايا الجديدة الآن.");
        $conn->query("INSERT INTO notifications (office_id,title,message,type)
            VALUES ($oid,'تمت الموافقة على طلبك','$notif_msg','success')");
    }
    $redirect_msg = ($record_payment && $amount > 0) ? 'approved_paid' : 'approved';
    header("Location: package_requests.php?msg=$redirect_msg"); exit;
}

/* ── رفض ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_id'])) {
    $id   = (int)$_POST['reject_id'];
    $note = $conn->real_escape_string(trim($_POST['admin_note'] ?? ''));
    $conn->query("UPDATE package_requests SET status='rejected', admin_note='$note', processed_at=NOW() WHERE id=$id AND status='pending'");
    /* إشعار للمكتب */
    $req = $conn->query("SELECT office_id FROM package_requests WHERE id=$id")->fetch_assoc();
    if ($req) {
        $oid = (int)$req['office_id'];
        $conn->query("INSERT INTO notifications (office_id,title,message,type)
            VALUES ($oid,'طلب تغيير الباقة','تم رفض طلب تغيير الباقة" . ($note ? ". السبب: " . $conn->real_escape_string($note) : "") . ".','warning')");
    }
    header("Location: package_requests.php?msg=rejected"); exit;
}

/* ── جلب الطلبات ── */
$st_f = $_GET['st'] ?? '';
$where = "1=1";
if ($st_f) $where .= " AND pr.status='".$conn->real_escape_string($st_f)."'";

$requests = $conn->query("
    SELECT pr.*,
           o.name  office_name, o.owner_name, o.phone office_phone,
           o.subscription_end,
           cp.name cur_pkg,
           rp.name req_pkg, rp.price_monthly req_price, rp.price_yearly req_price_yearly
    FROM package_requests pr
    JOIN offices  o  ON pr.office_id            = o.id
    LEFT JOIN packages cp ON pr.current_package_id   = cp.id
    LEFT JOIN packages rp ON pr.requested_package_id = rp.id AND pr.is_custom = 0
    WHERE $where
    ORDER BY pr.created_at DESC
");

/* ── إحصائيات ── */
$cnt_pending  = (int)$conn->query("SELECT COUNT(*) c FROM package_requests WHERE status='pending'")->fetch_assoc()['c'];
$cnt_approved = (int)$conn->query("SELECT COUNT(*) c FROM package_requests WHERE status='approved'")->fetch_assoc()['c'];
$cnt_rejected = (int)$conn->query("SELECT COUNT(*) c FROM package_requests WHERE status='rejected'")->fetch_assoc()['c'];

include '../includes/admin_header.php';
?>

<!-- إحصائيات -->
<div class="row g-3 mb-4">
  <?php foreach([
    ['معلقة',  $cnt_pending,  'clock',        'warning'],
    ['موافَق عليها', $cnt_approved, 'check-circle', 'success'],
    ['مرفوضة', $cnt_rejected, 'times-circle', 'danger'],
    ['الإجمالي', $cnt_pending+$cnt_approved+$cnt_rejected, 'list-alt', 'primary'],
  ] as [$l,$v,$ic,$c]): ?>
  <div class="col-6 col-lg-3">
    <div class="card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle bg-<?=$c?> bg-opacity-10 text-<?=$c?> d-flex align-items-center justify-content-center"
             style="width:50px;height:50px;font-size:20px;flex-shrink:0">
          <i class="fas fa-<?=$ic?>"></i>
        </div>
        <div>
          <div class="text-muted" style="font-size:12px"><?=$l?></div>
          <div class="fw-bold fs-4"><?=$v?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- فلتر -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="GET">
      <div class="col-auto">
        <select name="st" class="form-select form-select-sm">
          <option value="">جميع الحالات</option>
          <option value="pending"  <?= $st_f==='pending' ?'selected':''?>>معلقة</option>
          <option value="approved" <?= $st_f==='approved'?'selected':''?>>موافَق عليها</option>
          <option value="rejected" <?= $st_f==='rejected'?'selected':''?>>مرفوضة</option>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>فلتر</button>
        <a href="package_requests.php" class="btn btn-outline-secondary btn-sm ms-1">إعادة</a>
      </div>
    </form>
  </div>
</div>

<?php
$msg_map = [
  'approved'      => ['success','check-circle','تمت الموافقة وتغيير الباقة بنجاح'],
  'approved_paid' => ['success','check-circle','تمت الموافقة وتسجيل الدفعة بنجاح'],
  'rejected'      => ['warning','times-circle','تم رفض الطلب'],
];
if (isset($_GET['msg']) && isset($msg_map[$_GET['msg']])):
  [$ac,$ai,$at] = $msg_map[$_GET['msg']];
?>
<div class="alert alert-<?= $ac ?> alert-dismissible fade show d-flex align-items-center gap-2 py-2">
  <i class="fas fa-<?= $ai ?>"></i><div><?= $at ?></div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($cnt_pending > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 py-2">
  <i class="fas fa-exclamation-circle fa-lg"></i>
  <div>يوجد <strong><?= $cnt_pending ?></strong> طلب تغيير باقة في انتظار مراجعتك</div>
</div>
<?php endif; ?>

<!-- الجدول -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>المكتب</th>
            <th>من باقة</th>
            <th>إلى باقة</th>
            <th>سبب الطلب</th>
            <th>التاريخ</th>
            <th>الحالة</th>
            <th>إجراءات</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$requests || $requests->num_rows === 0): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">لا توجد طلبات</td></tr>
        <?php else: while ($r = $requests->fetch_assoc()): ?>
        <tr class="<?= $r['status']==='pending'?'table-warning bg-opacity-50':'' ?>">
          <td class="text-muted" style="font-size:12px">#<?= $r['id'] ?></td>
          <td>
            <div class="fw-semibold"><?= e($r['office_name']) ?></div>
            <div style="font-size:11px;color:#888"><?= e($r['owner_name']) ?> &bull; <?= e($r['office_phone']) ?></div>
          </td>
          <td>
            <span class="badge bg-secondary bg-opacity-10 text-secondary" style="font-size:12px">
              <?= e($r['cur_pkg'] ?? 'غير محدد') ?>
            </span>
          </td>
          <td>
            <?php if (!empty($r['is_custom'])): ?>
            <span class="badge" style="background:#7c3aed;font-size:12px">
              <i class="fas fa-puzzle-piece me-1"></i>مخصصة
            </span>
            <div style="font-size:11px;color:#888"><?= number_format($r['custom_price_monthly'] ?? 0) ?> ر.س/شهر (مقدر)</div>
            <?php else: ?>
            <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size:12px">
              <?= e($r['req_pkg']) ?>
            </span>
            <div style="font-size:11px;color:#888"><?= number_format($r['req_price'] ?? 0) ?> ر.س/شهر</div>
            <?php endif; ?>
          </td>
          <td style="max-width:220px">
            <div style="font-size:12px;color:#374151;white-space:pre-line"><?= e(mb_substr($r['reason'] ?? '—', 0, 100)) ?><?= mb_strlen($r['reason'] ?? '') > 100 ? '…' : '' ?></div>
            <?php if ($r['commitment']): ?>
            <span class="badge bg-success-subtle text-success mt-1" style="font-size:10px">
              <i class="fas fa-check-circle me-1"></i>وقّع التعهد
            </span>
            <?php endif; ?>
            <?php if (!empty($r['payment_proof'])): ?>
            <?php $proof_ext = strtolower(pathinfo($r['payment_proof'], PATHINFO_EXTENSION)); ?>
            <div class="mt-1">
              <a href="../<?= e($r['payment_proof']) ?>" target="_blank"
                 class="badge bg-primary-subtle text-primary border border-primary-subtle text-decoration-none"
                 style="font-size:10px">
                <i class="fas fa-<?= $proof_ext === 'pdf' ? 'file-pdf' : 'image' ?> me-1"></i>
                <?= $proof_ext === 'pdf' ? 'عرض الإيصال PDF' : 'عرض الإيصال' ?>
              </a>
            </div>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;white-space:nowrap">
            <?= date('d/m/Y', strtotime($r['created_at'])) ?>
            <div style="color:#9ca3af;font-size:11px"><?= date('H:i', strtotime($r['created_at'])) ?></div>
          </td>
          <td>
            <?php
            $badges = [
              'pending'  => ['warning','clock',        'معلق'],
              'approved' => ['success','check-circle',  'موافَق'],
              'rejected' => ['danger', 'times-circle',  'مرفوض'],
            ];
            [$bc,$bic,$bl] = $badges[$r['status']] ?? ['secondary','circle','—'];
            ?>
            <span class="badge bg-<?=$bc?>-subtle text-<?=$bc?>" style="font-size:11px">
              <i class="fas fa-<?=$bic?> me-1"></i><?=$bl?>
            </span>
            <?php if ($r['admin_note']): ?>
            <div style="font-size:10px;color:#9ca3af;margin-top:3px" title="<?= e($r['admin_note']) ?>">
              <i class="fas fa-comment-alt me-1"></i><?= e(mb_substr($r['admin_note'], 0, 40)) ?>…
            </div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['status'] === 'pending'): ?>
            <div class="d-flex gap-1">
              <?php
              $is_c = !empty($r['is_custom']);
              $cf_arr = $is_c ? (json_decode($r['custom_features']??'{}',true)?:[]) : [];
              $cl_arr = $is_c ? (json_decode($r['custom_limits']??'{}',true)?:[]) : [];
              $cf_json = addslashes(json_encode($cf_arr));
              $cl_json = addslashes(json_encode($cl_arr));
              ?>
              <button class="btn btn-sm btn-success"
                      onclick="openApprove(<?= $r['id'] ?>,'<?= e(addslashes($r['office_name'])) ?>','<?= $is_c ? 'مخصصة' : e(addslashes($r['req_pkg'])) ?>',<?= (float)($is_c ? $r['custom_price_monthly'] : ($r['req_price']??0)) ?>,<?= (float)($is_c ? round((float)$r['custom_price_monthly']*10,2) : ($r['req_price_yearly']??0)) ?>,<?= $r['current_package_id']?1:0 ?>,'<?= e($r['subscription_end']??'') ?>','<?= e(addslashes($r['payment_proof']??'')) ?>','<?= strtolower(pathinfo($r['payment_proof']??'',PATHINFO_EXTENSION)) ?>',<?= $is_c?1:0 ?>,'<?= $cf_json ?>','<?= $cl_json ?>')">
                <i class="fas fa-check me-1"></i>موافقة
              </button>
              <button class="btn btn-sm btn-outline-danger"
                      onclick="openReject(<?= $r['id'] ?>, '<?= e($r['office_name']) ?>')">
                <i class="fas fa-times me-1"></i>رفض
              </button>
            </div>
            <?php else: ?>
            <span class="text-muted" style="font-size:12px">
              <?= $r['processed_at'] ? date('d/m/Y', strtotime($r['processed_at'])) : '—' ?>
            </span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══ Modal الموافقة مع تسجيل الدفعة ═══ -->
<div class="modal fade" id="approveModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#065f46,#059669);border:none">
        <h5 class="modal-title text-white"><i class="fas fa-check-circle me-2"></i>الموافقة على الطلب</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="approveForm">
        <input type="hidden" name="approve_id" id="approve_id">
        <div class="modal-body">

          <!-- معلومات الطلب -->
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:18px">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="fw-bold" id="ap_office" style="font-size:14px"></div>
                <div style="font-size:12px;color:#64748b;margin-top:2px">
                  الباقة المطلوبة: <strong id="ap_pkg" class="text-primary"></strong>
                </div>
              </div>
              <div class="text-end">
                <div style="font-size:11px;color:#94a3b8">السعر</div>
                <div id="ap_price_display" style="font-size:15px;font-weight:700;color:#059669"></div>
              </div>
            </div>
            <!-- مستند الدفع -->
            <div id="ap_proof_wrap" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid #e2e8f0">
              <span style="font-size:12px;color:#64748b;margin-left:6px">مستند الدفع:</span>
              <a id="ap_proof_link" href="#" target="_blank" class="btn btn-sm btn-outline-primary" style="font-size:12px">
                <i id="ap_proof_icon" class="fas fa-file me-1"></i><span id="ap_proof_label">عرض المستند</span>
              </a>
            </div>
          </div>

          <!-- تفاصيل الباقة المخصصة -->
          <div id="ap_custom_wrap" style="display:none;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:8px;padding:12px 14px;margin-bottom:14px">
            <div style="font-size:12px;font-weight:700;color:#5b21b6;margin-bottom:8px">
              <i class="fas fa-puzzle-piece me-1"></i>تفاصيل الباقة المخصصة
            </div>
            <div id="ap_custom_feats" style="font-size:12px;color:#374151;margin-bottom:6px"></div>
            <div id="ap_custom_limits" style="font-size:12px;color:#374151;margin-bottom:8px"></div>
            <div class="row g-2 align-items-center">
              <div class="col-auto">
                <label style="font-size:12px;font-weight:600;color:#5b21b6">السعر النهائي (ر.س/شهر)</label>
              </div>
              <div class="col">
                <input type="number" name="admin_final_price" id="ap_final_price"
                       class="form-control form-control-sm" style="max-width:130px"
                       step="0.01" min="0" placeholder="اتركه فارغاً للإبقاء على السعر المقدر">
              </div>
            </div>
            <div style="font-size:11px;color:#7c3aed;margin-top:4px">
              إذا تركت الحقل فارغاً سيُستخدم السعر المقدر من المكتب
            </div>
          </div>

          <!-- دورة الفوترة -->
          <div class="mb-3">
            <label class="form-label fw-semibold">دورة الفوترة</label>
            <div class="d-flex gap-2">
              <label class="flex-fill" style="cursor:pointer">
                <input type="radio" name="billing" value="monthly" id="bill_monthly" class="d-none" checked>
                <div class="billing-opt text-center p-2 border rounded-3 selected-opt" id="opt_monthly">
                  <i class="fas fa-calendar-day text-primary mb-1 d-block"></i>
                  <div style="font-size:12px;font-weight:600">شهري</div>
                  <div id="ap_price_m" style="font-size:13px;color:#059669;font-weight:700"></div>
                </div>
              </label>
              <label class="flex-fill" style="cursor:pointer">
                <input type="radio" name="billing" value="yearly" id="bill_yearly" class="d-none">
                <div class="billing-opt text-center p-2 border rounded-3" id="opt_yearly">
                  <i class="fas fa-calendar-alt text-warning mb-1 d-block"></i>
                  <div style="font-size:12px;font-weight:600">سنوي</div>
                  <div id="ap_price_y" style="font-size:13px;color:#059669;font-weight:700"></div>
                  <span style="font-size:10px;background:#fef3c7;color:#92400e;border-radius:4px;padding:0 4px">وفّر 17%</span>
                </div>
              </label>
            </div>
          </div>

          <!-- تسجيل الدفعة -->
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="record_payment" id="record_payment" checked>
            <label class="form-check-label fw-semibold" for="record_payment">
              <i class="fas fa-coins text-warning me-1"></i>سجّل الدفعة في الإيرادات
            </label>
          </div>

          <div id="payment_fields">
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label fw-semibold" style="font-size:12px">المبلغ (ر.س)</label>
                <div class="input-group input-group-sm">
                  <input type="number" name="amount" id="ap_amount" class="form-control" step="0.01" min="0.01" required>
                  <span class="input-group-text">ر.س</span>
                </div>
              </div>
              <div class="col-6">
                <label class="form-label fw-semibold" style="font-size:12px">تاريخ الدفع</label>
                <input type="date" name="payment_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold" style="font-size:12px">ملاحظة</label>
              <input type="text" name="notes" class="form-control form-control-sm"
                     placeholder="رقم الإيصال أو أي تفاصيل...">
            </div>
          </div>

          <!-- تمديد الاشتراك -->
          <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" name="extend_subscription" id="extend_sub" checked>
            <label class="form-check-label fw-semibold" for="extend_sub">
              <i class="fas fa-calendar-plus text-info me-1"></i>مدّد الاشتراك تلقائياً
            </label>
          </div>
          <div id="sub_info" style="font-size:11px;color:#64748b;margin-right:2rem;margin-bottom:4px"></div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-success px-4">
            <i class="fas fa-check me-1"></i>تأكيد الموافقة
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.billing-opt { transition:.15s; cursor:pointer; }
.billing-opt:hover { border-color:#94a3b8 !important; }
.selected-opt { border-color:#2563eb !important; background:#eff6ff; }
</style>

<!-- Modal رفض -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger-subtle">
        <h5 class="modal-title text-danger"><i class="fas fa-times-circle me-2"></i>رفض طلب التغيير</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="reject_id" id="rejectId">
          <p class="mb-3">رفض طلب المكتب: <strong id="rejectOfficeName"></strong></p>
          <label class="form-label fw-semibold">سبب الرفض <span class="text-muted fw-normal">(اختياري — سيُرسَل للمكتب)</span></label>
          <textarea name="admin_note" class="form-control" rows="3"
                    placeholder="مثال: الطلب غير مستوفٍ للشروط، يرجى التواصل معنا..."></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-times me-1"></i>تأكيد الرفض</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
var _priceM = 0, _priceY = 0;
var _featLabels = {
  'has_finance':'الشؤون المالية','has_invoices':'الفواتير','has_contracts':'العقود',
  'has_poa':'الوكالات','has_correspondence':'الصادر والوارد','has_library':'المكتبة القانونية',
  'has_archive':'الأرشيف','has_ai':'المساعد الذكي','has_reports':'التقارير','has_api':'API',
  'has_precedents':'السوابق القضائية','has_digital_services':'الخدمات الرقمية'
};
Object.assign(_featLabels, <?= json_encode(custom_pkg_label_map($conn), JSON_UNESCAPED_UNICODE) ?>);

function openApprove(id, officeName, pkgName, priceM, priceY, hasCurPkg, subEnd, proofPath, proofExt, isCustom, cfJson, clJson) {
  _priceM = priceM; _priceY = priceY;
  isCustom = isCustom || 0;

  document.getElementById('approve_id').value = id;
  document.getElementById('ap_office').textContent = officeName;
  document.getElementById('ap_pkg').textContent    = pkgName;

  // مستند الدفع
  var proofWrap = document.getElementById('ap_proof_wrap');
  if (proofPath) {
    document.getElementById('ap_proof_link').href = '../' + proofPath;
    var icon = document.getElementById('ap_proof_icon');
    var lbl  = document.getElementById('ap_proof_label');
    icon.className = 'fas fa-' + (proofExt === 'pdf' ? 'file-pdf' : 'image') + ' me-1';
    lbl.textContent = proofExt === 'pdf' ? 'عرض الإيصال PDF' : 'عرض الإيصال';
    proofWrap.style.display = '';
  } else {
    proofWrap.style.display = 'none';
  }
  document.getElementById('ap_price_m').textContent = formatNum(priceM) + ' ر.س/شهر';
  document.getElementById('ap_price_y').textContent = formatNum(priceY) + ' ر.س/سنة';

  // الباقة المخصصة
  var customWrap = document.getElementById('ap_custom_wrap');
  if (isCustom) {
    var cf = {}, cl = {};
    try { cf = JSON.parse(cfJson); } catch(e) {}
    try { cl = JSON.parse(clJson); } catch(e) {}
    var selFeats = Object.keys(cf).filter(function(k){ return cf[k]; }).map(function(k){ return _featLabels[k]||k; });
    document.getElementById('ap_custom_feats').innerHTML =
      '<strong>الميزات:</strong> ' + (selFeats.length ? selFeats.join('، ') : 'لا توجد ميزات إضافية');
    document.getElementById('ap_custom_limits').innerHTML =
      '<strong>المستخدمون:</strong> ' + (cl.users||'—') +
      ' &bull; <strong>القضايا:</strong> ' + (cl.cases||'—') +
      ' &bull; <strong>التخزين:</strong> ' + (cl.storage_mb||'—') + ' MB';
    document.getElementById('ap_final_price').value = '';
    document.getElementById('ap_final_price').placeholder = formatNum(priceM) + ' ر.س (مقدر)';
    customWrap.style.display = '';
  } else {
    customWrap.style.display = 'none';
  }

  // ضبط المبلغ الافتراضي (شهري)
  document.getElementById('ap_amount').value = priceM;
  document.getElementById('ap_price_display').textContent = formatNum(priceM) + ' ر.س';

  // إعادة ضبط دورة الفوترة على شهري
  document.getElementById('bill_monthly').checked = true;
  document.getElementById('opt_monthly').classList.add('selected-opt');
  document.getElementById('opt_yearly').classList.remove('selected-opt');

  // معلومات تمديد الاشتراك
  var subEl = document.getElementById('sub_info');
  if (subEnd) {
    subEl.textContent = 'الاشتراك الحالي ينتهي في ' + subEnd + ' — سيُمدَّد بشهر (أو سنة حسب الدورة)';
  } else {
    subEl.textContent = 'لا يوجد اشتراك نشط — سيبدأ اشتراك جديد';
  }

  new bootstrap.Modal(document.getElementById('approveModal')).show();
}

function formatNum(n) {
  return Number(n).toLocaleString('ar-SA', {maximumFractionDigits:0});
}

// تبديل دورة الفوترة
document.addEventListener('DOMContentLoaded', function() {
  ['bill_monthly','bill_yearly'].forEach(function(rid) {
    document.getElementById(rid).addEventListener('change', function() {
      var isYearly = (rid === 'bill_yearly');
      document.getElementById('opt_monthly').classList.toggle('selected-opt', !isYearly);
      document.getElementById('opt_yearly').classList.toggle('selected-opt', isYearly);
      var price = isYearly ? _priceY : _priceM;
      document.getElementById('ap_amount').value = price;
      document.getElementById('ap_price_display').textContent = formatNum(price) + ' ر.س';
      // تحديث معلومات التمديد
      var subEl = document.getElementById('sub_info');
      if (subEl.textContent.includes('سيُمدَّد') || subEl.textContent.includes('سيبدأ')) {
        subEl.textContent = subEl.textContent.replace(/سيُمدَّد بشهر.*|سيُمدَّد بسنة.*/, isYearly ? 'سيُمدَّد بسنة' : 'سيُمدَّد بشهر');
      }
    });
  });

  // إظهار/إخفاء حقول الدفعة
  document.getElementById('record_payment').addEventListener('change', function() {
    document.getElementById('payment_fields').style.display = this.checked ? '' : 'none';
    document.getElementById('ap_amount').required = this.checked;
  });

  // كليك على البطاقات للتحديد
  document.querySelectorAll('.billing-opt').forEach(function(opt) {
    opt.addEventListener('click', function() {
      var radio = opt.parentElement.querySelector('input[type=radio]');
      if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change')); }
    });
  });
});

function openReject(id, name) {
  document.getElementById('rejectId').value = id;
  document.getElementById('rejectOfficeName').textContent = name;
  new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
</script>

<?php include '../includes/admin_footer.php'; ?>
