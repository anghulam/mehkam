<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('petition_wizard','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'petition_wizard')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'مولّد صحيفة الدعوى';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('petition_wizard','add');

$conn->query("CREATE TABLE IF NOT EXISTS petition_drafts (
    id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL,
    plaintiff VARCHAR(200) NOT NULL, defendant VARCHAR(200) NOT NULL, case_type VARCHAR(100) DEFAULT NULL,
    facts TEXT, claims TEXT, legal_basis TEXT, case_id INT DEFAULT NULL,
    created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حفظ المسودة ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'draft') {
    requirePerm('petition_wizard', 'add', 'petition_wizard.php?msg=denied');
    $plaintiff = trim($_POST['plaintiff'] ?? ''); $defendant = trim($_POST['defendant'] ?? '');
    if ($plaintiff === '' || $defendant === '') { header('Location: petition_wizard.php?msg=invalid'); exit; }
    $pe = $conn->real_escape_string($plaintiff); $de = $conn->real_escape_string($defendant);
    $ct = $conn->real_escape_string(trim($_POST['case_type'] ?? ''));
    $facts = $conn->real_escape_string(trim($_POST['facts'] ?? ''));
    $claims = $conn->real_escape_string(trim($_POST['claims'] ?? ''));
    $basis = $conn->real_escape_string(trim($_POST['legal_basis'] ?? ''));
    $conn->query("INSERT INTO petition_drafts (office_id,plaintiff,defendant,case_type,facts,claims,legal_basis,created_by) VALUES ($oid,'$pe','$de','$ct','$facts','$claims','$basis',".($uid ?: 'NULL').")");
    header('Location: petition_wizard.php?view=' . $conn->insert_id); exit;
}

/* ── إنشاء قضية من المسودة ── */
if (isset($_GET['make_case'])) {
    requirePerm('petition_wizard', 'add', 'petition_wizard.php?msg=denied');
    $pid = (int)$_GET['make_case'];
    $d = $conn->query("SELECT * FROM petition_drafts WHERE id=$pid AND office_id=$oid")->fetch_assoc();
    if ($d) {
        $pe = $conn->real_escape_string($d['plaintiff']);
        $cl = $conn->query("SELECT id FROM clients WHERE office_id=$oid AND full_name='$pe' LIMIT 1")->fetch_assoc();
        $client_id = $cl ? (int)$cl['id'] : 0;
        if (!$client_id) { $conn->query("INSERT INTO clients (office_id,full_name) VALUES ($oid,'$pe')"); $client_id = (int)$conn->insert_id; }
        $title = $conn->real_escape_string($d['plaintiff'] . ' ضد ' . $d['defendant']);
        $desc = $conn->real_escape_string(($d['facts'] ? "الوقائع:\n" . $d['facts'] . "\n\n" : '') . ($d['claims'] ? "الطلبات:\n" . $d['claims'] : ''));
        $ct = $conn->real_escape_string($d['case_type'] ?: '');
        $conn->query("INSERT INTO cases (office_id,client_id,title,description,case_type,status,priority) VALUES ($oid,$client_id,'$title','$desc','$ct','active','medium')");
        $case_id = (int)$conn->insert_id;
        $conn->query("UPDATE petition_drafts SET case_id=$case_id WHERE id=$pid");
    }
    header("Location: petition_wizard.php?view=$pid"); exit;
}

$view = null;
if (isset($_GET['view'])) $view = $conn->query("SELECT * FROM petition_drafts WHERE id=".(int)$_GET['view']." AND office_id=$oid")->fetch_assoc();

$drafts = [];
$dr = $conn->query("SELECT * FROM petition_drafts WHERE office_id=$oid ORDER BY id DESC LIMIT 50");
if ($dr) while ($x = $dr->fetch_assoc()) $drafts[] = $x;

include '../includes/office_header.php';
?>
<style>@media print { .no-print{display:none!important} .mk-sidebar,.mk-header{display:none!important} .mk-main{margin:0!important} body{background:#fff!important} }</style>

<div class="mk-page-title mb-1 no-print"><i class="fas fa-scroll"></i> مولّد صحيفة الدعوى</div>
<div class="mk-page-sub mb-3 no-print">أجب على الأسئلة التالية لتُصاغ مسودة صحيفة دعوى جاهزة للمراجعة والطباعة</div>

<?php if (!$view): ?>
<?php if (isset($_GET['msg']) && $_GET['msg']==='invalid'): ?><div class="alert alert-danger">أكمل اسم المدعي والمدعى عليه</div><?php endif; ?>

<?php if ($_canAdd): ?>
<div class="card mb-4"><div class="card-header fw-bold"><i class="fas fa-plus me-1"></i>صحيفة جديدة</div><div class="card-body">
  <form method="POST"><input type="hidden" name="form_type" value="draft">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label fw-semibold">المدعي *</label><input name="plaintiff" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">المدعى عليه *</label><input name="defendant" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">نوع القضية</label><input name="case_type" class="form-control" placeholder="عمالية، تجارية..."></div>
      <div class="col-12"><label class="form-label fw-semibold">الوقائع</label><textarea name="facts" class="form-control" rows="4" placeholder="اشرح وقائع القضية بالترتيب الزمني..."></textarea></div>
      <div class="col-12"><label class="form-label fw-semibold">الطلبات</label><textarea name="claims" class="form-control" rows="3" placeholder="ما الذي يطلبه المدعي من المحكمة..."></textarea></div>
      <div class="col-12"><label class="form-label fw-semibold">السند النظامي (اختياري)</label><textarea name="legal_basis" class="form-control" rows="2"></textarea></div>
    </div>
    <button class="btn btn-primary mt-3"><i class="fas fa-wand-magic-sparkles me-1"></i>توليد المسودة</button>
  </form>
</div></div>
<?php endif; ?>

<div class="fw-bold mb-2">مسودات سابقة</div>
<div class="list-group">
<?php if (!$drafts): ?><div class="list-group-item text-muted text-center py-4">لا توجد مسودات بعد</div>
<?php else: foreach ($drafts as $d): ?>
<a href="petition_wizard.php?view=<?= (int)$d['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
  <span><?= e($d['plaintiff']) ?> ضد <?= e($d['defendant']) ?></span><span class="text-muted" style="font-size:12px"><?= e(date('Y-m-d', strtotime($d['created_at']))) ?></span></a>
<?php endforeach; endif; ?>
</div>

<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <a href="petition_wizard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-right me-1"></i>رجوع</a>
  <div class="d-flex gap-2">
    <?php if (!$view['case_id'] && $_canAdd): ?><a href="petition_wizard.php?make_case=<?= (int)$view['id'] ?>" class="btn btn-success btn-sm"><i class="fas fa-gavel me-1"></i>إنشاء قضية من هذه الصحيفة</a>
    <?php elseif ($view['case_id']): ?><a href="cases.php?open_case=<?= (int)$view['case_id'] ?>" class="btn btn-outline-success btn-sm"><i class="fas fa-arrow-left me-1"></i>فتح القضية</a><?php endif; ?>
    <button onclick="window.print()" class="btn btn-outline-primary btn-sm"><i class="fas fa-print me-1"></i>طباعة</button>
  </div>
</div>

<div class="card"><div class="card-body" style="line-height:2.1">
  <div class="text-center fw-bold mb-4" style="font-size:20px">صحيفة دعوى</div>
  <p><b>المدعي:</b> <?= e($view['plaintiff']) ?></p>
  <p><b>المدعى عليه:</b> <?= e($view['defendant']) ?></p>
  <?php if ($view['case_type']): ?><p><b>نوع الدعوى:</b> <?= e($view['case_type']) ?></p><?php endif; ?>
  <?php if ($view['facts']): ?><p><b>الوقائع:</b><br><?= nl2br(e($view['facts'])) ?></p><?php endif; ?>
  <?php if ($view['claims']): ?><p><b>الطلبات:</b><br><?= nl2br(e($view['claims'])) ?></p><?php endif; ?>
  <?php if ($view['legal_basis']): ?><p><b>السند النظامي:</b><br><?= nl2br(e($view['legal_basis'])) ?></p><?php endif; ?>
</div></div>
<div class="alert alert-info mt-3 no-print" style="font-size:12px"><i class="fas fa-circle-info me-1"></i>هذه مسودة أولية للمراجعة — تأكد من صياغتها القانونية النهائية قبل التقديم للمحكمة.</div>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
