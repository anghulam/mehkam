<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('tasks','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'المهام والمواعيد';
$oid = (int)$_SESSION['office_id'];
$tab = $_GET['tab'] ?? 'tasks';

// أعمدة الإسناد (توافق)
foreach ([
    "ALTER TABLE tasks ADD COLUMN assigned_to_id INT DEFAULT NULL",
    "ALTER TABLE tasks ADD COLUMN completed_at DATETIME DEFAULT NULL",
    // إضافة الوقت لموعد استحقاق المهمة (كان تاريخاً بلا وقت) — توسيع آمن، لا يفقد القيم القديمة
    "ALTER TABLE tasks MODIFY COLUMN due_date DATETIME DEFAULT NULL",
    // تكليف المواعيد بموظّف — بنفس مبدأ إسناد المهام
    "ALTER TABLE appointments ADD COLUMN assigned_to_id INT DEFAULT NULL",
] as $_d) { try { $conn->query($_d); } catch (\Throwable $e) {} }

// «مدير المهام والمواعيد»: صلاحية مستقلة (اعتماد) يمنحها مالك المكتب لموظّف محدَّد فقط —
// غير مرتبطة تلقائياً بصلاحية «إضافة» حتى لا يصبح كل موظف يملك إضافة مديراً بالصدفة.
// من يملكها: يُسند المهام/المواعيد لأي موظف، ويرى مهام وموعد الجميع هنا وفي التقارير.
// غيره: يرى وتُسنَد له تلقائياً مهامه/مواعيده الخاصة فقط، ولا يقدر يكلّف زميلاً.
$_isTaskMgr = can('tasks', 'approve');

if (isset($_GET['delete_t'])) {
    $conn->query("DELETE FROM tasks WHERE id=".(int)$_GET['delete_t']." AND office_id=$oid");
    logAction($conn, 'delete', 'task', (int)$_GET['delete_t'], 'حذف مهمة');
    header("Location: tasks.php?tab=tasks&msg=deleted"); exit;
}
if (isset($_GET['delete_a'])) {
    $conn->query("DELETE FROM appointments WHERE id=".(int)$_GET['delete_a']." AND office_id=$oid");
    logAction($conn, 'delete', 'appointment', (int)$_GET['delete_a'], 'حذف موعد');
    header("Location: tasks.php?tab=appointments&msg=deleted"); exit;
}
if (isset($_GET['complete'])) {
    $conn->query("UPDATE tasks SET status='completed', completed_at=NOW() WHERE id=".(int)$_GET['complete']." AND office_id=$oid");
    logAction($conn, 'status', 'task', (int)$_GET['complete'], 'إنجاز مهمة');
    header("Location: tasks.php?tab=tasks"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['form_type'] === 'task') {
        $title  = $conn->real_escape_string($_POST['title']);
        $assign = $conn->real_escape_string($_POST['assigned_to'] ?? '');
        // غير مدير المهام لا يقدر يكلّف زميلاً — تُسنَد له هو دائماً بصرف النظر عمّا أُرسل من النموذج
        // (النموذج أصلاً يخفي خانة التكليف عنه، وهذا تحقّق من طرف الخادم لا يُعتمَد فيه على العرض وحده)
        $aid = $_isTaskMgr ? (!empty($_POST['assigned_to_id']) ? (int)$_POST['assigned_to_id'] : 'NULL') : (int)($_SESSION['user_id'] ?? 0);
        // موظّف مُقيَّد النطاق لم يحدّد مكلَّفاً → يُسنَد لنفسه تلقائياً، وإلا تختفي مهمته من قائمته المقيَّدة
        if ($aid === 'NULL' && isRestricted()) $aid = (int)($_SESSION['user_id'] ?? 0);
        // لو اختار مستخدماً، خزّن اسمه في assigned_to أيضاً للعرض القديم
        if ($aid !== 'NULL') {
            $ur = $conn->query("SELECT full_name FROM users WHERE id=$aid AND office_id=$oid LIMIT 1");
            if ($ur && $urow = $ur->fetch_assoc()) $assign = $conn->real_escape_string($urow['full_name']);
        }
        $prio   = $conn->real_escape_string($_POST['priority']);
        $status = $conn->real_escape_string($_POST['status']);
        $due_raw = str_replace('T', ' ', trim($_POST['due_date'] ?? ''));
        $due    = $due_raw !== '' ? "'".$conn->real_escape_string($due_raw)."'" : 'NULL';
        $done_sql = $status === 'completed' ? 'NOW()' : 'NULL';
        if (!empty($_POST['id'])) {
            $id = (int)$_POST['id'];
            $conn->query("UPDATE tasks SET title='$title',assigned_to='$assign',assigned_to_id=$aid,priority='$prio',status='$status',due_date=$due,
                completed_at=CASE WHEN '$status'='completed' AND completed_at IS NULL THEN NOW() WHEN '$status'<>'completed' THEN NULL ELSE completed_at END
                WHERE id=$id AND office_id=$oid");
            logAction($conn, 'update', 'task', $id, 'تعديل مهمة: ' . mb_substr($_POST['title'], 0, 80));
        } else {
            $conn->query("INSERT INTO tasks (office_id,title,assigned_to,assigned_to_id,priority,status,due_date,completed_at)
                VALUES ($oid,'$title','$assign',$aid,'$prio','$status',$due,$done_sql)");
            logAction($conn, 'create', 'task', $conn->insert_id, 'مهمة جديدة: ' . mb_substr($_POST['title'], 0, 80));
        }
        header("Location: tasks.php?tab=tasks&msg=saved"); exit;
    }
    if ($_POST['form_type'] === 'appointment') {
        $title  = $conn->real_escape_string($_POST['title']);
        $adate_raw = str_replace('T', ' ', trim($_POST['appointment_date'] ?? ''));
        $adate_sql = $adate_raw !== '' ? "'".$conn->real_escape_string($adate_raw)."'" : 'NULL';
        $loc    = $conn->real_escape_string($_POST['location']);
        $type   = $conn->real_escape_string($_POST['type']);
        $status = $conn->real_escape_string($_POST['status']);
        $cid    = (int)$_POST['client_id'];
        // غير مدير المهام لا يقدر يكلّف زميلاً بموعد — يُسنَد له هو دائماً (نفس مبدأ المهام أعلاه)
        $aid = $_isTaskMgr && !empty($_POST['assigned_to_id']) ? (int)$_POST['assigned_to_id'] : (int)($_SESSION['user_id'] ?? 0);
        if (!empty($_POST['id'])) {
            $id = (int)$_POST['id'];
            $conn->query("UPDATE appointments SET title='$title',appointment_date=$adate_sql,location='$loc',type='$type',status='$status',client_id=$cid,assigned_to_id=$aid WHERE id=$id AND office_id=$oid");
            logAction($conn, 'update', 'appointment', $id, 'تعديل موعد: ' . mb_substr($_POST['title'], 0, 80));
        } else {
            $conn->query("INSERT INTO appointments (office_id,title,appointment_date,location,type,status,client_id,assigned_to_id) VALUES ($oid,'$title',$adate_sql,'$loc','$type','$status',$cid,$aid)");
            logAction($conn, 'create', 'appointment', $conn->insert_id, 'موعد جديد: ' . mb_substr($_POST['title'], 0, 80));
        }
        header("Location: tasks.php?tab=appointments&msg=saved"); exit;
    }
}

// من ليس مديراً للمهام والمواعيد يرى فقط ما يخصه (بالمعرّف أو بالاسم للمهام القديمة)
// مدير المهام (can('tasks','approve')) يرى كل شيء بلا قيد — نفس آلية caseScope/finScope في بقية النظام
// ويقدر أيضاً يفلتر القائمتين على موظّف واحد محدَّد (emp_f) بدل الكل
$_empFilter = $_isTaskMgr ? (int)($_GET['emp_f'] ?? 0) : 0;
$_myTaskScope = '';
$_apptScope   = '';
if (!$_isTaskMgr) {
    $_uid = (int)($_SESSION['user_id'] ?? 0);
    $_uname = $conn->real_escape_string($_SESSION['full_name'] ?? '');
    $_delegFrom = '';
    // موديول تفويض الغياب: أثناء غياب زميل مُفوِّض لي، تظهر مهامه ومواعيده ضمن قائمتي أيضاً
    if (hasModule($conn, $oid, 'absence_delegation')) {
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS absence_delegations (
                id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, from_user_id INT NOT NULL, to_user_id INT NOT NULL,
                start_date DATE NOT NULL, end_date DATE NOT NULL, reason VARCHAR(255) DEFAULT NULL,
                status ENUM('active','ended') DEFAULT 'active', created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_office (office_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $_delegFrom = "SELECT from_user_id FROM absence_delegations WHERE office_id=$oid AND to_user_id=$_uid AND status='active' AND CURDATE() BETWEEN start_date AND end_date";
        } catch (\Throwable $e) {}
    }
    $_myTaskScope = " AND (assigned_to_id=$_uid OR (assigned_to_id IS NULL AND assigned_to='$_uname')" . ($_delegFrom !== '' ? " OR assigned_to_id IN ($_delegFrom)" : '') . ")";
    $_apptScope   = " AND (a.assigned_to_id=$_uid" . ($_delegFrom !== '' ? " OR a.assigned_to_id IN ($_delegFrom)" : '') . ")";
} elseif ($_empFilter) {
    $_myTaskScope = " AND assigned_to_id=$_empFilter";
    $_apptScope   = " AND a.assigned_to_id=$_empFilter";
}
$tasks        = $conn->query("SELECT * FROM tasks WHERE office_id=$oid$_myTaskScope ORDER BY FIELD(status,'in_progress','pending','completed','cancelled'), due_date ASC");
$appointments = $conn->query("SELECT a.*, cl.full_name client_name, au.full_name assignee_name
    FROM appointments a
    LEFT JOIN clients cl ON a.client_id=cl.id
    LEFT JOIN users au   ON a.assigned_to_id=au.id
    WHERE a.office_id=$oid$_apptScope ORDER BY a.appointment_date DESC");
$clients      = $conn->query("SELECT id,full_name FROM clients WHERE office_id=$oid ORDER BY full_name");
$clients_arr  = [0=>'-- بدون عميل --'];
while ($cl = $clients->fetch_assoc()) $clients_arr[$cl['id']] = $cl['full_name'];

// موظفو المكتب — لقائمة «تكليف» في نافذتي المهمة والموعد (تُعرض فقط لمدير المهام)
$_officeStaff = [];
if ($_isTaskMgr) {
    $_sr = $conn->query("SELECT id,full_name FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
    if ($_sr) while ($_su = $_sr->fetch_assoc()) $_officeStaff[] = $_su;
}

$edit_t = null; $edit_a = null;
if (isset($_GET['edit_t'])) $edit_t = $conn->query("SELECT * FROM tasks WHERE id=".(int)$_GET['edit_t']." AND office_id=$oid")->fetch_assoc();
if (isset($_GET['edit_a'])) $edit_a = $conn->query("SELECT * FROM appointments WHERE id=".(int)$_GET['edit_a']." AND office_id=$oid")->fetch_assoc();

// إحصائيات المهام والمواعيد
$pending_count   = $conn->query("SELECT COUNT(*) c FROM tasks WHERE office_id=$oid AND status='pending'$_myTaskScope")->fetch_assoc()['c'];
$progress_count  = $conn->query("SELECT COUNT(*) c FROM tasks WHERE office_id=$oid AND status='in_progress'$_myTaskScope")->fetch_assoc()['c'];
$done_count      = $conn->query("SELECT COUNT(*) c FROM tasks WHERE office_id=$oid AND status='completed'$_myTaskScope")->fetch_assoc()['c'];
$today_appts     = $conn->query("SELECT COUNT(*) c FROM appointments a WHERE a.office_id=$oid AND DATE(a.appointment_date)=CURDATE()$_apptScope")->fetch_assoc()['c'];

include '../includes/office_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- إحصائيات -->
<div class="row g-3 mb-3">
  <?php foreach([['معلقة',$pending_count,'clock','warning'],['جارية',$progress_count,'spinner','primary'],['مكتملة',$done_count,'check-circle','success'],['مواعيد اليوم',$today_appts,'calendar-day','info']] as [$l,$v,$ic,$col]): ?>
  <div class="col-6 col-lg-3">
    <div class="card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="rounded-circle bg-<?= $col ?> bg-opacity-10 text-<?= $col ?> d-flex align-items-center justify-content-center" style="width:46px;height:46px;font-size:18px;flex-shrink:0">
          <i class="fas fa-<?= $ic ?>"></i>
        </div>
        <div>
          <div class="text-muted" style="font-size:12px"><?= $l ?></div>
          <div class="fw-bold fs-5"><?= $v ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php $_empQS = $_empFilter ? '&emp_f=' . $_empFilter : ''; ?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='tasks'?'active':'' ?>" href="tasks.php?tab=tasks<?= $_empQS ?>"><i class="fas fa-tasks me-1"></i>المهام</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='appointments'?'active':'' ?>" href="tasks.php?tab=appointments<?= $_empQS ?>"><i class="fas fa-calendar-alt me-1"></i>المواعيد</a></li>
</ul>

<?php if (!$_isTaskMgr): ?>
<div class="alert alert-light border mb-3" style="font-size:12.5px">
  <i class="fas fa-circle-info me-1 text-primary"></i>تظهر لك هنا مهامك ومواعيدك الخاصة أو المُوكَلة إليك فقط.
</div>
<?php else: ?>
<div class="card mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="GET">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="col-auto">
        <label class="form-label form-label-sm mb-0 me-1"><i class="fas fa-user-group me-1 text-muted"></i>فلترة حسب الموظف</label>
      </div>
      <div class="col-auto">
        <select name="emp_f" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="0">كل الموظفين</option>
          <?php foreach ($_officeStaff as $_u): ?>
          <option value="<?= $_u['id'] ?>" <?= $_empFilter===(int)$_u['id']?'selected':'' ?>><?= e($_u['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($_empFilter): ?>
      <div class="col-auto"><a href="tasks.php?tab=<?= e($tab) ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-rotate-right me-1"></i>إلغاء الفلتر</a></div>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php endif; ?>

<style>
.tk-card{background:#fff;border:1px solid #eef1f6;border-radius:14px;padding:16px;height:100%;display:flex;flex-direction:column;transition:box-shadow .15s}
.tk-card:hover{box-shadow:0 4px 16px rgba(12,27,54,.08)}
.tk-card.is-done{background:#f8fafc}
.tk-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}
.tk-title{font-weight:600;font-size:14.5px;line-height:1.4}
.tk-badges{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.tk-meta{display:flex;align-items:center;gap:6px;font-size:12.5px;color:#64748b;margin-top:10px}
.tk-foot{margin-top:auto;padding-top:12px;display:flex;gap:6px;justify-content:flex-end}
</style>

<!-- المهام -->
<div class="<?= $tab!=='tasks'?'d-none':'' ?>">
  <div class="d-flex justify-content-end mb-3">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#taskModal">
      <i class="fas fa-plus me-1"></i>إضافة مهمة
    </button>
  </div>
  <div class="row g-3">
  <?php $_anyTask = false; while($t = $tasks->fetch_assoc()): $_anyTask = true;
    $overdue = $t['due_date'] && strtotime($t['due_date']) < time() && $t['status']!=='completed'; ?>
    <div class="col-md-6 col-lg-4">
      <div class="tk-card <?= $t['status']==='completed'?'is-done':'' ?>">
        <div class="tk-top">
          <div class="tk-title <?= $t['status']==='completed'?'text-decoration-line-through text-muted':'' ?>"><?= e($t['title']) ?></div>
        </div>
        <div class="tk-badges">
          <?= priorityBadge($t['priority']) ?>
          <?= statusBadge($t['status']) ?>
        </div>
        <?php if ($t['assigned_to']): ?>
        <div class="tk-meta"><i class="fas fa-user text-muted" style="width:14px"></i><?= e($t['assigned_to']) ?></div>
        <?php endif; ?>
        <div class="tk-meta <?= $overdue?'text-danger fw-bold':'' ?>">
          <i class="fas fa-<?= $overdue?'triangle-exclamation':'clock' ?>" style="width:14px"></i>
          <?= $t['due_date'] ? e(dDate($t['due_date'], true)) : 'بدون موعد نهائي' ?>
        </div>
        <div class="tk-foot">
          <?php if($t['status']!=='completed'): ?>
          <a href="tasks.php?tab=tasks&complete=<?= $t['id'] ?>" class="btn btn-sm btn-outline-success" title="إتمام"><i class="fas fa-check"></i></a>
          <?php endif; ?>
          <a href="tasks.php?tab=tasks&edit_t=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary" title="تعديل"><i class="fas fa-edit"></i></a>
          <a href="tasks.php?tab=tasks&delete_t=<?= $t['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف؟')" title="حذف"><i class="fas fa-trash"></i></a>
        </div>
      </div>
    </div>
  <?php endwhile; ?>
  <?php if (!$_anyTask): ?><div class="col-12"><div class="text-muted text-center py-5">لا توجد مهام بعد</div></div><?php endif; ?>
  </div>
</div>

<!-- المواعيد -->
<div class="<?= $tab!=='appointments'?'d-none':'' ?>">
  <div class="d-flex justify-content-end mb-3">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#appointmentModal">
      <i class="fas fa-plus me-1"></i>إضافة موعد
    </button>
  </div>
  <div class="row g-3">
  <?php $_anyAppt = false; $_apTypes=['meeting'=>'اجتماع','court'=>'محكمة','consultation'=>'استشارة','other'=>'أخرى'];
  while($a = $appointments->fetch_assoc()): $_anyAppt = true; ?>
    <div class="col-md-6 col-lg-4">
      <div class="tk-card">
        <div class="tk-top">
          <div class="tk-title"><?= e($a['title']) ?></div>
        </div>
        <div class="tk-badges">
          <span class="badge bg-secondary bg-opacity-10 text-secondary"><?= e($_apTypes[$a['type']] ?? $a['type']) ?></span>
          <?= statusBadge($a['status']) ?>
        </div>
        <div class="tk-meta"><i class="fas fa-calendar-day text-muted" style="width:14px"></i><?= e(dDate($a['appointment_date'], true)) ?></div>
        <?php if ($a['assignee_name'] && $_isTaskMgr): ?><div class="tk-meta"><i class="fas fa-user-tie text-muted" style="width:14px"></i><?= e($a['assignee_name']) ?></div><?php endif; ?>
        <?php if ($a['client_name']): ?><div class="tk-meta"><i class="fas fa-user text-muted" style="width:14px"></i><?= e($a['client_name']) ?></div><?php endif; ?>
        <?php if ($a['location']): ?><div class="tk-meta"><i class="fas fa-location-dot text-muted" style="width:14px"></i><?= e($a['location']) ?></div><?php endif; ?>
        <div class="tk-foot">
          <a href="tasks.php?tab=appointments&edit_a=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary" title="تعديل"><i class="fas fa-edit"></i></a>
          <a href="tasks.php?tab=appointments&delete_a=<?= $a['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف؟')" title="حذف"><i class="fas fa-trash"></i></a>
        </div>
      </div>
    </div>
  <?php endwhile; ?>
  <?php if (!$_anyAppt): ?><div class="col-12"><div class="text-muted text-center py-5">لا توجد مواعيد بعد</div></div><?php endif; ?>
  </div>
</div>

<!-- Modal المهمة -->
<div class="modal fade" id="taskModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-tasks me-2"></i><?= $edit_t ? 'تعديل المهمة' : 'إضافة مهمة جديدة' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="form_type" value="task">
        <div class="modal-body">
          <input type="hidden" name="id" value="<?= $edit_t['id'] ?? '' ?>">
          <div class="mb-3">
            <label class="form-label fw-semibold">عنوان المهمة *</label>
            <input type="text" name="title" class="form-control" required value="<?= e($edit_t['title'] ?? '') ?>">
          </div>
          <div class="row g-2">
            <?php if ($_isTaskMgr): $_cur_aid = (int)($edit_t['assigned_to_id'] ?? 0); ?>
            <div class="col-6">
              <label class="form-label fw-semibold">المكلَّف</label>
              <select name="assigned_to_id" class="form-select">
                <option value="">— بدون / يدوي —</option>
                <?php foreach ($_officeStaff as $_u): ?>
                <option value="<?= $_u['id'] ?>" <?= $_cur_aid === (int)$_u['id'] ? 'selected' : '' ?>><?= e($_u['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="assigned_to" class="form-control form-control-sm mt-1"
                     placeholder="أو اكتب اسماً يدوياً" value="<?= e($edit_t['assigned_to'] ?? '') ?>">
            </div>
            <?php else: ?>
            <input type="hidden" name="assigned_to_id" value="">
            <?php endif; ?>
            <div class="col-6">
              <label class="form-label fw-semibold">الموعد النهائي</label>
              <input type="datetime-local" name="due_date" class="form-control"
                     value="<?= $edit_t && $edit_t['due_date'] ? date('Y-m-d\TH:i', strtotime($edit_t['due_date'])) : '' ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">الأولوية</label>
              <select name="priority" class="form-select">
                <?php foreach(['low'=>'منخفضة','medium'=>'متوسطة','high'=>'عالية','urgent'=>'عاجلة'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= ($edit_t['priority']??'')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">الحالة</label>
              <select name="status" class="form-select">
                <?php foreach(['pending'=>'معلقة','in_progress'=>'جارية','completed'=>'مكتملة','cancelled'=>'ملغاة'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= ($edit_t['status']??'')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal الموعد -->
<div class="modal fade" id="appointmentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i><?= $edit_a ? 'تعديل الموعد' : 'إضافة موعد جديد' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="form_type" value="appointment">
        <div class="modal-body">
          <input type="hidden" name="id" value="<?= $edit_a['id'] ?? '' ?>">
          <div class="mb-3">
            <label class="form-label fw-semibold">عنوان الموعد *</label>
            <input type="text" name="title" class="form-control" required value="<?= e($edit_a['title'] ?? '') ?>">
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label fw-semibold">العميل</label>
              <select name="client_id" class="form-select">
                <?php foreach($clients_arr as $cid=>$cn): ?>
                <option value="<?= $cid ?>" <?= ($edit_a['client_id']??0)==$cid?'selected':'' ?>><?= e($cn) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">نوع الموعد</label>
              <select name="type" class="form-select">
                <?php foreach(['meeting'=>'اجتماع','court'=>'محكمة','consultation'=>'استشارة','other'=>'أخرى'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= ($edit_a['type']??'')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">التاريخ والوقت *</label>
              <input type="datetime-local" name="appointment_date" class="form-control" required
                value="<?= $edit_a ? date('Y-m-d\TH:i', strtotime($edit_a['appointment_date'])) : '' ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">الموقع</label>
              <input type="text" name="location" class="form-control" value="<?= e($edit_a['location'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">الحالة</label>
              <select name="status" class="form-select">
                <?php foreach(['scheduled'=>'مجدول','completed'=>'تم','cancelled'=>'ملغي'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= ($edit_a['status']??'')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php if ($_isTaskMgr): $_cur_aaid = (int)($edit_a['assigned_to_id'] ?? 0); ?>
            <div class="col-6">
              <label class="form-label fw-semibold">المكلَّف</label>
              <select name="assigned_to_id" class="form-select">
                <option value="">— أنا —</option>
                <?php foreach ($_officeStaff as $_u): ?>
                <option value="<?= $_u['id'] ?>" <?= $_cur_aaid === (int)$_u['id'] ? 'selected' : '' ?>><?= e($_u['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($edit_t): ?>
<script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('taskModal')).show();});</script>
<?php endif; ?>
<?php if ($edit_a): ?>
<script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('appointmentModal')).show();});</script>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
