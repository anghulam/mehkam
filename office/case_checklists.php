<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('case_checklists','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'case_checklists')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'قوائم تحقق القضايا';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('case_checklists','add'); $_canApprove = can('case_checklists','approve'); // مستوى المدير: يدير القوالب

$conn->query("CREATE TABLE IF NOT EXISTS checklist_templates (
    id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, case_type VARCHAR(100) NOT NULL,
    item VARCHAR(255) NOT NULL, sort_order INT DEFAULT 0, INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("CREATE TABLE IF NOT EXISTS case_checklist_items (
    id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, case_id INT NOT NULL,
    label VARCHAR(255) NOT NULL, is_done TINYINT(1) DEFAULT 0, done_at TIMESTAMP NULL DEFAULT NULL,
    sort_order INT DEFAULT 0, INDEX idx_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── إدارة القوالب (مستوى المدير) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'tpl_item') {
    if (!$_canApprove) { header('Location: case_checklists.php?msg=denied'); exit; }
    $ct = $conn->real_escape_string(trim($_POST['case_type'] ?? ''));
    $item = $conn->real_escape_string(trim($_POST['item'] ?? ''));
    if ($ct !== '' && $item !== '') {
        $ord = (int)$conn->query("SELECT COALESCE(MAX(sort_order),0)+1 o FROM checklist_templates WHERE office_id=$oid AND case_type='$ct'")->fetch_assoc()['o'];
        $conn->query("INSERT INTO checklist_templates (office_id,case_type,item,sort_order) VALUES ($oid,'$ct','$item',$ord)");
    }
    header('Location: case_checklists.php?tab=templates&msg=saved'); exit;
}
if (isset($_GET['del_tpl_item']) && $_canApprove) {
    $conn->query("DELETE FROM checklist_templates WHERE id=".(int)$_GET['del_tpl_item']." AND office_id=$oid");
    header('Location: case_checklists.php?tab=templates&msg=deleted'); exit;
}

/* ── توليد قائمة تحقق لقضية من قالب نوعها ── */
if (isset($_GET['generate'])) {
    requirePerm('case_checklists', 'add', 'case_checklists.php?msg=denied');
    $cid = (int)$_GET['generate'];
    $case = $conn->query("SELECT id, case_type FROM cases WHERE id=$cid AND office_id=$oid" . caseScope('cases'))->fetch_assoc();
    if ($case) {
        $ct = $conn->real_escape_string($case['case_type'] ?: '');
        $tr = $conn->query("SELECT item, sort_order FROM checklist_templates WHERE office_id=$oid AND case_type='$ct' ORDER BY sort_order");
        if ($tr) while ($t = $tr->fetch_assoc()) {
            $it = $conn->real_escape_string($t['item']);
            $conn->query("INSERT INTO case_checklist_items (office_id,case_id,label,sort_order) VALUES ($oid,$cid,'$it',".(int)$t['sort_order'].")");
        }
    }
    header("Location: case_checklists.php?open=$cid&msg=saved"); exit;
}

/* ── إضافة بند مخصّص ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'item') {
    requirePerm('case_checklists', 'add', 'case_checklists.php?msg=denied');
    $cid = (int)($_POST['case_id'] ?? 0);
    $ok = $conn->query("SELECT id FROM cases WHERE id=$cid AND office_id=$oid" . caseScope('cases'))->fetch_assoc();
    $label = trim($_POST['label'] ?? '');
    if ($ok && $label !== '') {
        $le = $conn->real_escape_string($label);
        $ord = (int)$conn->query("SELECT COALESCE(MAX(sort_order),0)+1 o FROM case_checklist_items WHERE case_id=$cid")->fetch_assoc()['o'];
        $conn->query("INSERT INTO case_checklist_items (office_id,case_id,label,sort_order) VALUES ($oid,$cid,'$le',$ord)");
    }
    header("Location: case_checklists.php?open=$cid"); exit;
}
if (isset($_GET['toggle'])) {
    $iid = (int)$_GET['toggle'];
    $row = $conn->query("SELECT * FROM case_checklist_items WHERE id=$iid AND office_id=$oid")->fetch_assoc();
    if ($row) {
        $done = $row['is_done'] ? 0 : 1;
        $conn->query("UPDATE case_checklist_items SET is_done=$done, done_at=" . ($done ? 'NOW()' : 'NULL') . " WHERE id=$iid");
        header("Location: case_checklists.php?open=" . (int)$row['case_id']); exit;
    }
    header('Location: case_checklists.php'); exit;
}
if (isset($_GET['del_item'])) {
    $row = $conn->query("SELECT case_id FROM case_checklist_items WHERE id=".(int)$_GET['del_item']." AND office_id=$oid")->fetch_assoc();
    if ($row) { $conn->query("DELETE FROM case_checklist_items WHERE id=".(int)$_GET['del_item']); header("Location: case_checklists.php?open=".(int)$row['case_id']); exit; }
    header('Location: case_checklists.php'); exit;
}

$tab = ($_GET['tab'] ?? '') === 'templates' ? 'templates' : 'cases';

/* ── قائمة القضايا مع نسبة الإنجاز ── */
$cases = [];
$cr = $conn->query("SELECT c.id, c.case_number, c.title, c.case_type,
    (SELECT COUNT(*) FROM case_checklist_items i WHERE i.case_id=c.id) total,
    (SELECT COUNT(*) FROM case_checklist_items i WHERE i.case_id=c.id AND i.is_done=1) done
    FROM cases c WHERE c.office_id=$oid AND c.status='active'" . caseScope('c') . " ORDER BY c.id DESC LIMIT 200");
if ($cr) while ($x = $cr->fetch_assoc()) $cases[] = $x;

$open = null; $items = [];
if (isset($_GET['open'])) {
    $ocid = (int)$_GET['open'];
    $open = $conn->query("SELECT * FROM cases WHERE id=$ocid AND office_id=$oid" . caseScope('cases'))->fetch_assoc();
    if ($open) {
        $ir = $conn->query("SELECT * FROM case_checklist_items WHERE case_id=$ocid AND office_id=$oid ORDER BY sort_order, id");
        if ($ir) while ($x = $ir->fetch_assoc()) $items[] = $x;
    }
}

$templates = [];
if ($tab === 'templates' && $_canApprove) {
    $tr2 = $conn->query("SELECT * FROM checklist_templates WHERE office_id=$oid ORDER BY case_type, sort_order");
    if ($tr2) while ($x = $tr2->fetch_assoc()) $templates[$x['case_type']][] = $x;
}

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-list-check"></i> قوائم تحقق القضايا</div>
<div class="mk-page-sub mb-3">قائمة مستندات وإجراءات مطلوبة لكل قضية حسب نوعها — تعلّم كل بند فور إنجازه</div>

<?php if (isset($_GET['msg'])): ?><div class="alert alert-success alert-dismissible fade show">تم بنجاح<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='cases'?'active':'' ?>" href="case_checklists.php">القضايا</a></li>
  <?php if ($_canApprove): ?><li class="nav-item"><a class="nav-link <?= $tab==='templates'?'active':'' ?>" href="case_checklists.php?tab=templates">القوالب حسب نوع القضية</a></li><?php endif; ?>
</ul>

<?php if ($tab === 'cases'): ?>
<?php if (!$open): ?>
<div class="row g-3">
<?php if (!$cases): ?><div class="col-12"><div class="text-muted text-center py-5">لا توجد قضايا نشطة</div></div>
<?php else: foreach ($cases as $c): $pct = $c['total'] ? round($c['done']/$c['total']*100) : 0; ?>
<div class="col-md-6 col-lg-4"><div class="card h-100"><div class="card-body">
  <div class="fw-bold"><?= e($c['case_number']) ?></div>
  <div class="text-muted" style="font-size:12px"><?= e(mb_substr($c['title'],0,40)) ?></div>
  <?php if ($c['total']): ?>
  <div class="progress my-2" style="height:8px"><div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div></div>
  <div style="font-size:12px"><?= $c['done'] ?>/<?= $c['total'] ?> مكتمل</div>
  <?php endif; ?>
  <a href="case_checklists.php?open=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary mt-2 w-100"><i class="fas fa-list-check me-1"></i>فتح القائمة</a>
</div></div></div>
<?php endforeach; endif; ?>
</div>

<?php else: ?>
<a href="case_checklists.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="fas fa-arrow-right me-1"></i>رجوع</a>
<div class="card"><div class="card-body">
  <h5><?= e($open['case_number']) ?> — <?= e($open['title']) ?></h5>
  <?php if (!$items && $_canAdd): ?>
  <a href="case_checklists.php?generate=<?= (int)$open['id'] ?>" class="btn btn-primary btn-sm mt-2"><i class="fas fa-wand-magic-sparkles me-1"></i>توليد من قالب «<?= e($open['case_type'] ?: 'عام') ?>»</a>
  <?php endif; ?>
  <ul class="list-group list-group-flush mt-3">
  <?php foreach ($items as $it): ?>
  <li class="list-group-item d-flex align-items-center gap-2">
    <a href="case_checklists.php?open=<?= (int)$open['id'] ?>&toggle=<?= (int)$it['id'] ?>" class="text-decoration-none"><i class="fa<?= $it['is_done']?'s text-success':'r' ?> fa-circle-check fa-lg"></i></a>
    <span class="flex-grow-1 <?= $it['is_done']?'text-decoration-line-through text-muted':'' ?>"><?= e($it['label']) ?></span>
    <a href="case_checklists.php?open=<?= (int)$open['id'] ?>&del_item=<?= (int)$it['id'] ?>" class="text-danger" onclick="return confirm('حذف البند؟')"><i class="fas fa-trash"></i></a>
  </li>
  <?php endforeach; ?>
  <?php if (!$items): ?><li class="list-group-item text-muted text-center">لا توجد بنود بعد</li><?php endif; ?>
  </ul>
  <?php if ($_canAdd): ?>
  <form method="POST" class="d-flex gap-2 mt-3"><input type="hidden" name="form_type" value="item"><input type="hidden" name="case_id" value="<?= (int)$open['id'] ?>">
    <input name="label" class="form-control form-control-sm" placeholder="إضافة بند جديد..." required><button class="btn btn-sm btn-primary">إضافة</button></form>
  <?php endif; ?>
</div></div>
<?php endif; ?>

<?php else: ?>
<form method="POST" class="card card-body mb-3"><input type="hidden" name="form_type" value="tpl_item">
  <div class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label fw-semibold">نوع القضية</label><input name="case_type" class="form-control form-control-sm" placeholder="مثال: عمالية" required></div>
    <div class="col-md-6"><label class="form-label fw-semibold">بند القائمة</label><input name="item" class="form-control form-control-sm" required></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">إضافة</button></div>
  </div>
</form>
<?php if (!$templates): ?><div class="text-muted text-center py-5">لا توجد قوالب بعد</div>
<?php else: foreach ($templates as $ct => $its): ?>
<div class="card mb-3"><div class="card-header fw-bold"><?= e($ct) ?></div>
  <ul class="list-group list-group-flush">
  <?php foreach ($its as $it): ?><li class="list-group-item d-flex justify-content-between"><?= e($it['item']) ?><a href="case_checklists.php?tab=templates&del_tpl_item=<?= (int)$it['id'] ?>" class="text-danger" onclick="return confirm('حذف؟')"><i class="fas fa-trash"></i></a></li><?php endforeach; ?>
  </ul>
</div>
<?php endforeach; endif; ?>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
