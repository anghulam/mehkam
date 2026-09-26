<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (currentRole() !== 'office_owner') { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'سجل الإجراءات';
$oid = (int)$_SESSION['office_id'];

try {
    $conn->query("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, user_id INT DEFAULT NULL,
        user_name VARCHAR(200) DEFAULT NULL, action VARCHAR(30) NOT NULL, entity VARCHAR(30) NOT NULL,
        entity_id INT DEFAULT NULL, summary VARCHAR(400) DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_office_time (office_id, created_at), INDEX idx_entity (entity, entity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

$page  = max(1, (int)($_GET['p'] ?? 1));
$per   = 40;
$off   = ($page - 1) * $per;

$where = "office_id=$oid";
if (!empty($_GET['u'])) $where .= " AND user_id=" . (int)$_GET['u'];
if (!empty($_GET['e'])) $where .= " AND entity='" . $conn->real_escape_string($_GET['e']) . "'";
if (!empty($_GET['a'])) $where .= " AND action='" . $conn->real_escape_string($_GET['a']) . "'";

$total = (int)dbVal($conn, "SELECT COUNT(*) FROM activity_log WHERE $where");
$rows  = $conn->query("SELECT * FROM activity_log WHERE $where ORDER BY id DESC LIMIT $per OFFSET $off");
$users = $conn->query("SELECT id, full_name FROM users WHERE office_id=$oid ORDER BY full_name");

$actLbl = ['create'=>'إضافة','update'=>'تعديل','delete'=>'حذف','status'=>'تغيير حالة','approve'=>'اعتماد','login'=>'دخول','export'=>'تصدير'];
$entLbl = ['case'=>'قضية','client'=>'عميل','session'=>'جلسة','task'=>'مهمة','appointment'=>'موعد','contract'=>'عقد','poa'=>'وكالة','invoice'=>'فاتورة','correspondence'=>'مراسلة','user'=>'مستخدم','precedent'=>'سابقة','archive'=>'أرشيف','library'=>'مكتبة'];

include '../includes/office_header.php';
?>

<form class="card mb-3"><div class="card-body py-2">
  <div class="row g-2 align-items-end">
    <div class="col-auto">
      <label class="form-label mb-0" style="font-size:12px">المستخدم</label>
      <select name="u" class="form-select form-select-sm">
        <option value="">الكل</option>
        <?php if ($users) while ($u = $users->fetch_assoc()): ?>
        <option value="<?= $u['id'] ?>" <?= ($_GET['u']??'')==$u['id']?'selected':'' ?>><?= e($u['full_name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label mb-0" style="font-size:12px">القسم</label>
      <select name="e" class="form-select form-select-sm">
        <option value="">الكل</option>
        <?php foreach ($entLbl as $k=>$v): ?><option value="<?= $k ?>" <?= ($_GET['e']??'')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label mb-0" style="font-size:12px">الإجراء</label>
      <select name="a" class="form-select form-select-sm">
        <option value="">الكل</option>
        <?php foreach ($actLbl as $k=>$v): ?><option value="<?= $k ?>" <?= ($_GET['a']??'')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-primary btn-sm">تصفية</button>
      <a href="activity.php" class="btn btn-outline-secondary btn-sm">إعادة</a>
    </div>
  </div>
</div></form>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" style="font-size:13px">
        <thead class="table-light"><tr>
          <th>التاريخ</th><th>المستخدم</th><th>الإجراء</th><th>القسم</th><th>التفاصيل</th><th>IP</th>
        </tr></thead>
        <tbody>
        <?php if ($rows && $rows->num_rows): while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td style="white-space:nowrap"><?= dDate($r['created_at'], true, false) ?></td>
          <td><?= e($r['user_name'] ?: '—') ?></td>
          <td><span class="badge bg-<?= $r['action']==='delete'?'danger':($r['action']==='create'?'success':'secondary') ?>"><?= $actLbl[$r['action']] ?? $r['action'] ?></span></td>
          <td><?= $entLbl[$r['entity']] ?? $r['entity'] ?><?= $r['entity_id'] ? ' #' . $r['entity_id'] : '' ?></td>
          <td><?= e($r['summary']) ?></td>
          <td class="text-muted" style="font-size:11px"><?= e($r['ip']) ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="6" class="text-center text-muted py-4">لا توجد سجلات</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$pages = (int)ceil($total / $per);
if ($pages > 1):
  $qs = $_GET; ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
  <?php for ($i = max(1, $page-3); $i <= min($pages, $page+3); $i++): $qs['p']=$i; ?>
  <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="?<?= http_build_query($qs) ?>"><?= $i ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
