<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('expert_witness','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'expert_witness')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'قاعدة بيانات الخبراء والشهود';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('expert_witness','add'); $_canEdit = can('expert_witness','edit'); $_canDel = can('expert_witness','delete');

$conn->query("CREATE TABLE IF NOT EXISTS experts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    full_name VARCHAR(200) NOT NULL,
    specialty VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    organization VARCHAR(200) DEFAULT NULL,
    rating TINYINT DEFAULT NULL,
    notes TEXT,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS expert_case_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expert_id INT NOT NULL,
    case_id INT NOT NULL,
    role ENUM('expert_witness','translator','forensic','other') DEFAULT 'expert_witness',
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expert (expert_id), INDEX idx_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حذف خبير ── */
if (isset($_GET['delete']) && $_canDel) {
    $eid = (int)$_GET['delete'];
    $conn->query("DELETE FROM expert_case_links WHERE expert_id=$eid");
    $conn->query("DELETE FROM experts WHERE id=$eid AND office_id=$oid");
    header("Location: expert_witness.php?msg=deleted"); exit;
}
/* ── فك ربط قضية ── */
if (isset($_GET['unlink']) && $_canEdit) {
    $conn->query("DELETE FROM expert_case_links WHERE id=".(int)$_GET['unlink']);
    header("Location: expert_witness.php?msg=deleted"); exit;
}

/* ── حفظ خبير ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'expert') {
    $eid = (int)($_POST['id'] ?? 0);
    requirePerm('expert_witness', $eid ? 'edit' : 'add', 'expert_witness.php?msg=denied');
    $name = $conn->real_escape_string(trim($_POST['full_name'] ?? ''));
    $spec = $conn->real_escape_string(trim($_POST['specialty'] ?? ''));
    $phone = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
    $email = $conn->real_escape_string(trim($_POST['email'] ?? ''));
    $org = $conn->real_escape_string(trim($_POST['organization'] ?? ''));
    $rating = !empty($_POST['rating']) ? min(5, max(1, (int)$_POST['rating'])) : 'NULL';
    $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    if ($eid) {
        $conn->query("UPDATE experts SET full_name='$name',specialty='$spec',phone='$phone',email='$email',organization='$org',rating=$rating,notes='$notes' WHERE id=$eid AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO experts (office_id,full_name,specialty,phone,email,organization,rating,notes,created_by) VALUES ($oid,'$name','$spec','$phone','$email','$org',$rating,'$notes',".($uid ?: 'NULL').")");
    }
    header("Location: expert_witness.php?msg=saved"); exit;
}

/* ── ربط بقضية ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'link' && $_canEdit) {
    $eid = (int)($_POST['expert_id'] ?? 0);
    $cid = (int)($_POST['case_id'] ?? 0);
    $role = in_array($_POST['role'] ?? '', ['expert_witness','translator','forensic','other'], true) ? $_POST['role'] : 'expert_witness';
    $notes = $conn->real_escape_string(trim($_POST['link_notes'] ?? ''));
    $expChk = $conn->query("SELECT id FROM experts WHERE id=$eid AND office_id=$oid")->num_rows;
    $caseChk = $conn->query("SELECT id FROM cases WHERE id=$cid AND office_id=$oid")->num_rows;
    if ($eid && $cid && $expChk && $caseChk) {
        $conn->query("INSERT INTO expert_case_links (expert_id,case_id,role,notes) VALUES ($eid,$cid,'$role','$notes')");
    }
    header("Location: expert_witness.php?msg=saved"); exit;
}

/* ── بيانات ── */
$experts = [];
$er = $conn->query("SELECT * FROM experts WHERE office_id=$oid ORDER BY full_name");
if ($er) while ($r = $er->fetch_assoc()) $experts[] = $r;

$links_by_expert = [];
$lr = $conn->query("SELECT l.*, c.case_number, c.title case_title FROM expert_case_links l
    JOIN cases c ON l.case_id=c.id WHERE c.office_id=$oid ORDER BY l.id DESC");
if ($lr) while ($r = $lr->fetch_assoc()) $links_by_expert[$r['expert_id']][] = $r;

$cases_arr = [];
$ccr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid" . caseScope('cases') . " ORDER BY id DESC LIMIT 300");
if ($ccr) while ($r = $ccr->fetch_assoc()) $cases_arr[$r['id']] = $r['case_number'].' — '.mb_substr($r['title'],0,40);

$_roleMap = ['expert_witness'=>'خبير','translator'=>'مترجم','forensic'=>'خبير جنائي/فني','other'=>'أخرى'];

include '../includes/office_header.php';
?>

<style>
.ew-star{color:#e2e8f0}
.ew-star.filled{color:#f59e0b}
</style>

<div class="mk-page-title mb-1"><i class="fas fa-user-doctor"></i> قاعدة بيانات الخبراء والشهود</div>
<div class="mk-page-sub mb-3">سجّل الخبراء والشهود والمترجمين اللي يتعامل معهم مكتبك وربطهم بالقضايا</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-end mb-3">
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#expertModal" onclick="expNew()"><i class="fas fa-plus me-1"></i>خبير/شاهد جديد</button>
  <?php endif; ?>
</div>

<div class="row g-3">
<?php if (!$experts): ?>
<div class="col-12"><div class="text-muted text-center py-5">لا يوجد خبراء أو شهود مسجّلون بعد</div></div>
<?php else: foreach ($experts as $ex): $exLinks = $links_by_expert[$ex['id']] ?? []; ?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <div class="fw-bold"><?= e($ex['full_name']) ?></div>
          <div class="text-muted" style="font-size:12px"><?= e($ex['specialty'] ?: '—') ?></div>
        </div>
        <div class="d-flex gap-1">
          <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='expEdit(<?= json_encode($ex, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
          <?php if ($_canDel): ?><a href="expert_witness.php?delete=<?= $ex['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا الخبير؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
        </div>
      </div>
      <?php if ($ex['rating']): ?>
      <div class="mb-2">
        <?php for ($i=1;$i<=5;$i++): ?><i class="fas fa-star ew-star <?= $i<=$ex['rating']?'filled':'' ?>" style="font-size:12px"></i><?php endfor; ?>
      </div>
      <?php endif; ?>
      <div class="text-muted mb-2" style="font-size:12px">
        <?php if ($ex['organization']): ?><div><i class="fas fa-building me-1"></i><?= e($ex['organization']) ?></div><?php endif; ?>
        <?php if ($ex['phone']): ?><div><i class="fas fa-phone me-1"></i><?= e($ex['phone']) ?></div><?php endif; ?>
        <?php if ($ex['email']): ?><div><i class="fas fa-envelope me-1"></i><?= e($ex['email']) ?></div><?php endif; ?>
      </div>
      <div class="border-top pt-2">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <small class="fw-semibold text-muted">القضايا (<?= count($exLinks) ?>)</small>
          <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="linkNew(<?= $ex['id'] ?>)" title="ربط بقضية"><i class="fas fa-link" style="font-size:10px"></i></button><?php endif; ?>
        </div>
        <?php if (!$exLinks): ?><div class="text-muted" style="font-size:11px">لا توجد قضايا مرتبطة</div>
        <?php else: foreach ($exLinks as $lk): ?>
        <div class="d-flex justify-content-between align-items-center" style="font-size:11px">
          <span><?= e($lk['case_number']) ?> <span class="text-muted">(<?= $_roleMap[$lk['role']] ?? $lk['role'] ?>)</span></span>
          <?php if ($_canEdit): ?><a href="expert_witness.php?unlink=<?= $lk['id'] ?>" class="text-danger" onclick="return confirm('فك الربط؟')"><i class="fas fa-xmark"></i></a><?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- Modal: خبير -->
<div class="modal fade" id="expertModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="expTitle"><i class="fas fa-user-doctor me-2"></i>خبير/شاهد جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="expert"><input type="hidden" name="id" id="exp_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">الاسم *</label><input type="text" name="full_name" id="exp_name" class="form-control" required></div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">التخصص</label><input type="text" name="specialty" id="exp_specialty" class="form-control" placeholder="مثال: خبير محاسبي، هندسي..."></div>
        <div class="col-md-6"><label class="form-label fw-semibold">الجهة/المؤسسة</label><input type="text" name="organization" id="exp_org" class="form-control"></div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">الجوال</label><input type="text" name="phone" id="exp_phone" class="form-control"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">البريد الإلكتروني</label><input type="email" name="email" id="exp_email" class="form-control"></div>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">التقييم</label>
        <select name="rating" id="exp_rating" class="form-select">
          <option value="">— بدون —</option>
          <?php for ($i=1;$i<=5;$i++): ?><option value="<?= $i ?>"><?= $i ?> <?= $i===1?'نجمة':'نجوم' ?></option><?php endfor; ?>
        </select>
      </div>
      <div class="mb-1"><label class="form-label fw-semibold">ملاحظات</label><textarea name="notes" id="exp_notes" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<!-- Modal: ربط بقضية -->
<div class="modal fade" id="linkModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-link me-2"></i>ربط بقضية</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="link"><input type="hidden" name="expert_id" id="link_expert_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">القضية *</label>
        <select name="case_id" class="form-select" required><option value="">— اختر —</option>
          <?php foreach ($cases_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">الدور</label>
        <select name="role" class="form-select">
          <?php foreach ($_roleMap as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-1"><label class="form-label fw-semibold">ملاحظات</label><input type="text" name="link_notes" class="form-control"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>ربط</button></div>
  </form>
</div></div></div>

<script>
function expNew(){document.getElementById('expTitle').innerHTML='<i class="fas fa-user-doctor me-2"></i>خبير/شاهد جديد';document.getElementById('exp_id').value='';document.getElementById('exp_name').value='';document.getElementById('exp_specialty').value='';document.getElementById('exp_org').value='';document.getElementById('exp_phone').value='';document.getElementById('exp_email').value='';document.getElementById('exp_rating').value='';document.getElementById('exp_notes').value='';}
function expEdit(ex){document.getElementById('expTitle').innerHTML='<i class="fas fa-edit me-2"></i>تعديل خبير';document.getElementById('exp_id').value=ex.id;document.getElementById('exp_name').value=ex.full_name;document.getElementById('exp_specialty').value=ex.specialty||'';document.getElementById('exp_org').value=ex.organization||'';document.getElementById('exp_phone').value=ex.phone||'';document.getElementById('exp_email').value=ex.email||'';document.getElementById('exp_rating').value=ex.rating||'';document.getElementById('exp_notes').value=ex.notes||'';new bootstrap.Modal(document.getElementById('expertModal')).show();}
function linkNew(expertId){document.getElementById('link_expert_id').value=expertId;new bootstrap.Modal(document.getElementById('linkModal')).show();}
</script>

<?php include '../includes/office_footer.php'; ?>
