<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('external_collab','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'external_collab')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'بوابة المحامي الاستشاري الخارجي';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('external_collab','add'); $_canDel = can('external_collab','delete');

$conn->query("CREATE TABLE IF NOT EXISTS external_collaborators (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    case_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('active','revoked') DEFAULT 'active',
    expires_at DATE NOT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("CREATE TABLE IF NOT EXISTS external_collab_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    collaborator_id INT NOT NULL,
    note VARCHAR(1000) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_collab (collaborator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── إلغاء دعوة ── */
if (isset($_GET['revoke']) && $_canDel) {
    $own = can('external_collab','approve') ? '' : " AND created_by=$uid";
    $conn->query("UPDATE external_collaborators SET status='revoked' WHERE id=".(int)$_GET['revoke']." AND office_id=$oid$own");
    header('Location: external_collab.php?msg=saved'); exit;
}

/* ── دعوة محامٍ خارجي ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'invite') {
    requirePerm('external_collab', 'add', 'external_collab.php?msg=denied');
    $case_id = (int)($_POST['case_id'] ?? 0);
    $ok = $conn->query("SELECT id FROM cases WHERE id=$case_id AND office_id=$oid" . caseScope('cases'))->fetch_assoc();
    $name = trim($_POST['name'] ?? '');
    $exp = $_POST['expires_at'] ?? '';
    if (!$ok || $name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) || $exp < date('Y-m-d')) { header('Location: external_collab.php?msg=invalid'); exit; }
    $ne = $conn->real_escape_string($name);
    $email = $conn->real_escape_string(trim($_POST['email'] ?? ''));
    $token = bin2hex(random_bytes(24));
    $conn->query("INSERT INTO external_collaborators (office_id,case_id,name,email,token,expires_at,created_by) VALUES ($oid,$case_id,'$ne','$email','$token','$exp',".($uid ?: 'NULL').")");
    header('Location: external_collab.php?msg=saved'); exit;
}

$cases_arr = [];
$cr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid" . caseScope('cases') . " ORDER BY id DESC LIMIT 300");
if ($cr) while ($x = $cr->fetch_assoc()) $cases_arr[$x['id']] = $x['case_number'] . ' — ' . mb_substr($x['title'], 0, 40);

$scope = can('external_collab','approve') ? '' : " AND ec.created_by=$uid";
$list = [];
$lr = $conn->query("SELECT ec.*, c.case_number, c.title case_title,
    (SELECT COUNT(*) FROM external_collab_notes n WHERE n.collaborator_id=ec.id) note_count
    FROM external_collaborators ec LEFT JOIN cases c ON c.id=ec.case_id
    WHERE ec.office_id=$oid$scope ORDER BY ec.id DESC LIMIT 100");
if ($lr) while ($x = $lr->fetch_assoc()) $list[] = $x;

$notesBy = [];
if ($list) {
    $ids = implode(',', array_map(fn($l) => (int)$l['id'], $list));
    $nr = $conn->query("SELECT * FROM external_collab_notes WHERE collaborator_id IN ($ids) ORDER BY id DESC");
    if ($nr) while ($x = $nr->fetch_assoc()) $notesBy[$x['collaborator_id']][] = $x;
}
$_base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/') . '/public/collab.php?t=';

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-user-tie"></i> بوابة المحامي الاستشاري الخارجي</div>
<div class="mk-page-sub mb-3">ادعُ محامياً من خارج المكتب للاطّلاع على قضية واحدة وإبداء ملاحظاته، بصلاحية محدودة ومؤقتة تنتهي تلقائياً</div>

<?php if (isset($_GET['msg'])):
  $mm = ['saved'=>['success','تم الحفظ بنجاح'],'invalid'=>['danger','أكمل الاسم والقضية وتاريخ انتهاء صالح'],'denied'=>['danger','ليست لديك صلاحية']];
  $m = $mm[$_GET['msg']] ?? ['success','تم']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($_canAdd): ?>
<div class="card mb-4"><div class="card-header fw-bold"><i class="fas fa-envelope-open-text me-1"></i>دعوة جديدة</div><div class="card-body">
  <form method="POST" class="row g-2 align-items-end"><input type="hidden" name="form_type" value="invite">
    <div class="col-md-3"><label class="form-label fw-semibold">القضية *</label><select name="case_id" class="form-select" required><option value="">— اختر —</option>
      <?php foreach ($cases_arr as $cid => $cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label fw-semibold">اسم المحامي *</label><input name="name" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label fw-semibold">البريد (اختياري)</label><input type="email" name="email" class="form-control"></div>
    <div class="col-md-2"><label class="form-label fw-semibold">ينتهي في *</label><input type="date" name="expires_at" class="form-control" value="<?= date('Y-m-d', strtotime('+14 days')) ?>" required></div>
    <div class="col-md-1"><button class="btn btn-primary w-100"><i class="fas fa-paper-plane"></i></button></div>
  </form>
</div></div>
<?php endif; ?>

<div class="row g-3">
<?php if (!$list): ?><div class="col-12"><div class="text-muted text-center py-5">لا توجد دعوات بعد</div></div>
<?php else: foreach ($list as $c): $active = $c['status']==='active' && $c['expires_at'] >= date('Y-m-d'); $url = $_base . $c['token']; ?>
<div class="col-lg-6"><div class="card h-100"><div class="card-body">
  <div class="d-flex justify-content-between align-items-start">
    <div><b><?= e($c['name']) ?></b><div class="text-muted" style="font-size:12px"><?= e($c['case_number'] ?: '—') ?> — <?= e(mb_substr($c['case_title'] ?? '', 0, 40)) ?></div></div>
    <span class="badge bg-<?= $active?'success':'secondary' ?> bg-opacity-10 text-<?= $active?'success':'secondary' ?>"><?= $active?'فعّالة':'منتهية' ?></span>
  </div>
  <div class="text-muted mt-1" style="font-size:12px">تنتهي في <?= e($c['expires_at']) ?><?= $c['email'] ? ' · '.e($c['email']) : '' ?></div>
  <?php if ($active): ?>
  <div class="input-group input-group-sm mt-2"><input type="text" class="form-control" readonly value="<?= e($url) ?>" onclick="this.select()">
    <button class="btn btn-outline-primary" onclick="navigator.clipboard.writeText('<?= e($url) ?>')"><i class="fas fa-copy"></i></button></div>
  <?php endif; ?>
  <?php if (!empty($notesBy[$c['id']])): ?>
  <div class="mt-2 border-top pt-2" style="font-size:12px;max-height:100px;overflow:auto">
    <b><i class="fas fa-comment-dots me-1"></i>ملاحظات المحامي (<?= $c['note_count'] ?>):</b>
    <?php foreach (array_slice($notesBy[$c['id']], 0, 5) as $n): ?><div class="mt-1"><?= e($n['note']) ?> <span class="text-muted">(<?= e(date('Y-m-d', strtotime($n['created_at']))) ?>)</span></div><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($active && $_canDel): ?><a href="external_collab.php?revoke=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-danger mt-2" onclick="return confirm('إلغاء هذه الدعوة الآن؟')"><i class="fas fa-ban me-1"></i>إلغاء الدعوة</a><?php endif; ?>
</div></div></div>
<?php endforeach; endif; ?>
</div>

<?php include '../includes/office_footer.php'; ?>
