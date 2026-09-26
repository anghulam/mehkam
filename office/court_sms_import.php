<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/legal_parse.php';
requireOffice();
if (!can('court_sms_import','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'court_sms_import')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'الاستيراد الذكي من رسائل المحكمة';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('court_sms_import','add');
$_canApprove = can('court_sms_import','approve'); // مستوى المدير: يرى سجل استيراد الجميع

$conn->query("CREATE TABLE IF NOT EXISTS court_message_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    case_id INT DEFAULT NULL,
    session_id INT DEFAULT NULL,
    raw_text VARCHAR(2000) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$parsed = null; $raw = ''; $matches = [];

/* ── تحليل النص (بدون حفظ) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'parse') {
    requirePerm('court_sms_import', 'add', 'court_sms_import.php?msg=denied');
    $raw = mb_substr(trim($_POST['message'] ?? ''), 0, 4000);
    if ($raw !== '') {
        $parsed = lp_parse_court_message($raw);
        if ($parsed['case_number'] !== '') {
            $num = $conn->real_escape_string($parsed['case_number']);
            $mr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid AND case_number LIKE '%$num%'" . caseScope('cases') . " LIMIT 10");
            if ($mr) while ($x = $mr->fetch_assoc()) $matches[$x['id']] = $x['case_number'] . ' — ' . mb_substr($x['title'], 0, 40);
        }
    }
}

/* ── تأكيد الاستيراد → جلسة جديدة ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'confirm') {
    requirePerm('court_sms_import', 'add', 'court_sms_import.php?msg=denied');
    $case_id = (int)($_POST['case_id'] ?? 0);
    $date = $_POST['session_date'] ?? ''; $time = $_POST['session_time'] ?? '09:00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) { header('Location: court_sms_import.php?msg=invalid'); exit; }
    $case = $conn->query("SELECT id, next_session FROM cases WHERE id=$case_id AND office_id=$oid" . caseScope('cases'))->fetch_assoc();
    if (!$case) { header('Location: court_sms_import.php?msg=invalid'); exit; }
    $dt = "$date $time:00";
    $dup = $conn->query("SELECT id FROM sessions WHERE case_id=$case_id AND office_id=$oid AND session_date='$dt'")->num_rows;
    if ($dup) { header('Location: court_sms_import.php?msg=duplicate'); exit; }
    $desc = $conn->real_escape_string('جلسة مستوردة من رسالة المحكمة' . (!empty($_POST['court']) ? ' — ' . mb_substr(trim($_POST['court']), 0, 100) : ''));
    $conn->query("INSERT INTO sessions (case_id,office_id,session_date,description,status) VALUES ($case_id,$oid,'$dt','$desc','scheduled')");
    $sid = (int)$conn->insert_id;
    if (strtotime($dt) > time() && (empty($case['next_session']) || strtotime($case['next_session']) < time() || strtotime($dt) < strtotime($case['next_session']))) {
        $conn->query("UPDATE cases SET next_session='$dt' WHERE id=$case_id AND office_id=$oid");
    }
    $rawE = $conn->real_escape_string(mb_substr(trim($_POST['raw'] ?? ''), 0, 2000));
    $conn->query("INSERT INTO court_message_imports (office_id,case_id,session_id,raw_text,created_by) VALUES ($oid,$case_id,$sid,'$rawE',".($uid ?: 'NULL').")");
    header('Location: court_sms_import.php?msg=saved'); exit;
}

$cases_arr = [];
$cr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid" . caseScope('cases') . " ORDER BY id DESC LIMIT 300");
if ($cr) while ($x = $cr->fetch_assoc()) $cases_arr[$x['id']] = $x['case_number'] . ' — ' . mb_substr($x['title'], 0, 40);

$log = [];
$lr = $conn->query("SELECT i.*, c.case_number, s.session_date, u.full_name uname FROM court_message_imports i
    LEFT JOIN cases c ON c.id=i.case_id LEFT JOIN sessions s ON s.id=i.session_id LEFT JOIN users u ON u.id=i.created_by
    WHERE i.office_id=$oid" . ($_canApprove ? '' : " AND i.created_by=$uid") . " ORDER BY i.id DESC LIMIT 30");
if ($lr) while ($x = $lr->fetch_assoc()) $log[] = $x;

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-message"></i> الاستيراد الذكي من رسائل المحكمة</div>
<div class="mk-page-sub mb-3">الصق نص رسالة الجلسة أو إشعار ناجز — يُستخرج رقم القضية والتاريخ والوقت وتُنشأ الجلسة في القضية المطابقة</div>

<?php if (isset($_GET['msg'])):
  $mm = ['saved'=>['success','تم إنشاء الجلسة بنجاح'],'invalid'=>['danger','بيانات غير صحيحة — تأكد من التاريخ والقضية'],'duplicate'=>['warning','هذه الجلسة مسجّلة مسبقاً في القضية'],'denied'=>['danger','ليست لديك صلاحية']];
  $m = $mm[$_GET['msg']] ?? ['success','تم']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <form method="POST"><input type="hidden" name="form_type" value="parse">
        <label class="form-label fw-semibold">نص الرسالة</label>
        <textarea name="message" class="form-control" rows="7" required placeholder="مثال: تم تحديد جلسة في القضية رقم 4412345678 بتاريخ 2026/10/12 الساعة 09:30 ص في المحكمة العمالية بالرياض"><?= e($raw) ?></textarea>
        <?php if ($_canAdd): ?><button class="btn btn-primary mt-2"><i class="fas fa-wand-magic-sparkles me-1"></i>تحليل الرسالة</button><?php endif; ?>
      </form>
    </div></div>

    <?php if ($parsed !== null): ?>
    <div class="card mt-3 border-primary"><div class="card-header fw-bold">نتيجة التحليل — راجعها قبل الحفظ</div><div class="card-body">
      <?php if ($parsed['hijri']): ?><div class="alert alert-warning py-2" style="font-size:12px"><i class="fas fa-triangle-exclamation me-1"></i>التاريخ في الرسالة هجري وحُوِّل تقريباً — قد يختلف يوماً واحداً، تحقّق منه.</div><?php endif; ?>
      <?php if (!$parsed['date'] && !$parsed['case_number']): ?><div class="alert alert-danger py-2" style="font-size:12px">لم أتمكن من استخراج بيانات واضحة — أكمل الحقول يدوياً.</div><?php endif; ?>
      <form method="POST"><input type="hidden" name="form_type" value="confirm"><input type="hidden" name="raw" value="<?= e($raw) ?>">
        <div class="mb-3"><label class="form-label fw-semibold">القضية *</label>
          <select name="case_id" class="form-select" required>
            <option value="">— اختر القضية —</option>
            <?php foreach ($matches as $cid => $cn): ?><option value="<?= $cid ?>" selected>✔ <?= e($cn) ?></option><?php endforeach; ?>
            <?php foreach ($cases_arr as $cid => $cn): if (isset($matches[$cid])) continue; ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
          </select>
          <div class="form-text"><?= $parsed['case_number'] ? 'رقم القضية المستخرج: <b>'.e($parsed['case_number']).'</b>'.($matches ? '' : ' — لا توجد قضية مطابقة، اختر يدوياً') : 'لم يُستخرج رقم قضية' ?></div></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label fw-semibold">تاريخ الجلسة *</label><input type="date" name="session_date" class="form-control" value="<?= e($parsed['date']) ?>" required></div>
          <div class="col-6"><label class="form-label fw-semibold">الوقت</label><input type="time" name="session_time" class="form-control" value="<?= e($parsed['time'] ?: '09:00') ?>"></div>
          <div class="col-12"><label class="form-label fw-semibold">المحكمة</label><input name="court" class="form-control" value="<?= e($parsed['court']) ?>"></div>
        </div>
        <button class="btn btn-success mt-3"><i class="fas fa-calendar-plus me-1"></i>إنشاء الجلسة</button>
      </form>
    </div></div>
    <?php endif; ?>
  </div>

  <div class="col-lg-6">
    <div class="card"><div class="card-header fw-bold">آخر عمليات الاستيراد</div>
      <div class="list-group list-group-flush">
      <?php if (!$log): ?><div class="list-group-item text-muted text-center py-4">لا توجد عمليات بعد</div>
      <?php else: foreach ($log as $x): ?>
        <div class="list-group-item" style="font-size:13px">
          <div class="fw-semibold"><?= e($x['case_number'] ?: '—') ?> — <?= $x['session_date'] ? e(date('Y-m-d H:i', strtotime($x['session_date']))) : '' ?></div>
          <div class="text-muted" style="font-size:11px"><?= e(date('Y-m-d H:i', strtotime($x['created_at']))) ?><?= $_canApprove ? ' · '.e($x['uname'] ?: '—') : '' ?></div>
          <div class="text-muted mt-1" style="font-size:11px"><?= e(mb_substr((string)$x['raw_text'], 0, 120)) ?></div>
        </div>
      <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include '../includes/office_footer.php'; ?>
