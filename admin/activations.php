<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/affiliate_helper.php';
requireAdmin();
$page_title = 'تفعيل المكاتب';

// التأكد من وجود أعمدة الدفع في جدول offices (توافق مع قواعد بيانات قديمة)
foreach ([
    "ALTER TABLE offices ADD COLUMN billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly'",
    "ALTER TABLE offices ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'bank'",
    "ALTER TABLE offices ADD COLUMN payment_ref VARCHAR(500) DEFAULT NULL",
] as $_ddl) {
    try { $conn->query($_ddl); } catch (\Throwable $e) {}
}

// تفعيل مكتب
if (isset($_GET['activate'])) {
    $id = (int)$_GET['activate'];
    // استخدم دورة الدفع المختارة من المكتب
    $bc = 'monthly';
    try {
        $bc_row = $conn->query("SELECT billing_cycle FROM offices WHERE id=$id LIMIT 1")->fetch_assoc();
        $bc = ($bc_row['billing_cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
    } catch (\Throwable $e) {}
    $end_expr = $bc === 'yearly'
        ? "DATE_ADD(CURDATE(), INTERVAL 1 YEAR)"
        : "DATE_ADD(CURDATE(), INTERVAL 1 MONTH)";
    $conn->query("UPDATE offices SET status='active',
        subscription_start=CURDATE(),
        subscription_end=$end_expr
        WHERE id=$id");
    // تفعيل المستخدم
    $conn->query("UPDATE users SET is_active=1 WHERE office_id=$id");
    // إشعار
    $o = $conn->query("SELECT name FROM offices WHERE id=$id")->fetch_assoc();
    $oname = $conn->real_escape_string($o['name']??'');

    // عمولة الأفلييت — تُحتسب من قيمة أول تفعيل (سعر الباقة حسب دورة الفوترة)
    $pkg_r = $conn->query("SELECT p.price_monthly, p.price_yearly FROM offices o JOIN packages p ON o.package_id=p.id WHERE o.id=$id LIMIT 1");
    if ($pkg_r && $pkg_row = $pkg_r->fetch_assoc()) {
        $first_amount = $bc === 'yearly' ? (float)$pkg_row['price_yearly'] : (float)$pkg_row['price_monthly'];
        affiliate_credit_conversion($conn, $id, $first_amount);
    }
    $conn->query("INSERT INTO notifications (office_id,title,message,type)
        SELECT id,'تم تفعيل حسابك','مرحباً! تم تفعيل حساب مكتبكم على منصة LawSaaS. يمكنكم البدء الآن.','success'
        FROM offices WHERE id=$id");
    header("Location: activations.php?msg=activated"); exit;
}

// رفض / تعليق
if (isset($_GET['suspend'])) {
    $id = (int)$_GET['suspend'];
    $conn->query("UPDATE offices SET status='suspended' WHERE id=$id");
    $conn->query("UPDATE users SET is_active=0 WHERE office_id=$id");
    header("Location: activations.php?msg=suspended"); exit;
}

// تمديد الاشتراك
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['extend_id'])) {
    $id     = (int)$_POST['extend_id'];
    $months = (int)$_POST['months'];
    $conn->query("UPDATE offices SET
        subscription_end=DATE_ADD(IFNULL(subscription_end,CURDATE()), INTERVAL $months MONTH),
        status='active'
        WHERE id=$id");
    $conn->query("UPDATE users SET is_active=1 WHERE office_id=$id");
    header("Location: activations.php?msg=extended"); exit;
}

// الفلتر
$where = "1=1";
if (!empty($_GET['status_f'])) $where .= " AND o.status='".$conn->real_escape_string($_GET['status_f'])."'";
if (!empty($_GET['q'])) {
    $q = $conn->real_escape_string($_GET['q']);
    $where .= " AND (o.name LIKE '%$q%' OR o.owner_name LIKE '%$q%' OR o.phone LIKE '%$q%')";
}

$offices = $conn->query("SELECT o.*,p.name pkg_name,p.price_yearly,
    (SELECT COUNT(*) FROM users WHERE office_id=o.id) users_count
    FROM offices o LEFT JOIN packages p ON o.package_id=p.id
    WHERE $where ORDER BY o.created_at DESC");

// إحصائيات
$pending  = $conn->query("SELECT COUNT(*) c FROM offices WHERE status='suspended'")->fetch_assoc()['c'];
$active   = $conn->query("SELECT COUNT(*) c FROM offices WHERE status='active'")->fetch_assoc()['c'];
$expired  = $conn->query("SELECT COUNT(*) c FROM offices WHERE status='expired'")->fetch_assoc()['c'];
$expiring = $conn->query("SELECT COUNT(*) c FROM offices WHERE status='active' AND subscription_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)")->fetch_assoc()['c'];

include '../includes/admin_header.php';
?>

<?php if(isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>
  <?= ['activated'=>'تم تفعيل المكتب بنجاح','suspended'=>'تم تعليق المكتب','extended'=>'تم تمديد الاشتراك'][$_GET['msg']] ?? 'تمت العملية' ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- إحصائيات -->
<div class="row g-3 mb-4">
  <?php foreach([
    ['في انتظار التفعيل',$pending,'hourglass-half','warning'],
    ['نشطة',$active,'check-circle','success'],
    ['تنتهي خلال 7 أيام',$expiring,'exclamation-triangle','danger'],
    ['منتهية الاشتراك',$expired,'times-circle','secondary'],
  ] as [$l,$v,$ic,$c]): ?>
  <div class="col-6 col-lg-3">
    <div class="card">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle bg-<?=$c?> bg-opacity-10 text-<?=$c?> d-flex align-items-center justify-content-center" style="width:50px;height:50px;font-size:20px;flex-shrink:0">
          <i class="fas fa-<?=$ic?>"></i>
        </div>
        <div><div class="text-muted" style="font-size:12px"><?=$l?></div><div class="fw-bold fs-4"><?=$v?></div></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if($pending > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-2">
  <i class="fas fa-exclamation-circle fa-lg"></i>
  <div>يوجد <strong><?=$pending?></strong> مكتب في انتظار مراجعة وتفعيل الاشتراك</div>
</div>
<?php endif; ?>

<!-- فلتر -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="GET">
      <div class="col-auto flex-grow-1">
        <input type="text" name="q" class="form-control" placeholder="بحث بالاسم أو المالك..." value="<?=e($_GET['q']??'')?>">
      </div>
      <div class="col-auto">
        <select name="status_f" class="form-select">
          <option value="">جميع الحالات</option>
          <option value="suspended" <?=($_GET['status_f']??'')==='suspended'?'selected':''?>>في انتظار التفعيل</option>
          <option value="active"    <?=($_GET['status_f']??'')==='active'?'selected':''?>>نشطة</option>
          <option value="expired"   <?=($_GET['status_f']??'')==='expired'?'selected':''?>>منتهية</option>
          <option value="trial"     <?=($_GET['status_f']??'')==='trial'?'selected':''?>>تجريبية</option>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary"><i class="fas fa-search me-1"></i>بحث</button>
        <a href="activations.php" class="btn btn-outline-secondary ms-1">إعادة</a>
      </div>
    </form>
  </div>
</div>

<!-- الجدول -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>المكتب</th><th>المالك</th><th>الباقة</th><th>الرسوم السنوية</th>
          <th>المستخدمون</th><th>انتهاء الاشتراك</th><th>الحالة</th><th>إجراءات</th>
        </tr></thead>
        <tbody>
        <?php while($o=$offices->fetch_assoc()):
          $expiring_soon = $o['status']==='active' && $o['subscription_end'] && strtotime($o['subscription_end']) < strtotime('+7 days');
        ?>
        <tr class="<?=$o['status']==='suspended'?'table-warning':($expiring_soon?'table-danger bg-opacity-10':'')?>">
          <td>
            <div class="fw-semibold"><?=e($o['name'])?></div>
            <div style="font-size:11px;color:#888"><?=e($o['city']??'').'  •  '.e($o['email']??'')?></div>
          </td>
          <td>
            <div><?=e($o['owner_name']??'')?></div>
            <div style="font-size:11px;color:#888"><?=e($o['phone']??'')?></div>
          </td>
          <td><span class="badge bg-primary bg-opacity-10 text-primary"><?=e($o['pkg_name']??'—')?></span></td>
          <td class="fw-semibold"><?=number_format($o['price_yearly']??0)?> <small class="text-muted">ر.س</small></td>
          <td><span class="badge bg-secondary"><?=$o['users_count']?></span></td>
          <td>
            <?php if($o['subscription_end']): ?>
              <?php if($expiring_soon): ?>
                <span class="text-danger fw-bold"><?=date('Y/m/d',strtotime($o['subscription_end']))?></span>
                <i class="fas fa-exclamation-circle text-danger ms-1"></i>
              <?php else: ?>
                <?=date('Y/m/d',strtotime($o['subscription_end']))?>
              <?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?=statusBadge($o['status'])?></td>
          <td>
            <div class="d-flex gap-1 flex-wrap">
              <?php if($o['status']==='suspended'): ?>
              <a href="activations.php?activate=<?=$o['id']?>"
                 class="btn btn-sm btn-success"
                 onclick="return confirm('تأكيد تفعيل المكتب؟')">
                <i class="fas fa-check me-1"></i>تفعيل
              </a>
              <?php elseif($o['status']==='active'): ?>
              <button class="btn btn-sm btn-outline-warning"
                      onclick="openExtend(<?=$o['id']?>, '<?=e($o['name'])?>')">
                <i class="fas fa-sync me-1"></i>تمديد
              </button>
              <a href="activations.php?suspend=<?=$o['id']?>"
                 class="btn btn-sm btn-outline-danger"
                 onclick="return confirm('تعليق هذا المكتب؟')">
                <i class="fas fa-pause me-1"></i>تعليق
              </a>
              <?php else: ?>
              <a href="activations.php?activate=<?=$o['id']?>"
                 class="btn btn-sm btn-outline-success"
                 onclick="return confirm('تفعيل؟')">
                <i class="fas fa-play"></i>
              </a>
              <?php endif; ?>
              <a href="offices.php?edit=<?=$o['id']?>" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-edit"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal تمديد -->
<div class="modal fade" id="extendModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-sync me-2"></i>تمديد اشتراك المكتب</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="extend_id" id="extendId">
          <p class="mb-3">تمديد اشتراك: <strong id="extendName"></strong></p>
          <label class="form-label fw-semibold">عدد الأشهر</label>
          <select name="months" class="form-select">
            <option value="1">شهر واحد</option>
            <option value="3">3 أشهر</option>
            <option value="6">6 أشهر</option>
            <option value="12" selected>سنة كاملة (12 شهر)</option>
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>تأكيد التمديد</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openExtend(id, name) {
  document.getElementById('extendId').value = id;
  document.getElementById('extendName').textContent = name;
  new bootstrap.Modal(document.getElementById('extendModal')).show();
}
</script>

<?php include '../includes/admin_footer.php'; ?>
