<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
$page_title = 'التنبيهات';
$oid = (int)$_SESSION['office_id'];
$uid = (int)$_SESSION['user_id'];

if (isset($_GET['read'])) {
    $conn->query("UPDATE notifications SET is_read=1 WHERE id=".(int)$_GET['read']." AND (office_id=$oid OR user_id=$uid)");
    header("Location: notifications.php"); exit;
}
if (isset($_GET['read_all'])) {
    $conn->query("UPDATE notifications SET is_read=1 WHERE office_id=$oid OR user_id=$uid");
    header("Location: notifications.php"); exit;
}
if (isset($_GET['delete'])) {
    $conn->query("DELETE FROM notifications WHERE id=".(int)$_GET['delete']." AND (office_id=$oid OR user_id=$uid)");
    header("Location: notifications.php"); exit;
}

$notifs   = $conn->query("SELECT * FROM notifications WHERE (office_id=$oid OR user_id=$uid) ORDER BY created_at DESC");
$unread   = $conn->query("SELECT COUNT(*) c FROM notifications WHERE (office_id=$oid OR user_id=$uid) AND is_read=0")->fetch_assoc()['c'];

include '../includes/office_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <?php if($unread > 0): ?>
    <span class="badge bg-danger fs-6"><?= $unread ?> إشعار غير مقروء</span>
    <?php else: ?>
    <span class="badge bg-success fs-6">جميع الإشعارات مقروءة</span>
    <?php endif; ?>
  </div>
  <a href="notifications.php?read_all=1" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-check-double me-1"></i>تعليم الكل كمقروء
  </a>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="list-group list-group-flush">
    <?php while($n = $notifs->fetch_assoc()):
      $icons = ['info'=>['info-circle','text-info','bg-info'],'warning'=>['exclamation-triangle','text-warning','bg-warning'],'success'=>['check-circle','text-success','bg-success'],'danger'=>['times-circle','text-danger','bg-danger']];
      $ico = $icons[$n['type']] ?? ['bell','text-secondary','bg-secondary'];
    ?>
      <div class="list-group-item d-flex align-items-start gap-3 py-3 px-4 <?= !$n['is_read'] ? 'bg-light border-start border-4 border-primary' : '' ?>">
        <div class="<?= $ico[2] ?> bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center" style="width:42px;height:42px;flex-shrink:0">
          <i class="fas fa-<?= $ico[0] ?> <?= $ico[1] ?>"></i>
        </div>
        <div class="flex-grow-1">
          <div class="d-flex justify-content-between align-items-start">
            <strong style="font-size:14px" class="<?= !$n['is_read']?'text-dark':'text-muted' ?>"><?= e($n['title']) ?></strong>
            <small class="text-muted ms-3 text-nowrap"><?= date('Y/m/d H:i', strtotime($n['created_at'])) ?></small>
          </div>
          <p class="mb-1 mt-1" style="font-size:13px;color:#555"><?= e($n['message']) ?></p>
          <div class="d-flex gap-2">
            <?php if(!$n['is_read']): ?>
            <a href="notifications.php?read=<?= $n['id'] ?>" class="btn btn-xs btn-outline-primary" style="font-size:11px;padding:2px 8px">
              <i class="fas fa-eye me-1"></i>تعليم كمقروء
            </a>
            <?php endif; ?>
            <a href="notifications.php?delete=<?= $n['id'] ?>" class="btn btn-xs btn-outline-danger" style="font-size:11px;padding:2px 8px" onclick="return confirm('حذف؟')">
              <i class="fas fa-trash me-1"></i>حذف
            </a>
          </div>
        </div>
      </div>
    <?php endwhile; ?>
    </div>
  </div>
</div>

<?php include '../includes/office_footer.php'; ?>
