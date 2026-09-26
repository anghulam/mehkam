<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('support_tickets','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'support_tickets')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'تذاكر الدعم الفني';
$uid = (int)($_SESSION['user_id'] ?? 0);
$uname = $_SESSION['full_name'] ?? '';
$_canAdd = can('support_tickets','add'); $_canEdit = can('support_tickets','edit'); $_canDel = can('support_tickets','delete');
$_canApprove = can('support_tickets','approve'); // مستوى المدير: يشوف كل التذاكر ويسندها لأي موظف

$conn->query("CREATE TABLE IF NOT EXISTS support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    client_id INT DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    assigned_to INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS support_ticket_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    message TEXT NOT NULL,
    created_by INT DEFAULT NULL,
    created_by_name VARCHAR(150) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM support_tickets WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    $conn->query("DELETE FROM support_ticket_replies WHERE ticket_id=".(int)$_GET['delete']);
    header("Location: support_tickets.php?msg=deleted"); exit;
}

/* ── حفظ تذكرة جديدة ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'ticket') {
    requirePerm('support_tickets', 'add', 'support_tickets.php?msg=denied');
    $client_id = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : 'NULL';
    $subject = $conn->real_escape_string(trim($_POST['subject'] ?? ''));
    $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
    $priority = in_array($_POST['priority'] ?? '', ['low','medium','high','urgent'], true) ? $_POST['priority'] : 'medium';
    // موظف عادي لا يقدر يسند التذكرة لغيره — الإسناد لأشخاص آخرين قرار مدير فقط (نتجاهل أي قيمة أخرى تصل من الواجهة)
    $assigned = $_canApprove ? (!empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : 'NULL') : $uid;
    $conn->query("INSERT INTO support_tickets (office_id,client_id,subject,description,priority,assigned_to,created_by)
        VALUES ($oid,$client_id,'$subject','$desc','$priority',$assigned,".($uid ?: 'NULL').")");
    header("Location: support_tickets.php?msg=saved"); exit;
}

/* ── تغيير حالة — المدير أو المسؤول/منشئ التذكرة نفسها ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'status') {
    $tid = (int)($_POST['ticket_id'] ?? 0);
    $tk = $conn->query("SELECT assigned_to, created_by FROM support_tickets WHERE id=$tid AND office_id=$oid")->fetch_assoc();
    $canAct = $tk && ($_canEdit || $_canApprove || $tk['assigned_to']==$uid || $tk['created_by']==$uid);
    if ($canAct) {
        $st = in_array($_POST['status'] ?? '', ['open','in_progress','resolved','closed'], true) ? $_POST['status'] : 'open';
        $conn->query("UPDATE support_tickets SET status='$st' WHERE id=$tid AND office_id=$oid");
    }
    header("Location: support_tickets.php?open_ticket=$tid"); exit;
}

/* ── إضافة رد ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'reply') {
    $tid = (int)($_POST['ticket_id'] ?? 0);
    $msg = $conn->real_escape_string(trim($_POST['message'] ?? ''));
    $tkChk = $conn->query("SELECT assigned_to, created_by FROM support_tickets WHERE id=$tid AND office_id=$oid")->fetch_assoc();
    $chk = $tkChk && ($_canApprove || $tkChk['assigned_to']==$uid || $tkChk['created_by']==$uid);
    if ($chk && $msg !== '') {
        $unameEsc = $conn->real_escape_string($uname);
        $conn->query("INSERT INTO support_ticket_replies (ticket_id,message,created_by,created_by_name) VALUES ($tid,'$msg',".($uid ?: 'NULL').",'$unameEsc')");
    }
    header("Location: support_tickets.php?open_ticket=$tid"); exit;
}

/* ── بيانات ──
   موظف عادي (بلا صلاحية اعتماد) يشوف فقط التذاكر المُسندة له أو التي أنشأها —
   المدير (صلاحية اعتماد) يشوف كل التذاكر. ── */
$status_f = $_GET['status_f'] ?? '';
$where = "t.office_id=$oid";
if ($status_f !== '' && in_array($status_f, ['open','in_progress','resolved','closed'], true)) $where .= " AND t.status='".$conn->real_escape_string($status_f)."'";
if (!$_canApprove) $where .= " AND (t.assigned_to=$uid OR t.created_by=$uid)";

$tickets = [];
$tr = $conn->query("SELECT t.*, cl.full_name client_name, u.full_name assigned_name
    FROM support_tickets t LEFT JOIN clients cl ON t.client_id=cl.id LEFT JOIN users u ON t.assigned_to=u.id
    WHERE $where ORDER BY t.created_at DESC LIMIT 300");
if ($tr) while ($r = $tr->fetch_assoc()) $tickets[] = $r;

$clients_arr = [];
$clr = $conn->query("SELECT id, full_name FROM clients WHERE office_id=$oid ORDER BY full_name");
if ($clr) while ($r = $clr->fetch_assoc()) $clients_arr[$r['id']] = $r['full_name'];

$users_arr = [];
$usr = $conn->query("SELECT id, full_name FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
if ($usr) while ($r = $usr->fetch_assoc()) $users_arr[$r['id']] = $r['full_name'];
// موظف عادي لا يقدر يسند تذكرة لغيره — فقط المدير يشوف كل الموظفين في القائمة
$_assignableUsers = $_canApprove ? $users_arr : array_intersect_key($users_arr, [$uid => true]);

$open_ticket = null; $open_replies = [];
if (isset($_GET['open_ticket'])) {
    $_ownTicketScope = !$_canApprove ? " AND (t.assigned_to=$uid OR t.created_by=$uid)" : "";
    $open_ticket = $conn->query("SELECT t.*, cl.full_name client_name FROM support_tickets t LEFT JOIN clients cl ON t.client_id=cl.id
        WHERE t.id=".(int)$_GET['open_ticket']." AND t.office_id=$oid$_ownTicketScope LIMIT 1")->fetch_assoc();
    if ($open_ticket) {
        $orr = $conn->query("SELECT * FROM support_ticket_replies WHERE ticket_id=".(int)$open_ticket['id']." ORDER BY id ASC");
        if ($orr) while ($r = $orr->fetch_assoc()) $open_replies[] = $r;
    }
}

$_statusMap = ['open'=>['مفتوحة','danger'],'in_progress'=>['قيد المعالجة','primary'],'resolved'=>['محلولة','success'],'closed'=>['مغلقة','secondary']];
$_prioMap = ['low'=>'منخفضة','medium'=>'متوسطة','high'=>'عالية','urgent'=>'عاجلة'];

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-headset"></i> تذاكر الدعم الفني</div>
<div class="mk-page-sub mb-3">تابع طلبات ومشاكل عملائك عبر تذاكر منظّمة</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form method="GET" class="d-flex gap-2">
    <select name="status_f" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">كل الحالات</option>
      <?php foreach ($_statusMap as $k=>$v): ?><option value="<?= $k ?>" <?= $status_f===$k?'selected':'' ?>><?= $v[0] ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#ticketModal"><i class="fas fa-plus me-1"></i>تذكرة جديدة</button>
  <?php endif; ?>
</div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>الموضوع</th><th>العميل</th><th>الأولوية</th><th>الحالة</th><th>المسؤول</th><th>التاريخ</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$tickets): ?><tr><td colspan="7" class="text-center text-muted py-4">لا توجد تذاكر بعد</td></tr>
<?php else: foreach ($tickets as $t): $sm = $_statusMap[$t['status']] ?? ['—','secondary']; ?>
<tr>
  <td class="fw-semibold"><?= e($t['subject']) ?></td>
  <td><?= e($t['client_name'] ?: '—') ?></td>
  <td><?= $_prioMap[$t['priority']] ?? $t['priority'] ?></td>
  <td><span class="badge bg-<?= $sm[1] ?> bg-opacity-10 text-<?= $sm[1] ?>"><?= $sm[0] ?></span></td>
  <td><?= e($t['assigned_name'] ?: '—') ?></td>
  <td style="font-size:12px"><?= dDate($t['created_at'], true) ?></td>
  <td class="d-flex gap-1">
    <a href="support_tickets.php?open_ticket=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary" title="فتح"><i class="fas fa-comments"></i></a>
    <?php if ($_canDel): ?><a href="support_tickets.php?delete=<?= $t['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذه التذكرة؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>

<!-- Modal: تذكرة جديدة -->
<div class="modal fade" id="ticketModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-headset me-2"></i>تذكرة دعم جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="ticket">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">العميل</label>
        <select name="client_id" class="form-select"><option value="">— بدون —</option>
          <?php foreach ($clients_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">الموضوع *</label><input type="text" name="subject" class="form-control" required></div>
      <div class="mb-3"><label class="form-label fw-semibold">الوصف</label><textarea name="description" class="form-control" rows="3"></textarea></div>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label fw-semibold">الأولوية</label>
          <select name="priority" class="form-select">
            <?php foreach ($_prioMap as $k=>$v): ?><option value="<?= $k ?>" <?= $k==='medium'?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label fw-semibold">تعيين لموظف</label>
          <select name="assigned_to" class="form-select" <?= !$_canApprove?'disabled':'' ?>>
            <?php if (!$_canApprove): ?><option value="<?= $uid ?>" selected><?= e($uname) ?> (أنت)</option>
            <?php else: ?><option value="">— بدون —</option><?php foreach ($_assignableUsers as $uid4=>$un): ?><option value="<?= $uid4 ?>"><?= e($un) ?></option><?php endforeach; endif; ?>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>إنشاء التذكرة</button></div>
  </form>
</div></div></div>

<?php if ($open_ticket):
  $sm = $_statusMap[$open_ticket['status']] ?? ['—','secondary']; ?>
<!-- Modal: عرض تذكرة -->
<div class="modal fade show" id="viewTicketModal" tabindex="-1" style="display:block" aria-modal="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-headset me-2"></i><?= e($open_ticket['subject']) ?></h5>
        <a href="support_tickets.php" class="btn-close"></a>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-2 mb-3 flex-wrap">
          <span class="badge bg-<?= $sm[1] ?> bg-opacity-10 text-<?= $sm[1] ?>"><?= $sm[0] ?></span>
          <span class="badge bg-light text-dark"><?= e($open_ticket['client_name'] ?: 'بدون عميل') ?></span>
          <span class="badge bg-light text-dark"><?= $_prioMap[$open_ticket['priority']] ?? '' ?></span>
        </div>
        <?php if ($open_ticket['description']): ?>
        <div class="p-3 rounded mb-3" style="background:#f8fafc;font-size:13px;white-space:pre-wrap"><?= e($open_ticket['description']) ?></div>
        <?php endif; ?>

        <?php if ($_canEdit || $_canApprove || $open_ticket['assigned_to']==$uid || $open_ticket['created_by']==$uid): ?>
        <form method="POST" class="d-flex gap-2 mb-3">
          <input type="hidden" name="form_type" value="status">
          <input type="hidden" name="ticket_id" value="<?= $open_ticket['id'] ?>">
          <select name="status" class="form-select form-select-sm" style="max-width:200px" onchange="this.form.submit()">
            <?php foreach ($_statusMap as $k=>$v): ?><option value="<?= $k ?>" <?= $open_ticket['status']===$k?'selected':'' ?>><?= $v[0] ?></option><?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>

        <div class="fw-semibold mb-2" style="font-size:13px">المحادثة</div>
        <?php if (!$open_replies): ?>
        <div class="text-muted text-center py-3" style="font-size:12px">لا توجد ردود بعد</div>
        <?php else: foreach ($open_replies as $rp): ?>
        <div class="mb-2 p-2 rounded" style="background:#f1f5f9;font-size:13px">
          <div class="fw-semibold" style="font-size:11px;color:#64748b"><?= e($rp['created_by_name'] ?: '—') ?> — <?= dDate($rp['created_at'], true) ?></div>
          <div style="white-space:pre-wrap"><?= e($rp['message']) ?></div>
        </div>
        <?php endforeach; endif; ?>

        <form method="POST" class="mt-3">
          <input type="hidden" name="form_type" value="reply">
          <input type="hidden" name="ticket_id" value="<?= $open_ticket['id'] ?>">
          <textarea name="message" class="form-control mb-2" rows="2" placeholder="اكتب ردّاً..." required></textarea>
          <button class="btn btn-primary btn-sm"><i class="fas fa-reply me-1"></i>إرسال الرد</button>
        </form>
      </div>
      <div class="modal-footer"><a href="support_tickets.php" class="btn btn-outline-secondary">إغلاق</a></div>
    </div>
  </div>
</div>
<div class="modal-backdrop fade show"></div>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
