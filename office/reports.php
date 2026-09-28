<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('reports','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];

if (!hasFeature($conn, $oid, 'has_reports')) {
    header("Location: profile.php?tab=upgrade&feature=reports"); exit;
}

$page_title = 'التقارير المتقدمة';

$_restricted = isRestricted();
$_uid        = (int)($_SESSION['user_id'] ?? 0);
// مدير المهام والمواعيد (صلاحية "اعتماد" على قسم المهام) يرى تقريراً كاملاً لكل الموظفين؛
// غيره يرى مهامه ومواعيده فقط — نفس المبدأ المطبَّق في tasks.php
$_isTaskMgr  = can('tasks','approve');

// كل قسم يظهر في التقرير حسب صلاحية هذا الموظّف بالضبط — نفس صلاحيات النظام الأساسية
$_canCases    = can('cases','view');
$_canSessions = can('sessions','view');
$_canTasks    = can('tasks','view');
$_canAppts    = can('tasks','view'); // المواعيد تتبع نفس صلاحية المهام (لا قسم صلاحيات منفصل لها)
$_canClients  = can('clients','view');
$_canFinance  = can('finance','view') && hasFeature($conn, $oid, 'has_finance');

/* ── فلتر التاريخ (افتراضياً آخر 30 يوماً) ── */
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
$fromE = $conn->real_escape_string($from);
$toE   = $conn->real_escape_string($to);

// قائمة التبويبات المتاحة فعلياً لهذا الموظّف فقط
$tabs = [];
if ($_canCases)    $tabs['cases']    = 'القضايا';
if ($_canSessions) $tabs['sessions'] = 'الجلسات';
if ($_canTasks)    $tabs['tasks']    = 'المهام';
if ($_canAppts)    $tabs['appointments'] = 'المواعيد';
if ($_canClients)  $tabs['clients']  = 'العملاء';
if ($_canFinance)  $tabs['finance']  = 'المالية';

$tab = $_GET['tab'] ?? '';
if (!isset($tabs[$tab])) $tab = array_key_first($tabs) ?? '';

// بحث نصّي + فلتر حالة — يُطبَّقان فقط على التبويب المفتوح حالياً
$_rpQ  = trim($_GET['q'] ?? '');
$_rpQE = $conn->real_escape_string($_rpQ);
$status_f = $_GET['status_f'] ?? '';
// فلتر الموظف لتبويبَي المهام/المواعيد — لمدير المهام فقط (اعتماد)؛ يفصل التقرير
// إما شاملاً لكل الموظفين (بلا فلتر) أو مخصّصاً بموظف واحد يختاره
$_empFilter = $_isTaskMgr ? (int)($_GET['emp_f'] ?? 0) : 0;
$_officeStaff = [];
if ($_isTaskMgr) {
    $_sr = $conn->query("SELECT id,full_name FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
    if ($_sr) while ($_su = $_sr->fetch_assoc()) $_officeStaff[] = $_su;
}

$S_CASE = ['active'=>'نشطة','closed'=>'مغلقة','suspended'=>'موقوفة','won'=>'مكسوبة','lost'=>'خاسرة','settled'=>'متسوية'];
$S_SESS = ['scheduled'=>'مجدولة','held'=>'منعقدة','postponed'=>'مؤجّلة','cancelled'=>'ملغاة'];
$S_TASK = ['pending'=>'معلقة','in_progress'=>'جارية','completed'=>'مكتملة','cancelled'=>'ملغاة'];
$S_APPT = ['scheduled'=>'مجدول','completed'=>'تم','cancelled'=>'ملغي'];
$_apTypes = ['meeting'=>'اجتماع','court'=>'محكمة','consultation'=>'استشارة','other'=>'أخرى'];
$_empFilterName = $_empFilter ? (array_column($_officeStaff, 'full_name', 'id')[$_empFilter] ?? null) : null;
$_statusOptions = ['cases'=>$S_CASE,'sessions'=>$S_SESS,'tasks'=>$S_TASK,'appointments'=>$S_APPT];

/* ═══ القضايا ═══ */
$cases_total = 0; $cases_by_status = []; $cases_list = null;
if ($_canCases) {
    $cw = "c.office_id=$oid AND DATE(c.created_at) BETWEEN '$fromE' AND '$toE'" . caseScope('c');
    if ($tab === 'cases' && $_rpQ !== '') $cw .= " AND (c.case_number LIKE '%$_rpQE%' OR c.title LIKE '%$_rpQE%')";
    if ($tab === 'cases' && $status_f !== '') $cw .= " AND c.status='" . $conn->real_escape_string($status_f) . "'";
    $cases_total = (int)dbVal($conn, "SELECT COUNT(*) FROM cases c WHERE $cw");
    $r = $conn->query("SELECT status, COUNT(*) c FROM cases c WHERE $cw GROUP BY status");
    if ($r) while ($x = $r->fetch_assoc()) $cases_by_status[$x['status']] = (int)$x['c'];
    $cases_list = $conn->query("
        SELECT c.*, cl.full_name client_name
        FROM cases c LEFT JOIN clients cl ON c.client_id = cl.id
        WHERE $cw ORDER BY c.created_at DESC LIMIT 300
    ");
}

/* ═══ الجلسات ═══ */
$sessions_total = 0; $sessions_by_status = []; $sessions_list = null;
if ($_canSessions) {
    $sw = "s.office_id=$oid AND DATE(s.session_date) BETWEEN '$fromE' AND '$toE'" . caseScope('c');
    if ($tab === 'sessions' && $_rpQ !== '') $sw .= " AND (c.case_number LIKE '%$_rpQE%' OR c.title LIKE '%$_rpQE%' OR s.description LIKE '%$_rpQE%')";
    if ($tab === 'sessions' && $status_f !== '') $sw .= " AND s.status='" . $conn->real_escape_string($status_f) . "'";
    $sessions_total = (int)dbVal($conn, "SELECT COUNT(*) FROM sessions s JOIN cases c ON s.case_id=c.id WHERE $sw");
    $r = $conn->query("SELECT s.status, COUNT(*) c FROM sessions s JOIN cases c ON s.case_id=c.id WHERE $sw GROUP BY s.status");
    if ($r) while ($x = $r->fetch_assoc()) $sessions_by_status[$x['status']] = (int)$x['c'];
    $sessions_list = $conn->query("
        SELECT s.*, c.case_number, c.title case_title
        FROM sessions s JOIN cases c ON s.case_id=c.id
        WHERE $sw ORDER BY s.session_date DESC LIMIT 300
    ");
}

/* ═══ المهام ═══ */
$tasks_total = 0; $tasks_by_status = []; $tasks_list = null;
if ($_canTasks) {
    $tw = "t.office_id=$oid AND DATE(t.due_date) BETWEEN '$fromE' AND '$toE'";
    if (!$_isTaskMgr) $tw .= " AND (t.assigned_to_id=$_uid OR (t.assigned_to_id IS NULL AND t.assigned_to='" . $conn->real_escape_string($_SESSION['full_name'] ?? '') . "'))";
    elseif ($_empFilter) $tw .= " AND t.assigned_to_id=$_empFilter";
    if ($tab === 'tasks' && $_rpQ !== '') $tw .= " AND (t.title LIKE '%$_rpQE%' OR t.assigned_to LIKE '%$_rpQE%')";
    if ($tab === 'tasks' && $status_f !== '') $tw .= " AND t.status='" . $conn->real_escape_string($status_f) . "'";
    $tasks_total = (int)dbVal($conn, "SELECT COUNT(*) FROM tasks t WHERE $tw");
    $r = $conn->query("SELECT status, COUNT(*) c FROM tasks t WHERE $tw GROUP BY status");
    if ($r) while ($x = $r->fetch_assoc()) $tasks_by_status[$x['status']] = (int)$x['c'];
    $tasks_list = $conn->query("
        SELECT t.*, c.case_number
        FROM tasks t LEFT JOIN cases c ON t.case_id = c.id
        WHERE $tw ORDER BY t.due_date DESC LIMIT 300
    ");
}

/* ═══ المواعيد ═══ */
$appts_total = 0; $appts_by_status = []; $appts_list = null;
if ($_canAppts) {
    $aw = "a.office_id=$oid AND DATE(a.appointment_date) BETWEEN '$fromE' AND '$toE'";
    if (!$_isTaskMgr) $aw .= " AND a.assigned_to_id=$_uid";
    elseif ($_empFilter) $aw .= " AND a.assigned_to_id=$_empFilter";
    if ($tab === 'appointments' && $_rpQ !== '') $aw .= " AND (a.title LIKE '%$_rpQE%' OR a.location LIKE '%$_rpQE%')";
    if ($tab === 'appointments' && $status_f !== '') $aw .= " AND a.status='" . $conn->real_escape_string($status_f) . "'";
    $appts_total = (int)dbVal($conn, "SELECT COUNT(*) FROM appointments a WHERE $aw");
    $r = $conn->query("SELECT status, COUNT(*) c FROM appointments a WHERE $aw GROUP BY status");
    if ($r) while ($x = $r->fetch_assoc()) $appts_by_status[$x['status']] = (int)$x['c'];
    $appts_list = $conn->query("
        SELECT a.*, cl.full_name client_name, au.full_name assignee_name
        FROM appointments a
        LEFT JOIN clients cl ON a.client_id = cl.id
        LEFT JOIN users au   ON a.assigned_to_id = au.id
        WHERE $aw ORDER BY a.appointment_date DESC LIMIT 300
    ");
}

/* ═══ العملاء ═══ */
$clients_total = 0; $clients_list = null;
if ($_canClients) {
    $clw = "cl.office_id=$oid AND DATE(cl.created_at) BETWEEN '$fromE' AND '$toE'";
    if ($_restricted) $clw .= " AND cl.id IN (SELECT client_id FROM cases c WHERE client_id IS NOT NULL" . caseScope('c') . ")";
    if ($tab === 'clients' && $_rpQ !== '') $clw .= " AND (cl.full_name LIKE '%$_rpQE%' OR cl.phone LIKE '%$_rpQE%')";
    $clients_total = (int)dbVal($conn, "SELECT COUNT(*) FROM clients cl WHERE $clw");
    $clients_list = $conn->query("SELECT * FROM clients cl WHERE $clw ORDER BY cl.created_at DESC LIMIT 300");
}

/* ═══ المالية (حسب صلاحية الشؤون المالية) ═══ */
$fin_income = $fin_expense = 0.0;
$invoices_list = null;
if ($_canFinance) {
    $fwBase = "inv.office_id=$oid AND inv.status='paid' AND DATE(COALESCE(inv.paid_date,inv.created_at)) BETWEEN '$fromE' AND '$toE'" . finScope('inv');
    $fw = $fwBase . (($tab === 'finance' && $_rpQ !== '') ? " AND inv.invoice_number LIKE '%$_rpQE%'" : '');
    $fin_income  = (float)dbVal($conn, "SELECT IFNULL(SUM(total),0) FROM invoices inv WHERE $fw AND (direction='income' OR direction IS NULL)");
    $fin_expense = (float)dbVal($conn, "SELECT IFNULL(SUM(total),0) FROM invoices inv WHERE $fw AND direction='expense'");
    $fwRows = $fwBase . (($tab === 'finance' && $_rpQ !== '') ? " AND (inv.invoice_number LIKE '%$_rpQE%' OR cl.full_name LIKE '%$_rpQE%' OR ca.case_number LIKE '%$_rpQE%')" : '');
    $invoices_list = $conn->query("
        SELECT inv.*, cl.full_name client_name, ca.case_number
        FROM invoices inv
        LEFT JOIN clients cl ON inv.client_id = cl.id
        LEFT JOIN cases   ca ON inv.case_id   = ca.id
        WHERE $fwRows ORDER BY COALESCE(inv.paid_date, inv.created_at) DESC LIMIT 300
    ");
}
$fin_net = $fin_income - $fin_expense;

include '../includes/office_header.php';
?>
<style>
.rp-hdr {
  background: linear-gradient(135deg, var(--mk-navy,#0c1b36), var(--mk-navy4,#1e3a60));
  border-radius: 16px; padding: 22px 26px; color: #fff;
  display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
  margin-bottom: 18px;
}
.rp-hdr-title { font-size: 19px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
.rp-hdr-title i { color: var(--mk-gold4,#e8c040); }
.rp-hdr-sub { font-size: 12.5px; color: rgba(255,255,255,.65); margin-top: 4px; }
.rp-hdr-actions { display: flex; gap: 8px; }
.rp-btn-pdf {
  background: var(--mk-gold4,#e8c040); color: #17233d; border: none; font-weight: 700;
  border-radius: 9px; padding: 9px 18px; font-size: 13.5px; display: inline-flex; align-items: center; gap: 7px;
  text-decoration: none; transition: transform .15s, box-shadow .15s;
}
.rp-btn-pdf:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(232,192,64,.35); color: #17233d; }
.rp-btn-print {
  background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.25);
  border-radius: 9px; padding: 9px 16px; font-size: 13.5px; display: inline-flex; align-items: center; gap: 7px;
}
.rp-btn-print:hover { background: rgba(255,255,255,.2); }

.rp-filter-card { border: 1px solid #e8edf5; border-radius: 14px; }
.rp-filter-row { display: flex; flex-direction: column; gap: 14px; }
.rp-filter-field { display: flex; flex-direction: column; gap: 5px; width: 100%; }
.rp-filter-field label { font-size: 11.5px; font-weight: 700; color: #64748b; }
.rp-filter-field input, .rp-filter-field select { width: 100%; }
.rp-filter-sep { display: none; }
.rp-filter-actions { display: flex; gap: 8px; }
@media (min-width: 768px) {
  .rp-filter-row { flex-direction: row; flex-wrap: wrap; align-items: flex-end; }
  .rp-filter-field { width: auto; min-width: 165px; }
  .rp-filter-actions { margin-inline-start: auto; }
}

.rp-stat { border: 0; border-radius: 14px; color: #fff; height: 100%; overflow: hidden; position: relative; }
.rp-stat .card-body { padding: 18px 20px; position: relative; z-index: 1; }
.rp-stat i.rp-bg-ico { position: absolute; left: -6px; bottom: -14px; font-size: 58px; opacity: .18; z-index: 0; }
.rp-stat-lbl { font-size: 11.5px; opacity: .85; font-weight: 600; }
.rp-stat-val { font-weight: 800; margin-top: 4px; font-size: 26px; }

.rp-tabs { border-bottom: 2px solid #eef1f6; gap: 4px; }
.rp-tabs .nav-link { font-size: 13.5px; font-weight: 700; color: #64748b; border: none; border-radius: 10px 10px 0 0; padding: 10px 18px; }
.rp-tabs .nav-link.active { color: var(--mk-navy,#0c1b36); background: #eef2ff; border-bottom: 3px solid var(--mk-gold4,#e8c040); }

.rp-empty { color:#94a3b8; }
</style>

<div class="rp-hdr">
  <div>
    <div class="rp-hdr-title"><i class="fas fa-chart-line"></i> التقارير المتقدمة</div>
    <div class="rp-hdr-sub"><?= $_restricted ? 'بياناتك الشخصية فقط — القضايا والجلسات والمهام المُسندة إليك' : 'كل بيانات المكتب' ?></div>
  </div>
  <div class="rp-hdr-actions">
    <button type="button" class="rp-btn-print" onclick="window.print()"><i class="fas fa-print"></i> طباعة سريعة</button>
    <button type="button" class="rp-btn-pdf" data-bs-toggle="modal" data-bs-target="#rpExportModal">
      <i class="fas fa-file-pdf"></i> تصدير تقرير PDF
    </button>
  </div>
</div>

<!-- ═══ Modal تصدير PDF: شامل أو مخصص ═══ -->
<div class="modal fade" id="rpExportModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-file-pdf me-2 text-danger"></i>تصدير تقرير PDF</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="GET" action="reports_pdf.php" target="_blank">
        <div class="modal-body">
          <input type="hidden" name="from" value="<?= e($from) ?>">
          <input type="hidden" name="to" value="<?= e($to) ?>">
          <input type="hidden" name="q" value="<?= e($_rpQ) ?>">
          <?php if ($_empFilter): ?><input type="hidden" name="emp_f" value="<?= $_empFilter ?>"><?php endif; ?>

          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="rp_mode" id="rp_mode_full" value="full" checked onchange="rpToggleCustom()">
              <label class="form-check-label fw-semibold" for="rp_mode_full">شامل — كل الأقسام المتاحة لي دفعة واحدة</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="rp_mode" id="rp_mode_custom" value="custom" onchange="rpToggleCustom()">
              <label class="form-check-label fw-semibold" for="rp_mode_custom">مخصص — أحدد بنفسي الأقسام</label>
            </div>
          </div>

          <div id="rp_custom_wrap" class="border rounded p-3" style="display:none;background:#f8fafc">
            <div class="text-muted mb-2" style="font-size:12px">اختر قسماً واحداً أو أكثر يدخل بنفس ملف الـ PDF:</div>
            <?php foreach ($tabs as $tk=>$tl): ?>
            <label class="d-block py-1">
              <input type="checkbox" name="sections[]" value="<?= $tk ?>" disabled <?= $tab===$tk?'checked':'' ?>>
              <?= e($tl) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-file-pdf me-1"></i>تصدير</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function rpToggleCustom() {
  var custom = document.getElementById('rp_mode_custom').checked;
  document.getElementById('rp_custom_wrap').style.display = custom ? 'block' : 'none';
  document.querySelectorAll('#rp_custom_wrap input[type=checkbox]').forEach(function (cb) {
    cb.disabled = !custom;
  });
}
</script>

<div class="card mb-3 rp-filter-card">
  <div class="card-body">
    <form class="rp-filter-row" method="GET">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="rp-filter-field" style="min-width:200px">
        <label>بحث</label>
        <input type="text" name="q" class="form-control" placeholder="رقم قضية، اسم، عنوان..." value="<?= e($_rpQ) ?>">
      </div>
      <?php if (isset($_statusOptions[$tab])): ?>
      <div class="rp-filter-field">
        <label>الحالة</label>
        <select name="status_f" class="form-select">
          <option value="">الكل</option>
          <?php foreach ($_statusOptions[$tab] as $sv=>$sl): ?>
          <option value="<?= $sv ?>" <?= $status_f===$sv?'selected':'' ?>><?= $sl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($_isTaskMgr && in_array($tab, ['tasks','appointments'], true)): ?>
      <div class="rp-filter-field">
        <label>الموظف</label>
        <select name="emp_f" class="form-select">
          <option value="0">كل الموظفين (شامل)</option>
          <?php foreach ($_officeStaff as $_u): ?>
          <option value="<?= $_u['id'] ?>" <?= $_empFilter===(int)$_u['id']?'selected':'' ?>><?= e($_u['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="rp-filter-field">
        <label>من تاريخ</label>
        <input type="date" name="from" class="form-control mk-plain-date" value="<?= e($from) ?>">
      </div>
      <div class="rp-filter-sep"><i class="fas fa-arrow-left-long"></i></div>
      <div class="rp-filter-field">
        <label>إلى تاريخ</label>
        <input type="date" name="to" class="form-control mk-plain-date" value="<?= e($to) ?>">
      </div>
      <div class="rp-filter-actions">
        <button class="btn btn-primary"><i class="fas fa-filter me-1"></i>تطبيق مخصص</button>
        <a href="reports.php?tab=<?= e($tab) ?><?= $_empFilter ? '&emp_f='.$_empFilter : '' ?>&from=2000-01-01&to=<?= date('Y-m-d') ?>" class="btn btn-outline-primary" title="كل الفترات بلا استثناء">
          <i class="fas fa-infinity me-1"></i>تقرير شامل
        </a>
        <a href="reports.php?tab=<?= e($tab) ?>" class="btn btn-outline-secondary" title="إعادة تعيين"><i class="fas fa-rotate-right"></i></a>
      </div>
    </form>
  </div>
</div>

<?php if (!$tabs): ?>
<div class="card"><div class="card-body text-center rp-empty py-5">لا تملك صلاحية اطلاع على أي قسم من التقارير حالياً.</div></div>
<?php else: ?>

<div class="row g-3 mb-3">
  <?php if ($_canCases): ?>
  <div class="col-6 col-lg-3">
    <div class="card rp-stat" style="background:linear-gradient(135deg,#0c1b36,#1a3a6e)">
      <i class="fas fa-gavel rp-bg-ico"></i>
      <div class="card-body">
        <div class="rp-stat-lbl"><i class="fas fa-gavel me-1"></i>القضايا الجديدة</div>
        <div class="rp-stat-val"><?= $cases_total ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($_canSessions): ?>
  <div class="col-6 col-lg-3">
    <div class="card rp-stat" style="background:linear-gradient(135deg,#0891b2,#22d3ee)">
      <i class="fas fa-calendar-days rp-bg-ico"></i>
      <div class="card-body">
        <div class="rp-stat-lbl"><i class="fas fa-calendar-days me-1"></i>الجلسات</div>
        <div class="rp-stat-val"><?= $sessions_total ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($_canTasks): ?>
  <div class="col-6 col-lg-3">
    <div class="card rp-stat" style="background:linear-gradient(135deg,#7c3aed,#a78bfa)">
      <i class="fas fa-list-check rp-bg-ico"></i>
      <div class="card-body">
        <div class="rp-stat-lbl"><i class="fas fa-list-check me-1"></i>المهام</div>
        <div class="rp-stat-val"><?= $tasks_total ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($_canAppts): ?>
  <div class="col-6 col-lg-3">
    <div class="card rp-stat" style="background:linear-gradient(135deg,#0f766e,#2dd4bf)">
      <i class="fas fa-calendar-alt rp-bg-ico"></i>
      <div class="card-body">
        <div class="rp-stat-lbl"><i class="fas fa-calendar-alt me-1"></i>المواعيد</div>
        <div class="rp-stat-val"><?= $appts_total ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($_canFinance): ?>
  <div class="col-6 col-lg-3">
    <div class="card rp-stat" style="background:linear-gradient(135deg,<?= $fin_net>=0?'#16a34a,#22c55e':'#dc2626,#ef4444' ?>)">
      <i class="fas fa-coins rp-bg-ico"></i>
      <div class="card-body">
        <div class="rp-stat-lbl"><i class="fas fa-coins me-1"></i>صافي الفترة</div>
        <div class="rp-stat-val" style="font-size:22px"><?= number_format($fin_net) ?></div>
        <div style="font-size:10.5px;opacity:.75">إيرادات <?= number_format($fin_income) ?> · مصروفات <?= number_format($fin_expense) ?></div>
      </div>
    </div>
  </div>
  <?php elseif ($_canClients): ?>
  <div class="col-6 col-lg-3">
    <div class="card rp-stat" style="background:linear-gradient(135deg,#64748b,#94a3b8)">
      <i class="fas fa-address-book rp-bg-ico"></i>
      <div class="card-body">
        <div class="rp-stat-lbl"><i class="fas fa-address-book me-1"></i>عملاء جدد</div>
        <div class="rp-stat-val"><?= $clients_total ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<ul class="nav nav-tabs rp-tabs mb-3">
  <?php foreach ($tabs as $tk=>$tl): ?>
  <li class="nav-item">
    <a class="nav-link <?= $tab===$tk?'active':'' ?>" href="?tab=<?= $tk ?>&from=<?= e($from) ?>&to=<?= e($to) ?><?= $_empFilter ? '&emp_f='.$_empFilter : '' ?>"><?= $tl ?></a>
  </li>
  <?php endforeach; ?>
</ul>

<?php
// زر تصدير سريع لجدول التبويب المفتوح حالياً بنفس فلاتره (تاريخ/بحث/موظّف) —
// بديل مباشر لنافذة «تصدير شامل/مخصص» أعلى الصفحة، بلا خطوات إضافية
$_pdfBtn = function (string $tabKey) use ($from, $to, $_rpQ, $_empFilter, $_isTaskMgr): string {
    $qs = 'sections[]=' . urlencode($tabKey) . '&from=' . urlencode($from) . '&to=' . urlencode($to);
    if ($_rpQ !== '') $qs .= '&q=' . urlencode($_rpQ);
    if ($_isTaskMgr && $_empFilter && in_array($tabKey, ['tasks', 'appointments'], true)) $qs .= '&emp_f=' . $_empFilter;
    return '<a href="reports_pdf.php?' . $qs . '&view=1" target="_blank" class="btn btn-sm btn-outline-danger ms-auto">'
         . '<i class="fas fa-file-pdf me-1"></i>تصدير PDF</a>';
};
?>
<?php if ($tab === 'cases'): ?>
<div class="card">
  <div class="card-header d-flex flex-wrap gap-2">
    <span><i class="fas fa-gavel me-2 text-primary"></i>القضايا (<?= $cases_total ?>)</span>
    <?php foreach ($cases_by_status as $st=>$ct): ?><?= str_replace('</span>', ' ('.$ct.')</span>', statusBadge($st)) ?><?php endforeach; ?>
    <?= $_pdfBtn('cases') ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>رقم القضية</th><th>العنوان</th><th>العميل</th><th>الحالة</th><th>الأولوية</th><th>تاريخ الإنشاء</th></tr></thead>
        <tbody>
        <?php if ($cases_list && $cases_list->num_rows): while ($c = $cases_list->fetch_assoc()): ?>
        <tr>
          <td class="fw-bold text-primary"><?= e($c['case_number']) ?></td>
          <td style="max-width:260px" class="text-truncate"><?= e($c['title']) ?></td>
          <td><?= e($c['client_name'] ?? '—') ?></td>
          <td><?= statusBadge($c['status']) ?></td>
          <td><?= priorityBadge($c['priority']) ?></td>
          <td><?= dDate($c['created_at']) ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="6" class="text-center text-muted py-4">لا توجد قضايا في هذه الفترة</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'sessions'): ?>
<div class="card">
  <div class="card-header d-flex flex-wrap gap-2">
    <span><i class="fas fa-calendar-days me-2 text-info"></i>الجلسات (<?= $sessions_total ?>)</span>
    <?php foreach ($sessions_by_status as $st=>$ct): ?><span class="badge bg-secondary-subtle text-secondary ms-1"><?= $S_SESS[$st] ?? $st ?>: <?= $ct ?></span><?php endforeach; ?>
    <?= $_pdfBtn('sessions') ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>القضية</th><th>موعد الجلسة</th><th>الحالة</th><th>ما تم في الجلسة</th></tr></thead>
        <tbody>
        <?php if ($sessions_list && $sessions_list->num_rows): while ($s = $sessions_list->fetch_assoc()): ?>
        <tr>
          <td><span class="fw-bold text-primary"><?= e($s['case_number']) ?></span> — <?= e(mb_substr($s['case_title'],0,30)) ?></td>
          <td><?= dDate($s['session_date'], true) ?></td>
          <td><span class="badge bg-info-subtle text-info"><?= $S_SESS[$s['status']] ?? $s['status'] ?></span></td>
          <td style="max-width:260px" class="text-truncate"><?= e($s['description'] ?? '—') ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="4" class="text-center text-muted py-4">لا توجد جلسات في هذه الفترة</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'tasks'): ?>
<div class="card">
  <div class="card-header d-flex flex-wrap gap-2">
    <span><i class="fas fa-list-check me-2 text-purple"></i>المهام (<?= $tasks_total ?>)</span>
    <?php if ($_empFilterName): ?><span class="badge bg-primary-subtle text-primary"><i class="fas fa-user me-1"></i><?= e($_empFilterName) ?></span><?php endif; ?>
    <?php foreach ($tasks_by_status as $st=>$ct): ?><?= str_replace('</span>', ' ('.$ct.')</span>', statusBadge($st)) ?><?php endforeach; ?>
    <?= $_pdfBtn('tasks') ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>المهمة</th><th>القضية</th><th>المكلَّف</th><th>الأولوية</th><th>الحالة</th><th>الموعد</th></tr></thead>
        <tbody>
        <?php if ($tasks_list && $tasks_list->num_rows): while ($t = $tasks_list->fetch_assoc()): ?>
        <tr>
          <td style="max-width:220px" class="text-truncate"><?= e($t['title']) ?></td>
          <td><?= e($t['case_number'] ?? '—') ?></td>
          <td><?= e($t['assigned_to'] ?? '—') ?></td>
          <td><?= priorityBadge($t['priority']) ?></td>
          <td><?= statusBadge($t['status']) ?></td>
          <td><?= dDate($t['due_date'], true) ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="6" class="text-center text-muted py-4">لا توجد مهام في هذه الفترة</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'appointments'): ?>
<div class="card">
  <div class="card-header d-flex flex-wrap gap-2">
    <span><i class="fas fa-calendar-alt me-2 text-teal"></i>المواعيد (<?= $appts_total ?>)</span>
    <?php if ($_empFilterName): ?><span class="badge bg-primary-subtle text-primary"><i class="fas fa-user me-1"></i><?= e($_empFilterName) ?></span><?php endif; ?>
    <?php foreach ($appts_by_status as $st=>$ct): ?><span class="badge bg-secondary-subtle text-secondary ms-1"><?= $S_APPT[$st] ?? $st ?>: <?= $ct ?></span><?php endforeach; ?>
    <?= $_pdfBtn('appointments') ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>الموعد</th><th>النوع</th><th>العميل</th><th>المكلَّف</th><th>الحالة</th><th>التاريخ والوقت</th></tr></thead>
        <tbody>
        <?php if ($appts_list && $appts_list->num_rows): while ($a = $appts_list->fetch_assoc()): ?>
        <tr>
          <td style="max-width:220px" class="text-truncate"><?= e($a['title']) ?></td>
          <td><?= e($_apTypes[$a['type']] ?? $a['type']) ?></td>
          <td><?= e($a['client_name'] ?? '—') ?></td>
          <td><?= e($a['assignee_name'] ?? '—') ?></td>
          <td><?= statusBadge($a['status']) ?></td>
          <td><?= dDate($a['appointment_date'], true) ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="6" class="text-center text-muted py-4">لا توجد مواعيد في هذه الفترة</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'clients'): ?>
<div class="card">
  <div class="card-header d-flex flex-wrap gap-2 align-items-center">
    <span><i class="fas fa-address-book me-2 text-secondary"></i>العملاء الجدد (<?= $clients_total ?>)</span>
    <?= $_pdfBtn('clients') ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>الاسم</th><th>النوع</th><th>الجوال</th><th>البريد</th><th>المدينة</th><th>تاريخ الإضافة</th></tr></thead>
        <tbody>
        <?php if ($clients_list && $clients_list->num_rows): while ($cl = $clients_list->fetch_assoc()): ?>
        <tr>
          <td class="fw-semibold"><?= e($cl['full_name']) ?></td>
          <td><?= $cl['client_type']==='company' ? 'شركة' : 'فرد' ?></td>
          <td><?= e($cl['phone'] ?? '—') ?></td>
          <td><?= e($cl['email'] ?? '—') ?></td>
          <td><?= e($cl['city'] ?? '—') ?></td>
          <td><?= dDate($cl['created_at']) ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="6" class="text-center text-muted py-4">لا يوجد عملاء جدد في هذه الفترة</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'finance' && $_canFinance): ?>
<div class="card">
  <div class="card-header d-flex flex-wrap gap-2 align-items-center">
    <span><i class="fas fa-coins me-2 text-success"></i>الحركة المالية (فواتير مدفوعة)</span>
    <?= $_pdfBtn('finance') ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>الرقم</th><th>العميل</th><th>القضية</th><th>النوع</th><th>المبلغ</th><th>تاريخ السداد</th></tr></thead>
        <tbody>
        <?php if ($invoices_list && $invoices_list->num_rows): while ($inv = $invoices_list->fetch_assoc()): ?>
        <tr>
          <td class="fw-bold"><?= e($inv['invoice_number'] ?? ('#'.$inv['id'])) ?></td>
          <td><?= e($inv['client_name'] ?? '—') ?></td>
          <td><?= e($inv['case_number'] ?? '—') ?></td>
          <td>
            <?php if (($inv['direction'] ?? 'income') === 'expense'): ?>
            <span class="badge bg-danger-subtle text-danger">مصروف</span>
            <?php else: ?>
            <span class="badge bg-success-subtle text-success">إيراد</span>
            <?php endif; ?>
          </td>
          <td class="fw-semibold"><?= number_format((float)$inv['total'],2) ?> ر.س</td>
          <td><?= dDate($inv['paid_date'] ?? $inv['created_at']) ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="6" class="text-center text-muted py-4">لا توجد حركة مالية في هذه الفترة</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; // !$tabs ?>

<?php include '../includes/office_footer.php'; ?>
