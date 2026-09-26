<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireAdmin();
$page_title = 'نظام الإشعارات';

if (isset($_GET['delete'])) {
    $conn->query("DELETE FROM notifications WHERE id=".(int)$_GET['delete']);
    header("Location: notifications.php"); exit;
}
if (isset($_GET['read_all'])) {
    $conn->query("UPDATE notifications SET is_read=1 WHERE user_id IS NULL");
    header("Location: notifications.php"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title   = $conn->real_escape_string($_POST['title']);
    $msg     = $conn->real_escape_string($_POST['message']);
    $type    = $conn->real_escape_string($_POST['type']);
    $target  = $_POST['target']; // all / specific office
    if ($target === 'all') {
        $offices = $conn->query("SELECT id FROM offices WHERE status='active'");
        while ($o = $offices->fetch_assoc()) {
            $oid = $o['id'];
            $conn->query("INSERT INTO notifications (office_id,title,message,type) VALUES ($oid,'$title','$msg','$type')");
        }
    } else {
        $oid = (int)$_POST['office_id'];
        $conn->query("INSERT INTO notifications (office_id,title,message,type) VALUES ($oid,'$title','$msg','$type')");
    }
    header("Location: notifications.php?msg=sent"); exit;
}

// نعرض فقط إشعارات الإدارة (تسجيل مكاتب جديدة، طلبات/تفعيل باقات، إشعاراتنا اليدوية)
// ونستثني تنبيهات المكاتب الداخلية الموجّهة لموظف بعينه (جلسات، مهام...) لأنها لا تخص مالك المنصة
$notifs  = $conn->query("SELECT n.*, o.name office_name FROM notifications n LEFT JOIN offices o ON n.office_id=o.id WHERE n.user_id IS NULL ORDER BY n.created_at DESC LIMIT 50");
$offices = $conn->query("SELECT id,name FROM offices WHERE status='active' ORDER BY name");

include '../includes/admin_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم إرسال الإشعار بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="fas fa-paper-plane me-2 text-primary"></i>إرسال إشعار جديد</div>
      <div class="card-body">
        <form method="POST">
          <div class="mb-3">
            <label class="form-label fw-semibold">العنوان *</label>
            <input type="text" name="title" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">الرسالة *</label>
            <textarea name="message" class="form-control" rows="3" required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">النوع</label>
            <select name="type" class="form-select">
              <option value="info">معلومة</option>
              <option value="warning">تحذير</option>
              <option value="success">نجاح</option>
              <option value="danger">تنبيه عاجل</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">الإرسال إلى</label>
            <select name="target" class="form-select" id="targetSelect" onchange="toggleOffice(this.value)">
              <option value="all">جميع المكاتب النشطة</option>
              <option value="specific">مكتب محدد</option>
            </select>
          </div>
          <div class="mb-3 d-none" id="officeSelect">
            <label class="form-label fw-semibold">اختر المكتب</label>
            <select name="office_id" class="form-select">
              <?php while ($o = $offices->fetch_assoc()): ?>
              <option value="<?= $o['id'] ?>"><?= e($o['name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary w-100"><i class="fas fa-paper-plane me-1"></i>إرسال</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <span><i class="fas fa-bell me-2"></i>سجل الإشعارات</span>
        <a href="notifications.php?read_all=1" class="btn btn-sm btn-outline-secondary">تعليم الكل كمقروء</a>
      </div>
      <div class="card-body p-0">
        <div class="list-group list-group-flush">
        <?php while($n = $notifs->fetch_assoc()): ?>
          <?php
            $icons = ['info'=>'info-circle text-info','warning'=>'exclamation-triangle text-warning','success'=>'check-circle text-success','danger'=>'times-circle text-danger'];
            $icon = $icons[$n['type']] ?? 'bell text-secondary';
          ?>
          <div class="list-group-item d-flex align-items-start gap-3 <?= $n['is_read']?'':'bg-light' ?>">
            <i class="fas fa-<?= $icon ?> mt-1" style="font-size:18px"></i>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between">
                <strong style="font-size:14px"><?= e($n['title']) ?></strong>
                <small class="text-muted"><?= date('Y/m/d H:i', strtotime($n['created_at'])) ?></small>
              </div>
              <div style="font-size:13px;color:#555"><?= e($n['message']) ?></div>
              <?php if ($n['office_name']): ?>
              <small class="text-muted"><i class="fas fa-building me-1"></i><?= e($n['office_name']) ?></small>
              <?php endif; ?>
            </div>
            <a href="notifications.php?delete=<?= $n['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف؟')">
              <i class="fas fa-trash"></i>
            </a>
          </div>
        <?php endwhile; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function toggleOffice(v) {
    document.getElementById('officeSelect').classList.toggle('d-none', v !== 'specific');
}
</script>

<?php include '../includes/admin_footer.php'; ?>
