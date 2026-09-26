<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('ai_legal','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'ai_legal')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'المساعد القانوني الذكي المتقدم';
$uid = (int)($_SESSION['user_id'] ?? 0);

$conn->query("CREATE TABLE IF NOT EXISTS ai_legal_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    tool VARCHAR(30) NOT NULL,
    input_excerpt VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'log') {
    header('Content-Type: application/json; charset=utf-8');
    $tool = in_array($_POST['tool'] ?? '', ['analyze','research','summarize'], true) ? $_POST['tool'] : 'analyze';
    $excerpt = $conn->real_escape_string(mb_substr(trim($_POST['excerpt'] ?? ''), 0, 200));
    $conn->query("INSERT INTO ai_legal_logs (office_id,user_id,tool,input_excerpt) VALUES ($oid,".($uid ?: 'NULL').",'$tool','$excerpt')");
    echo json_encode(['ok'=>true]); exit;
}

// موظف عادي يشوف فقط استخداماته الخاصة؛ المدير (صلاحية اعتماد) يشوف استخدام الفريق كامل
$_canApprove = can('ai_legal','approve');
$logs = [];
$_ownScope = !$_canApprove ? " AND l.user_id=$uid" : "";
$lr = $conn->query("SELECT l.*, u.full_name FROM ai_legal_logs l LEFT JOIN users u ON l.user_id=u.id WHERE l.office_id=$oid$_ownScope ORDER BY l.id DESC LIMIT 10");
if ($lr) while ($r = $lr->fetch_assoc()) $logs[] = $r;
$_toolLbl = ['analyze'=>'تحليل عقد','research'=>'بحث قانوني','summarize'=>'تلخيص قضية'];

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-brain"></i> المساعد القانوني الذكي المتقدم</div>
<div class="mk-page-sub mb-3">تحليل عقود، بحث قانوني، وتلخيص قضايا بالذكاء الاصطناعي — راجع أي نتيجة قبل الاعتماد عليها</div>

<div class="alert alert-warning" style="font-size:12.5px"><i class="fas fa-triangle-exclamation me-1"></i>الردود توليد ذكاء اصطناعي وقد تحتوي أخطاء — لا تُستخدَم كبديل عن المراجعة القانونية المتخصصة.</div>

<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="pill" href="#tab-analyze"><i class="fas fa-file-contract me-1"></i>تحليل عقد</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tab-research"><i class="fas fa-magnifying-glass me-1"></i>بحث قانوني</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tab-summarize"><i class="fas fa-file-lines me-1"></i>تلخيص قضية</a></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="tab-analyze">
    <div class="card"><div class="card-body">
      <label class="form-label fw-semibold">الصق نص العقد هنا</label>
      <textarea id="in_analyze" class="form-control mb-2" rows="8" placeholder="الصق نص العقد كاملاً أو الجزء الذي تريد تحليله..."></textarea>
      <button class="btn btn-primary" onclick="aiRun('analyze')" id="btn_analyze"><i class="fas fa-wand-magic-sparkles me-1"></i>حلّل العقد</button>
      <div class="mt-3" id="out_analyze"></div>
    </div></div>
  </div>
  <div class="tab-pane fade" id="tab-research">
    <div class="card"><div class="card-body">
      <label class="form-label fw-semibold">اكتب سؤالك القانوني</label>
      <textarea id="in_research" class="form-control mb-2" rows="5" placeholder="مثال: ما هي إجراءات فسخ عقد الإيجار التجاري في النظام السعودي؟"></textarea>
      <button class="btn btn-primary" onclick="aiRun('research')" id="btn_research"><i class="fas fa-wand-magic-sparkles me-1"></i>ابحث</button>
      <div class="mt-3" id="out_research"></div>
    </div></div>
  </div>
  <div class="tab-pane fade" id="tab-summarize">
    <div class="card"><div class="card-body">
      <label class="form-label fw-semibold">الصق تفاصيل أو وقائع القضية</label>
      <textarea id="in_summarize" class="form-control mb-2" rows="8" placeholder="الصق وقائع القضية أو محتوى مستنداتها..."></textarea>
      <button class="btn btn-primary" onclick="aiRun('summarize')" id="btn_summarize"><i class="fas fa-wand-magic-sparkles me-1"></i>لخّص القضية</button>
      <div class="mt-3" id="out_summarize"></div>
    </div></div>
  </div>
</div>

<?php if ($logs): ?>
<div class="card mt-3">
  <div class="card-header"><i class="fas fa-clock-rotate-left me-2 text-muted"></i>آخر الاستخدامات</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <?php foreach ($logs as $l): ?>
      <tr>
        <td style="font-size:12px"><span class="badge bg-light text-dark"><?= $_toolLbl[$l['tool']] ?? $l['tool'] ?></span></td>
        <td style="font-size:12px" class="text-muted"><?= e(mb_substr($l['input_excerpt'] ?? '',0,60)) ?></td>
        <td style="font-size:11px" class="text-muted"><?= e($l['full_name'] ?? '—') ?> — <?= dDate($l['created_at'], true) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
var PROMPTS = {
  analyze:   'أنت مساعد قانوني متخصص. حلّل العقد التالي واستخرج: (1) الأطراف والالتزامات الأساسية، (2) البنود الغامضة أو الخطرة، (3) أي بنود ناقصة يُفترض وجودها في هذا النوع من العقود، (4) توصيات للتحسين. اكتب بالعربية وبنقاط واضحة.',
  research:  'أنت مساعد بحث قانوني متخصص في النظام السعودي. أجب على السؤال بدقة، واذكر الأنظمة أو اللوائح ذات الصلة إن وُجدت، ونبّه دائماً أن هذا ليس استشارة قانونية رسمية.',
  summarize: 'أنت مساعد قانوني. لخّص وقائع القضية التالية في نقاط منظمة: الموضوع، الأطراف، أهم الوقائع بالترتيب الزمني، والنقاط القانونية المحتملة التي تحتاج انتباه المحامي.'
};
function aiRun(tool) {
  var inp = document.getElementById('in_' + tool).value.trim();
  var out = document.getElementById('out_' + tool);
  var btn = document.getElementById('btn_' + tool);
  if (!inp) { alert('يرجى إدخال نص أولاً'); return; }
  btn.disabled = true;
  out.innerHTML = '<div class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>جارٍ التحليل...</div>';

  fetch('ai_proxy.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ system: PROMPTS[tool], messages: [{role:'user', content: inp}] })
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    btn.disabled = false;
    if (d.error) { out.innerHTML = '<div class="alert alert-danger mb-0" style="font-size:13px">' + d.error + '</div>'; return; }
    var html = (d.content || '').replace(/\n/g, '<br>');
    out.innerHTML = '<div class="p-3 rounded" style="background:#f8fafc;font-size:13px;line-height:1.8">' + html + '</div>' +
      '<button class="btn btn-sm btn-outline-secondary mt-2" onclick="navigator.clipboard.writeText(' + JSON.stringify(d.content || '') + ')"><i class="fas fa-copy me-1"></i>نسخ النص</button>';
    var fd = new FormData(); fd.append('form_type','log'); fd.append('tool', tool); fd.append('excerpt', inp);
    fetch('ai_legal.php', {method:'POST', body:fd}).catch(function(){});
  })
  .catch(function(){ btn.disabled = false; out.innerHTML = '<div class="alert alert-danger mb-0" style="font-size:13px">خطأ في الاتصال</div>'; });
}
</script>

<?php include '../includes/office_footer.php'; ?>
