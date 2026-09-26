<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/content_helper.php';
require_once '../includes/legal_parse.php';
requireOffice();
if (!can('ai_case_intake','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'ai_case_intake')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'إنشاء القضية من صحيفة الدعوى';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('ai_case_intake','add');
$_canApprove = can('ai_case_intake','approve'); // مستوى المدير: يرى سجل الجميع

$conn->query("CREATE TABLE IF NOT EXISTS ai_intake_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    used_ai TINYINT(1) DEFAULT 0,
    chars INT DEFAULT 0,
    case_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

const ACI_DAILY_AI_LIMIT = 20; // حماية تكلفة مفتاح المنصّة: استدعاءات ذكاء اصطناعي لكل مكتب يومياً

$ai_key   = sc($conn, 'ai_api_key', '');
$ai_model = sc($conn, 'ai_model', 'claude-sonnet-5');
$ai_ready = $ai_key !== '';
$usedToday = (int)$conn->query("SELECT COUNT(*) c FROM ai_intake_log WHERE office_id=$oid AND used_ai=1 AND DATE(created_at)=CURDATE()")->fetch_assoc()['c'];
$ai_available = $ai_ready && $usedToday < ACI_DAILY_AI_LIMIT;

/** استخراج بالذكاء الاصطناعي — يعيد مصفوفة حقول أو ['error'=>..] */
function aci_ai_extract($key, $model, $text) {
    $prompt = "أنت مساعد قانوني سعودي. استخرج من نص صحيفة الدعوى/الحكم التالي بيانات القضية، وأعد JSON صالحاً فقط بلا أي شرح وبهذه المفاتيح بالضبط: "
        . "case_number, court, case_type (واحد من: عمالية، تجارية، أحوال شخصية، جزائية، إدارية، عقارية، أخرى), plaintiff, defendant, "
        . "amount (رقم بالريال أو 0), session_date (YYYY-MM-DD ميلادي أو فارغ), session_time (HH:MM أو فارغ), title (عنوان مختصر للقضية), "
        . "summary (ملخص بالعربية في ٣ أسطر يتضمن الطلبات). لا تخترع معلومة غير موجودة — اترك الحقل فارغاً.\n\nالنص:\n" . $text;
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
        CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'max_tokens' => 1200, 'messages' => [['role' => 'user', 'content' => $prompt]]], JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($res === false) return ['error' => 'تعذّر الاتصال بخدمة الذكاء الاصطناعي: ' . $err];
    $j = json_decode($res, true);
    if ($code !== 200) return ['error' => 'رفضت الخدمة الطلب' . (isset($j['error']['message']) ? ': ' . $j['error']['message'] : '')];
    $txt = $j['content'][0]['text'] ?? '';
    if (preg_match('/\{.*\}/su', $txt, $m)) $txt = $m[0];
    $d = json_decode($txt, true);
    return is_array($d) ? $d : ['error' => 'لم تُرجع الخدمة نتيجة مفهومة'];
}

$result = null; $raw = ''; $note = ''; $usedAi = false;

/* ── تحليل ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'analyze') {
    requirePerm('ai_case_intake', 'add', 'ai_case_intake.php?msg=denied');
    $raw = mb_substr(trim($_POST['petition'] ?? ''), 0, 12000);
    if (mb_strlen($raw) >= 30) {
        $local = lp_parse_petition($raw);
        $result = $local;
        $result['title'] = '';
        if (!empty($_POST['use_ai']) && $ai_available) {
            $ai = aci_ai_extract($ai_key, $ai_model, $raw);
            if (isset($ai['error'])) { $note = $ai['error'] . ' — عُرضت نتيجة المحلل المحلي بدلاً منها.'; }
            else {
                $usedAi = true;
                foreach (['case_number','court','case_type','plaintiff','defendant','title','summary'] as $k) if (!empty($ai[$k])) $result[$k] = (string)$ai[$k];
                if (!empty($ai['amount'])) $result['amount'] = (float)$ai['amount'];
                if (!empty($ai['session_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ai['session_date'])) $result['session_date'] = $ai['session_date'];
                if (!empty($ai['session_time']) && preg_match('/^\d{2}:\d{2}$/', $ai['session_time'])) $result['session_time'] = $ai['session_time'];
                $result['hijri'] = false;
            }
            $conn->query("INSERT INTO ai_intake_log (office_id,user_id,used_ai,chars) VALUES ($oid,".($uid ?: 'NULL').",".($usedAi ? 1 : 0).",".mb_strlen($raw).")");
        }
        if ($result['title'] === '') $result['title'] = trim(($result['plaintiff'] ? $result['plaintiff'] . ' ضد ' . $result['defendant'] : mb_substr($result['summary'], 0, 60)));
    } else {
        $note = 'النص قصير — الصق صحيفة الدعوى أو الحكم كاملاً.';
    }
}

/* ── إنشاء القضية ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'create') {
    requirePerm('ai_case_intake', 'add', 'ai_case_intake.php?msg=denied');
    if (function_exists('canAddMore') && !canAddMore($conn, $oid, 'cases')) { header('Location: ai_case_intake.php?msg=limit'); exit; }
    $title = trim($_POST['title'] ?? ''); $cname = trim($_POST['client_name'] ?? '');
    if ($title === '' || $cname === '') { header('Location: ai_case_intake.php?msg=invalid'); exit; }
    $cne = $conn->real_escape_string($cname);
    $cl = $conn->query("SELECT id FROM clients WHERE office_id=$oid AND full_name='$cne' LIMIT 1")->fetch_assoc();
    if ($cl) $client_id = (int)$cl['id'];
    else { $conn->query("INSERT INTO clients (office_id,full_name) VALUES ($oid,'$cne')"); $client_id = (int)$conn->insert_id; }
    $te = $conn->real_escape_string($title);
    $num = $conn->real_escape_string(trim($_POST['case_number'] ?? ''));
    $court = $conn->real_escape_string(trim($_POST['court'] ?? ''));
    $type = $conn->real_escape_string(trim($_POST['case_type'] ?? ''));
    $desc = $conn->real_escape_string(trim($_POST['summary'] ?? ''));
    $fees = max(0, (float)($_POST['amount'] ?? 0));
    $sd = $_POST['session_date'] ?? ''; $st = $_POST['session_time'] ?? '09:00';
    $dt = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) && preg_match('/^\d{2}:\d{2}$/', $st)) ? "$sd $st:00" : '';
    $conn->query("INSERT INTO cases (office_id,case_number,client_id,title,description,case_type,court_name,status,priority,next_session,fees)
        VALUES ($oid,'$num',$client_id,'$te','$desc','$type','$court','active','medium',".($dt ? "'$dt'" : 'NULL').",$fees)");
    $case_id = (int)$conn->insert_id;
    if ($dt) $conn->query("INSERT INTO sessions (case_id,office_id,session_date,description,status) VALUES ($case_id,$oid,'$dt','جلسة من صحيفة الدعوى','scheduled')");
    $conn->query("INSERT INTO ai_intake_log (office_id,user_id,used_ai,chars,case_id) VALUES ($oid,".($uid ?: 'NULL').",0,0,$case_id)");
    header("Location: ai_case_intake.php?msg=created&case=$case_id"); exit;
}

$clients_arr = [];
$cr = $conn->query("SELECT id, full_name FROM clients WHERE office_id=$oid ORDER BY full_name LIMIT 500");
if ($cr) while ($x = $cr->fetch_assoc()) $clients_arr[] = $x['full_name'];

$log = [];
$lr = $conn->query("SELECT l.*, c.case_number, c.title, u.full_name uname FROM ai_intake_log l LEFT JOIN cases c ON c.id=l.case_id LEFT JOIN users u ON u.id=l.user_id
    WHERE l.office_id=$oid AND l.case_id IS NOT NULL" . ($_canApprove ? '' : " AND l.user_id=$uid") . " ORDER BY l.id DESC LIMIT 15");
if ($lr) while ($x = $lr->fetch_assoc()) $log[] = $x;

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-file-import"></i> إنشاء القضية من صحيفة الدعوى</div>
<div class="mk-page-sub mb-3">الصق نص صحيفة الدعوى أو الحكم — تُستخرج الأطراف والمحكمة والمبلغ والتواريخ وتُنشأ القضية بعد مراجعتك</div>

<?php if (isset($_GET['msg'])):
  $mm = ['created'=>['success','تم إنشاء القضية بنجاح'],'invalid'=>['danger','أكمل عنوان القضية واسم الموكّل'],'limit'=>['warning','بلغت الحد الأقصى للقضايا في باقتك'],'denied'=>['danger','ليست لديك صلاحية']];
  $m = $mm[$_GET['msg']] ?? ['success','تم']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><?= ($_GET['msg']==='created' && !empty($_GET['case'])) ? ' — <a href="cases.php">فتح القضايا</a>' : '' ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($note): ?><div class="alert alert-warning"><?= e($note) ?></div><?php endif; ?>

<div class="row g-3">
<div class="col-lg-6">
  <div class="card"><div class="card-body">
    <form method="POST"><input type="hidden" name="form_type" value="analyze">
      <label class="form-label fw-semibold">نص صحيفة الدعوى / الحكم</label>
      <textarea name="petition" class="form-control" rows="11" required placeholder="الصق النص كاملاً هنا (لا تتوفر قراءة تلقائية للصور أو ملفات PDF الممسوحة)"><?= e($raw) ?></textarea>
      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" name="use_ai" id="use_ai" value="1" <?= $ai_available ? 'checked' : 'disabled' ?>>
        <label class="form-check-label" for="use_ai">استخدام الذكاء الاصطناعي للاستخراج الأدق
          <?php if (!$ai_ready): ?><span class="text-muted">(غير مفعّل على المنصة — يعمل المحلل المحلي)</span>
          <?php elseif (!$ai_available): ?><span class="text-warning">(بلغت حدّ اليوم: <?= ACI_DAILY_AI_LIMIT ?>)</span><?php endif; ?></label>
      </div>
      <?php if ($ai_ready): ?><div class="form-text"><i class="fas fa-shield-halved me-1"></i>عند تفعيل الذكاء الاصطناعي يُرسل نص الصحيفة إلى مزوّد الخدمة لمعالجته — تجنّب إدخال بيانات لا تريد مشاركتها.</div><?php endif; ?>
      <?php if ($_canAdd): ?><button class="btn btn-primary mt-3"><i class="fas fa-wand-magic-sparkles me-1"></i>تحليل</button><?php endif; ?>
    </form>
  </div></div>

  <div class="card mt-3"><div class="card-header fw-bold">آخر القضايا المنشأة</div><div class="list-group list-group-flush">
    <?php if (!$log): ?><div class="list-group-item text-muted text-center py-3">لا توجد بعد</div>
    <?php else: foreach ($log as $x): ?><div class="list-group-item" style="font-size:13px"><b><?= e($x['case_number'] ?: '—') ?></b> <?= e(mb_substr((string)$x['title'], 0, 50)) ?>
      <div class="text-muted" style="font-size:11px"><?= e(date('Y-m-d H:i', strtotime($x['created_at']))) ?><?= $_canApprove ? ' · '.e($x['uname'] ?: '—') : '' ?></div></div>
    <?php endforeach; endif; ?></div></div>
</div>

<?php if ($result !== null): ?>
<div class="col-lg-6">
  <div class="card border-primary"><div class="card-header fw-bold">نتيجة التحليل <?= $usedAi ? '<span class="badge bg-primary">ذكاء اصطناعي</span>' : '<span class="badge bg-secondary">محلي</span>' ?> — راجعها قبل الإنشاء</div>
  <div class="card-body">
    <?php if (!empty($result['hijri'])): ?><div class="alert alert-warning py-2" style="font-size:12px">التاريخ هجري وحُوِّل تقريباً — تحقّق منه.</div><?php endif; ?>
    <form method="POST"><input type="hidden" name="form_type" value="create">
      <div class="mb-2"><label class="form-label fw-semibold">اسم الموكّل *</label>
        <input name="client_name" id="client_name" list="cl_list" class="form-control" value="<?= e($result['plaintiff']) ?>" required>
        <datalist id="cl_list"><?php foreach ($clients_arr as $cn): ?><option value="<?= e($cn) ?>"><?php endforeach; ?></datalist>
        <div class="form-text">
          <?php if ($result['plaintiff']): ?><a href="#" onclick="document.getElementById('client_name').value=<?= e(json_encode($result['plaintiff'], JSON_UNESCAPED_UNICODE)) ?>;return false">المدعي: <?= e($result['plaintiff']) ?></a><?php endif; ?>
          <?php if ($result['defendant']): ?> · <a href="#" onclick="document.getElementById('client_name').value=<?= e(json_encode($result['defendant'], JSON_UNESCAPED_UNICODE)) ?>;return false">المدعى عليه: <?= e($result['defendant']) ?></a><?php endif; ?>
          — يُربط بعميل موجود إن تطابق الاسم، وإلا يُنشأ عميل جديد.</div></div>
      <div class="mb-2"><label class="form-label fw-semibold">عنوان القضية *</label><input name="title" class="form-control" value="<?= e($result['title']) ?>" required></div>
      <div class="row g-2">
        <div class="col-6"><label class="form-label fw-semibold">رقم القضية</label><input name="case_number" class="form-control" value="<?= e($result['case_number']) ?>"></div>
        <div class="col-6"><label class="form-label fw-semibold">النوع</label><input name="case_type" class="form-control" value="<?= e($result['case_type']) ?>"></div>
        <div class="col-12"><label class="form-label fw-semibold">المحكمة</label><input name="court" class="form-control" value="<?= e($result['court']) ?>"></div>
        <div class="col-4"><label class="form-label fw-semibold">المبلغ (ر.س)</label><input type="number" step="0.01" name="amount" class="form-control" value="<?= e($result['amount'] ?: '') ?>"></div>
        <div class="col-4"><label class="form-label fw-semibold">تاريخ الجلسة</label><input type="date" name="session_date" class="form-control" value="<?= e($result['session_date']) ?>"></div>
        <div class="col-4"><label class="form-label fw-semibold">الوقت</label><input type="time" name="session_time" class="form-control" value="<?= e($result['session_time'] ?: '09:00') ?>"></div>
        <div class="col-12"><label class="form-label fw-semibold">الوصف / الملخص</label><textarea name="summary" class="form-control" rows="4"><?= e($result['summary']) ?></textarea></div>
      </div>
      <button class="btn btn-success mt-3"><i class="fas fa-check me-1"></i>إنشاء القضية</button>
    </form>
  </div></div>
</div>
<?php endif; ?>
</div>

<?php include '../includes/office_footer.php'; ?>
