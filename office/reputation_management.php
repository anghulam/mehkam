<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('reputation_management','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'reputation_management')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'إدارة السمعة والتقييمات';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('reputation_management','add'); $_canEdit = can('reputation_management','edit'); $_canDel = can('reputation_management','delete');
$_canApprove = can('reputation_management','approve'); // مستوى المدير: يشوف كل التقييمات ويسند الرد لأي موظف

$conn->query("CREATE TABLE IF NOT EXISTS client_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    client_name VARCHAR(200) DEFAULT NULL,
    source VARCHAR(50) DEFAULT 'google',
    rating TINYINT NOT NULL DEFAULT 5,
    comment TEXT,
    review_date DATE DEFAULT NULL,
    response_text TEXT,
    responded_at TIMESTAMP NULL DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    status ENUM('new','responded','archived') DEFAULT 'new',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$officeUsers = [];
$ur = $conn->query("SELECT id, full_name FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
if ($ur) while ($u = $ur->fetch_assoc()) $officeUsers[$u['id']] = $u['full_name'];
$_assignableUsers = $_canApprove ? $officeUsers : array_intersect_key($officeUsers, [$uid=>true]);

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $own = $_canApprove ? "" : " AND (assigned_to=$uid OR created_by=$uid)";
    $conn->query("DELETE FROM client_reviews WHERE id=".(int)$_GET['delete']." AND office_id=$oid$own");
    header("Location: reputation_management.php?msg=deleted"); exit;
}

/* ── حفظ / تعديل تقييم ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'review') {
    $rid = (int)($_POST['id'] ?? 0);
    requirePerm('reputation_management', $rid ? 'edit' : 'add', 'reputation_management.php?msg=denied');
    if ($rid && !$_canApprove) {
        $chk = $conn->query("SELECT id FROM client_reviews WHERE id=$rid AND office_id=$oid AND (assigned_to=$uid OR created_by=$uid)")->fetch_assoc();
        if (!$chk) { header('Location: reputation_management.php?msg=denied'); exit; }
    }
    $client_name = $conn->real_escape_string(trim($_POST['client_name'] ?? ''));
    $source = $conn->real_escape_string(trim($_POST['source'] ?? 'google'));
    $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
    $comment = $conn->real_escape_string(trim($_POST['comment'] ?? ''));
    $review_date = !empty($_POST['review_date']) ? "'".$conn->real_escape_string($_POST['review_date'])."'" : 'NULL';
    $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : ($_canApprove ? 'NULL' : $uid);
    if (!$_canApprove) $assigned_to = $uid;

    if ($rid) {
        $conn->query("UPDATE client_reviews SET client_name='$client_name',source='$source',rating=$rating,comment='$comment',review_date=$review_date,assigned_to=".($assigned_to==='NULL'?'NULL':(int)$assigned_to)." WHERE id=$rid AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO client_reviews (office_id,client_name,source,rating,comment,review_date,assigned_to,created_by)
            VALUES ($oid,'$client_name','$source',$rating,'$comment',$review_date,".($assigned_to==='NULL'?'NULL':(int)$assigned_to).",".($uid ?: 'NULL').")");
    }
    header("Location: reputation_management.php?msg=saved"); exit;
}

/* ── إضافة رد ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'reply') {
    $rid = (int)($_POST['id'] ?? 0);
    $own = $_canApprove ? "" : " AND (assigned_to=$uid OR created_by=$uid)";
    $chk = $conn->query("SELECT id FROM client_reviews WHERE id=$rid AND office_id=$oid$own")->fetch_assoc();
    if (!$chk) { header('Location: reputation_management.php?msg=denied'); exit; }
    $resp = $conn->real_escape_string(trim($_POST['response_text'] ?? ''));
    $conn->query("UPDATE client_reviews SET response_text='$resp', responded_at=NOW(), status='responded' WHERE id=$rid AND office_id=$oid");
    header("Location: reputation_management.php?msg=saved"); exit;
}

/* ── بيانات ── */
$_ownScope = !$_canApprove ? " AND (r.assigned_to=$uid OR r.created_by=$uid)" : "";
$reviews = [];
$rr = $conn->query("SELECT r.*, u.full_name assignee_name FROM client_reviews r LEFT JOIN users u ON r.assigned_to=u.id
    WHERE r.office_id=$oid$_ownScope ORDER BY r.created_at DESC LIMIT 300");
if ($rr) while ($r = $rr->fetch_assoc()) $reviews[] = $r;

$avg_rating = 0; $total = count($reviews); $pending_response = 0;
foreach ($reviews as $r) { $avg_rating += (int)$r['rating']; if ($r['status']==='new') $pending_response++; }
$avg_rating = $total ? round($avg_rating/$total, 1) : 0;
$_statusMap = ['new'=>['بانتظار الرد','warning'],'responded'=>['تم الرد','success'],'archived'=>['مؤرشف','secondary']];

$edit = null;
if (isset($_GET['edit'])) {
    $own = $_canApprove ? "" : " AND (assigned_to=$uid OR created_by=$uid)";
    $edit = $conn->query("SELECT * FROM client_reviews WHERE id=".(int)$_GET['edit']." AND office_id=$oid$own")->fetch_assoc();
}

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-thumbs-up"></i> إدارة السمعة والتقييمات</div>
<div class="mk-page-sub mb-3">سجّل تقييمات العملاء من مصادر مختلفة وتابع الرد عليها لبناء سمعة أفضل للمكتب</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#fef3c7;color:#d97706"><i class="fas fa-star"></i></div>
      <div><div class="fw-bold"><?= $avg_rating ?> / 5</div><div class="text-muted" style="font-size:11px">متوسط التقييم</div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#fee2e2;color:#dc2626"><i class="fas fa-hourglass-half"></i></div>
      <div><div class="fw-bold"><?= $pending_response ?></div><div class="text-muted" style="font-size:11px">بانتظار الرد</div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#f0fdf4;color:#16a34a"><i class="fas fa-comments"></i></div>
      <div><div class="fw-bold"><?= $total ?></div><div class="text-muted" style="font-size:11px">إجمالي التقييمات</div></div>
    </div></div>
  </div>
</div>

<div class="d-flex justify-content-end mb-3">
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#reviewModal" onclick="reviewNew()"><i class="fas fa-plus me-1"></i>تقييم جديد</button>
  <?php endif; ?>
</div>

<div class="row g-3">
<?php if (!$reviews): ?><div class="col-12"><div class="text-muted text-center py-5">لا توجد تقييمات مسجّلة بعد</div></div>
<?php else: foreach ($reviews as $r): $sm = $_statusMap[$r['status']] ?? ['—','secondary']; ?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <div class="fw-bold"><?= e($r['client_name'] ?: 'عميل غير محدد') ?></div>
          <div class="text-warning" style="font-size:13px"><?php for($i=0;$i<5;$i++): ?><i class="fa<?= $i<$r['rating']?'s':'r' ?> fa-star"></i><?php endfor; ?></div>
        </div>
        <span class="badge bg-<?= $sm[1] ?> bg-opacity-10 text-<?= $sm[1] ?>"><?= $sm[0] ?></span>
      </div>
      <div class="text-muted mb-2" style="font-size:11px"><i class="fas fa-tag me-1"></i><?= e($r['source']) ?> <?php if ($r['review_date']): ?> — <?= $r['review_date'] ?><?php endif; ?></div>
      <?php if ($r['comment']): ?><div class="mb-2" style="font-size:13px"><?= e($r['comment']) ?></div><?php endif; ?>
      <?php if ($r['response_text']): ?><div class="border-start border-3 border-success ps-2 mb-2" style="font-size:12px"><i class="fas fa-reply me-1"></i><?= e($r['response_text']) ?></div><?php endif; ?>
      <div class="text-muted mb-2" style="font-size:11px"><i class="fas fa-user me-1"></i><?= e($r['assignee_name'] ?: 'غير مسنَد') ?></div>
      <div class="d-flex gap-1 flex-wrap">
        <?php if (!$r['response_text']): ?><button class="btn btn-sm btn-outline-success" onclick="replyOpen(<?= $r['id'] ?>)"><i class="fas fa-reply"></i> رد</button><?php endif; ?>
        <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='reviewEdit(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
        <?php if ($_canDel): ?><a href="reputation_management.php?delete=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا التقييم؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- Modal: تقييم -->
<div class="modal fade" id="reviewModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="reviewModalTitle">تقييم جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="review"><input type="hidden" name="id" id="rev_id">
    <div class="modal-body">
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">اسم العميل</label><input type="text" name="client_name" id="rev_client" class="form-control"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">المصدر</label>
          <select name="source" id="rev_source" class="form-select">
            <option value="google">Google</option><option value="whatsapp">واتساب</option><option value="phone">هاتف</option><option value="in_person">شخصياً</option><option value="other">أخرى</option>
          </select>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">التقييم (1-5) *</label><input type="number" name="rating" id="rev_rating" class="form-control" min="1" max="5" value="5" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">تاريخ التقييم</label><input type="date" name="review_date" id="rev_date" class="form-control"></div>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">نص التقييم</label><textarea name="comment" id="rev_comment" class="form-control" rows="3"></textarea></div>
      <?php if ($_canApprove): ?>
      <div class="mb-1"><label class="form-label fw-semibold">إسناد المتابعة إلى</label>
        <select name="assigned_to" id="rev_assigned" class="form-select">
          <option value="">— غير مسنَد —</option>
          <?php foreach ($_assignableUsers as $auid=>$aun): ?><option value="<?= $auid ?>"><?= e($aun) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<!-- Modal: رد -->
<div class="modal fade" id="replyModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-reply me-2"></i>الرد على التقييم</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="reply"><input type="hidden" name="id" id="reply_id">
    <div class="modal-body"><textarea name="response_text" class="form-control" rows="4" placeholder="اكتب ردك على العميل هنا..." required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-success"><i class="fas fa-paper-plane me-1"></i>إرسال الرد</button></div>
  </form>
</div></div></div>

<script>
function reviewNew(){document.getElementById('reviewModalTitle').innerText='تقييم جديد';document.getElementById('rev_id').value='';document.getElementById('rev_client').value='';document.getElementById('rev_source').value='google';document.getElementById('rev_rating').value=5;document.getElementById('rev_date').value='';document.getElementById('rev_comment').value='';var a=document.getElementById('rev_assigned');if(a)a.value='';}
function reviewEdit(r){document.getElementById('reviewModalTitle').innerText='تعديل تقييم';document.getElementById('rev_id').value=r.id;document.getElementById('rev_client').value=r.client_name||'';document.getElementById('rev_source').value=r.source;document.getElementById('rev_rating').value=r.rating;document.getElementById('rev_date').value=r.review_date||'';document.getElementById('rev_comment').value=r.comment||'';var a=document.getElementById('rev_assigned');if(a)a.value=r.assigned_to||'';new bootstrap.Modal(document.getElementById('reviewModal')).show();}
function replyOpen(id){document.getElementById('reply_id').value=id;new bootstrap.Modal(document.getElementById('replyModal')).show();}
</script>

<?php include '../includes/office_footer.php'; ?>
