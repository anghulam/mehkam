<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('clients','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];

$client_id = (int)($_GET['id'] ?? 0);
$client = $conn->query("SELECT * FROM clients WHERE id=$client_id AND office_id=$oid LIMIT 1")->fetch_assoc();
if (!$client) { header('Location: clients.php'); exit; }
$page_title = 'ملف العميل — ' . $client['full_name'];

/* ── جدول سجل التواصل (مكالمات / اجتماعات / ملاحظات) — إنشاء ذاتي ── */
try {
    $conn->query("CREATE TABLE IF NOT EXISTS client_interactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        office_id INT NOT NULL,
        client_id INT NOT NULL,
        type ENUM('call','meeting','whatsapp','email','note') NOT NULL DEFAULT 'call',
        interaction_date DATE NOT NULL,
        summary TEXT,
        created_by INT DEFAULT NULL,
        created_by_name VARCHAR(150) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_office_client (office_id, client_id)
    )");
} catch (\Throwable $e) {}

/* ── حذف تواصل ── */
if (isset($_GET['del_int'])) {
    requirePerm('clients', 'delete', "client_file.php?id=$client_id&msg=denied");
    $conn->query("DELETE FROM client_interactions WHERE id=".(int)$_GET['del_int']." AND office_id=$oid AND client_id=$client_id");
    header("Location: client_file.php?id=$client_id&tab=interactions&msg=deleted"); exit;
}

/* ── بوابة العميل (موديول إضافي) ── */
$_canPortal = hasModule($conn, $oid, 'client_portal') && can('clients','edit');
if ($_canPortal) {
    try { $conn->query("ALTER TABLE clients ADD COLUMN portal_username VARCHAR(60) DEFAULT NULL UNIQUE"); } catch (\Throwable $e) {}
    try { $conn->query("ALTER TABLE clients ADD COLUMN portal_password_hash VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $conn->query("ALTER TABLE clients ADD COLUMN portal_enabled TINYINT(1) DEFAULT 0"); } catch (\Throwable $e) {}
    try { $conn->query("ALTER TABLE clients ADD COLUMN portal_created_at TIMESTAMP NULL DEFAULT NULL"); } catch (\Throwable $e) {}

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'portal_enable') {
        $base = preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8','ASCII//TRANSLIT',$client['full_name']) ?: 'client'));
        $base = $base !== '' ? $base : 'client' . $client_id;
        $username = $base . $client_id;
        $tmp_pass = substr(bin2hex(random_bytes(4)), 0, 8);
        $hash = password_hash($tmp_pass, PASSWORD_BCRYPT);
        $userEsc = $conn->real_escape_string($username);
        $conn->query("UPDATE clients SET portal_username='$userEsc', portal_password_hash='$hash', portal_enabled=1, portal_created_at=NOW() WHERE id=$client_id AND office_id=$oid");
        header("Location: client_file.php?id=$client_id&portal_user=".urlencode($username)."&portal_pass=".urlencode($tmp_pass)); exit;
    }
    if (isset($_GET['portal_disable'])) {
        $conn->query("UPDATE clients SET portal_enabled=0 WHERE id=$client_id AND office_id=$oid");
        header("Location: client_file.php?id=$client_id"); exit;
    }
    if (isset($_GET['portal_reset_pass'])) {
        $tmp_pass = substr(bin2hex(random_bytes(4)), 0, 8);
        $hash = password_hash($tmp_pass, PASSWORD_BCRYPT);
        $conn->query("UPDATE clients SET portal_password_hash='$hash' WHERE id=$client_id AND office_id=$oid");
        header("Location: client_file.php?id=$client_id&portal_pass=".urlencode($tmp_pass)); exit;
    }
    // إعادة تحميل بيانات العميل بعد أي تعديل أعلاه
    $client = $conn->query("SELECT * FROM clients WHERE id=$client_id AND office_id=$oid LIMIT 1")->fetch_assoc();
}

/* ── حفظ / تعديل تواصل ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'interaction') {
    $int_id = (int)($_POST['int_id'] ?? 0);
    requirePerm('clients', $int_id ? 'edit' : 'add', "client_file.php?id=$client_id&msg=denied");
    $itype = in_array($_POST['type'] ?? '', ['call','meeting','whatsapp','email','note'], true) ? $_POST['type'] : 'call';
    $idate = $conn->real_escape_string(trim($_POST['interaction_date'] ?? '') !== '' ? $_POST['interaction_date'] : date('Y-m-d'));
    $summary = $conn->real_escape_string(trim($_POST['summary'] ?? ''));
    if ($int_id) {
        $conn->query("UPDATE client_interactions SET type='$itype', interaction_date='$idate', summary='$summary'
            WHERE id=$int_id AND office_id=$oid AND client_id=$client_id");
    } else {
        $uid   = (int)($_SESSION['user_id'] ?? 0);
        $uname = $conn->real_escape_string($_SESSION['full_name'] ?? '');
        $conn->query("INSERT INTO client_interactions (office_id,client_id,type,interaction_date,summary,created_by,created_by_name)
            VALUES ($oid,$client_id,'$itype','$idate','$summary',".($uid ?: 'NULL').",'$uname')");
    }
    header("Location: client_file.php?id=$client_id&tab=interactions&msg=saved"); exit;
}

/* ── صلاحيات وميزات الباقة لكل قسم ── */
$_canCasesView    = can('cases','view');
$_canSessionsView = can('sessions','view');
$_canContracts    = can('contracts','view') && hasFeature($conn, $oid, 'has_contracts');
$_canInvoices     = can('invoices','view') && can('finance','view') && hasFeature($conn, $oid, 'has_invoices');
$_canServices     = can('services','view') && hasFeature($conn, $oid, 'has_digital_services');

$_returnHere = 'client_file.php?id=' . $client_id;

/* ── بيانات القضايا ── */
$cases = []; $cases_count = 0;
if ($_canCasesView) {
    // نفس نطاق البيانات المطبَّق في cases.php: موظّف مقيَّد يرى فقط قضاياه المُسندة
    $cr = $conn->query("SELECT c.* FROM cases c WHERE c.client_id=$client_id AND c.office_id=$oid" . caseScope('c') . " ORDER BY c.id DESC");
    if ($cr) while ($row = $cr->fetch_assoc()) $cases[] = $row;
    $cases_count = count($cases);
}

/* ── الجلسات (عبر قضايا هذا العميل) ── */
$sessions = []; $sessions_count = 0;
if ($_canCasesView && $_canSessionsView) {
    $sr = $conn->query("SELECT s.*, c.case_number, c.title case_title, c.id case_pk
        FROM sessions s JOIN cases c ON s.case_id = c.id
        WHERE c.client_id=$client_id AND s.office_id=$oid" . caseScope('c') . " ORDER BY s.session_date DESC");
    if ($sr) while ($row = $sr->fetch_assoc()) $sessions[] = $row;
    $sessions_count = count($sessions);
}

/* ── العقود والوكالات ── */
$contracts = []; $poas = []; $contracts_count = 0;
if ($_canContracts) {
    $ctr = $conn->query("SELECT * FROM contracts WHERE client_id=$client_id AND office_id=$oid ORDER BY id DESC");
    if ($ctr) while ($row = $ctr->fetch_assoc()) $contracts[] = $row;
    $por = $conn->query("SELECT * FROM poa WHERE client_id=$client_id AND office_id=$oid ORDER BY id DESC");
    if ($por) while ($row = $por->fetch_assoc()) $poas[] = $row;
    $contracts_count = count($contracts) + count($poas);
}

/* ── الفواتير ── */
$invoices = []; $invoices_total = 0;
if ($_canInvoices) {
    $ir = $conn->query("SELECT * FROM invoices WHERE client_id=$client_id AND office_id=$oid ORDER BY created_at DESC");
    if ($ir) while ($row = $ir->fetch_assoc()) { $invoices[] = $row; if ($row['direction'] !== 'expense') $invoices_total += (float)$row['total']; }
}

/* ── الخدمات الرقمية ── */
$services = [];
if ($_canServices) {
    $svr = $conn->query("SELECT * FROM service_requests WHERE client_id=$client_id AND office_id=$oid ORDER BY created_at DESC");
    if ($svr) while ($row = $svr->fetch_assoc()) $services[] = $row;
}

/* ── سجل التواصل ── */
$interactions = [];
$intr = $conn->query("SELECT * FROM client_interactions WHERE client_id=$client_id AND office_id=$oid ORDER BY interaction_date DESC, id DESC");
if ($intr) while ($row = $intr->fetch_assoc()) $interactions[] = $row;

$edit_int = null;
if (isset($_GET['edit_int'])) {
    foreach ($interactions as $row) { if ($row['id'] == (int)$_GET['edit_int']) { $edit_int = $row; break; } }
}

/* ── التبويبات المتاحة فعلياً حسب الصلاحيات/الباقة ── */
$_tabs = ['overview' => 'نظرة عامة'];
if ($_canCasesView) $_tabs['cases'] = 'القضايا (' . $cases_count . ')';
if ($_canCasesView && $_canSessionsView) $_tabs['sessions'] = 'الجلسات (' . $sessions_count . ')';
if ($_canContracts) $_tabs['contracts'] = 'العقود والوكالات (' . $contracts_count . ')';
if ($_canInvoices) $_tabs['invoices'] = 'الفواتير (' . count($invoices) . ')';
if ($_canServices) $_tabs['services'] = 'الخدمات الرقمية (' . count($services) . ')';
$_tabs['interactions'] = 'سجل التواصل (' . count($interactions) . ')';

$tab = $_GET['tab'] ?? 'overview';
if (!isset($_tabs[$tab])) $tab = 'overview';

$_typeMap = ['call'=>['مكالمة','fa-phone','#2563eb'],'meeting'=>['اجتماع','fa-people-arrows','#7c3aed'],
    'whatsapp'=>['واتساب','fa-whatsapp','#25d366'],'email'=>['بريد','fa-envelope','#d97706'],'note'=>['ملاحظة','fa-note-sticky','#64748b']];

include '../includes/office_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-3">
    <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center fw-bold"
         style="width:52px;height:52px;font-size:20px;flex-shrink:0"><?= mb_substr($client['full_name'],0,1) ?></div>
    <div>
      <div class="fw-bold" style="font-size:18px"><?= e($client['full_name']) ?>
        <?= $client['client_type']==='company' ? "<span class='badge bg-info ms-1'>شركة</span>" : "<span class='badge bg-secondary ms-1'>فرد</span>" ?>
      </div>
      <div class="text-muted" style="font-size:13px">
        <?php if ($client['phone']): ?><i class="fas fa-phone me-1"></i><?= e($client['phone']) ?><?php endif; ?>
        <?php if ($client['email']): ?> &nbsp;·&nbsp; <i class="fas fa-envelope me-1"></i><?= e($client['email']) ?><?php endif; ?>
        <?php if ($client['city']): ?> &nbsp;·&nbsp; <i class="fas fa-location-dot me-1"></i><?= e($client['city']) ?><?php endif; ?>
        <?php if ($client['id_number']): ?> &nbsp;·&nbsp; <span class="font-monospace"><?= e($client['id_number']) ?></span><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a href="clients.php?edit=<?= $client_id ?>" class="btn btn-outline-primary"><i class="fas fa-user-pen me-1"></i>تعديل بيانات العميل</a>
    <a href="clients.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-right me-1"></i>رجوع للعملاء</a>
  </div>
</div>

<ul class="nav nav-pills mb-3 flex-wrap">
  <?php foreach ($_tabs as $tk => $tl): ?>
  <li class="nav-item"><a class="nav-link <?= $tab===$tk?'active':'' ?>" href="client_file.php?id=<?= $client_id ?>&tab=<?= $tk ?>"><?= $tl ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): ?>
<div class="row g-3">
  <?php
  $kpis = [];
  if ($_canCasesView) $kpis[] = ['القضايا', $cases_count, 'gavel', 'primary'];
  if ($_canCasesView && $_canSessionsView) {
      $upcoming = 0; foreach ($sessions as $s) if ($s['status']==='scheduled' && strtotime($s['session_date']) >= time()) $upcoming++;
      $kpis[] = ['جلسات قادمة', $upcoming, 'calendar-days', 'info'];
  }
  if ($_canContracts) $kpis[] = ['عقود ووكالات', $contracts_count, 'file-signature', 'warning'];
  if ($_canInvoices) $kpis[] = ['إجمالي الفواتير', number_format($invoices_total,0) . ' ر.س', 'file-invoice', 'success'];
  if ($_canServices) $kpis[] = ['طلبات خدمات', count($services), 'bolt', 'secondary'];
  $kpis[] = ['آخر تواصل', $interactions ? dDate($interactions[0]['interaction_date'], false, false) : '—', 'comments', 'dark'];
  foreach ($kpis as [$lbl,$val,$ic,$col]): ?>
  <div class="col-6 col-lg-<?= max(2, intdiv(12,count($kpis))) ?>">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3 py-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center text-<?= $col ?>" style="width:42px;height:42px;font-size:17px;flex-shrink:0;background:rgba(0,0,0,.05)">
        <i class="fas fa-<?= $ic ?>"></i>
      </div>
      <div>
        <div class="fw-bold" style="font-size:16px"><?= $val ?></div>
        <div class="text-muted" style="font-size:11px"><?= $lbl ?></div>
      </div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($client['notes']): ?>
<div class="card mt-3"><div class="card-header"><i class="fas fa-note-sticky me-2 text-muted"></i>ملاحظات على ملف العميل</div>
  <div class="card-body" style="font-size:13px;white-space:pre-wrap"><?= e($client['notes']) ?></div></div>
<?php endif; ?>

<?php if ($_canPortal): ?>
<div class="card mt-3">
  <div class="card-header"><i class="fas fa-door-open me-2 text-primary"></i>بوابة العميل</div>
  <div class="card-body">
    <?php if (isset($_GET['portal_pass'])): ?>
    <div class="alert alert-success" style="font-size:13px">
      <i class="fas fa-key me-1"></i>بيانات الدخول الجديدة — سلّمها للعميل الآن (لن تظهر كلمة المرور مرة أخرى):
      <div class="mt-2 d-flex gap-3 flex-wrap">
        <div>اسم المستخدم: <b class="font-monospace"><?= e($_GET['portal_user'] ?? $client['portal_username']) ?></b></div>
        <div>كلمة المرور: <b class="font-monospace"><?= e($_GET['portal_pass']) ?></b></div>
      </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($client['portal_enabled'])): ?>
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <span class="badge bg-success bg-opacity-10 text-success py-2 px-3"><i class="fas fa-check-circle me-1"></i>البوابة مفعّلة</span>
      <span style="font-size:13px">اسم المستخدم: <b class="font-monospace"><?= e($client['portal_username']) ?></b></span>
      <a href="client_file.php?id=<?= $client_id ?>&portal_reset_pass=1" class="btn btn-sm btn-outline-secondary" onclick="return confirm('توليد كلمة مرور جديدة؟ الكلمة القديمة ستتوقف عن العمل.')"><i class="fas fa-key me-1"></i>إعادة تعيين كلمة المرور</a>
      <a href="client_file.php?id=<?= $client_id ?>&portal_disable=1" class="btn btn-sm btn-outline-danger" onclick="return confirm('تعطيل بوابة العميل؟')"><i class="fas fa-ban me-1"></i>تعطيل البوابة</a>
    </div>
    <?php else: ?>
    <p class="text-muted mb-2" style="font-size:13px">فعّل بوابة خاصة يدخلها هذا العميل ليشوف قضاياه وفواتيره ويتواصل مع مكتبك مباشرة.</p>
    <form method="POST"><input type="hidden" name="form_type" value="portal_enable">
      <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-door-open me-1"></i>تفعيل بوابة العميل</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php if ($tab === 'cases'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="fw-semibold">قضايا العميل</div>
  <?php if (can('cases','add')): ?>
  <a class="btn btn-primary btn-sm" href="cases.php?client_id=<?= $client_id ?>&return_to=<?= urlencode($_returnHere.'&tab=cases') ?>">
    <i class="fas fa-plus me-1"></i>قضية جديدة لهذا العميل</a>
  <?php endif; ?>
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>رقم القضية</th><th>العنوان</th><th>النوع</th><th>المحكمة</th><th>الحالة</th><th>الجلسة القادمة</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$cases): ?>
<tr><td colspan="7" class="text-center text-muted py-4">لا توجد قضايا لهذا العميل بعد</td></tr>
<?php else: foreach ($cases as $c): ?>
<tr>
  <td class="font-monospace"><?= e($c['case_number']) ?></td>
  <td><?= e($c['title']) ?></td>
  <td><?= e($c['case_type'] ?: '—') ?></td>
  <td><?= e($c['court_name'] ?: '—') ?></td>
  <td><?= statusBadge($c['status']) ?></td>
  <td style="font-size:12px"><?= $c['next_session'] ? dDate($c['next_session'], true) : '—' ?></td>
  <td class="d-flex gap-1">
    <?php if ($_canSessionsView): ?>
    <a href="cases.php?open_case=<?= $c['id'] ?>" class="btn btn-sm btn-outline-info" title="جلسات القضية"><i class="fas fa-calendar-days"></i></a>
    <?php endif; ?>
    <?php if (can('cases','edit')): ?>
    <a href="cases.php?edit=<?= $c['id'] ?>&return_to=<?= urlencode($_returnHere.'&tab=cases') ?>" class="btn btn-sm btn-outline-primary" title="تعديل"><i class="fas fa-edit"></i></a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php if ($tab === 'sessions'): ?>
<div class="fw-semibold mb-3">جلسات قضايا هذا العميل</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>القضية</th><th>التاريخ</th><th>الحالة</th><th>الوصف</th><th>النتيجة</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$sessions): ?>
<tr><td colspan="6" class="text-center text-muted py-4">لا توجد جلسات مسجّلة بعد</td></tr>
<?php else: foreach ($sessions as $s): ?>
<tr>
  <td><span class="font-monospace"><?= e($s['case_number']) ?></span><br><small class="text-muted"><?= e(mb_substr($s['case_title'],0,40)) ?></small></td>
  <td style="font-size:12px;white-space:nowrap"><?= dDate($s['session_date'], true) ?></td>
  <td><?= statusBadge($s['status']) ?></td>
  <td style="font-size:12px;max-width:200px"><?= e(mb_substr($s['description'] ?? '',0,80)) ?></td>
  <td style="font-size:12px;max-width:200px"><?= e(mb_substr($s['result'] ?? '',0,80)) ?></td>
  <td class="d-flex gap-1">
    <a href="cases.php?open_case=<?= $s['case_pk'] ?>" class="btn btn-sm btn-outline-info" title="فتح القضية"><i class="fas fa-folder-open"></i></a>
    <?php if (can('sessions','edit')): ?>
    <a href="cases.php?edit_session=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary" title="تعديل"><i class="fas fa-edit"></i></a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php if ($tab === 'contracts'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="fw-semibold">العقود</div>
  <?php if (can('contracts','add')): ?>
  <a class="btn btn-primary btn-sm" href="contracts.php?client_id=<?= $client_id ?>&open_new=contract&return_to=<?= urlencode($_returnHere.'&tab=contracts') ?>">
    <i class="fas fa-plus me-1"></i>عقد جديد</a>
  <?php endif; ?>
</div>
<div class="card mb-4"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>رقم العقد</th><th>العنوان</th><th>النوع</th><th>القيمة</th><th>الحالة</th><th>البداية</th><th>النهاية</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$contracts): ?>
<tr><td colspan="8" class="text-center text-muted py-4">لا توجد عقود لهذا العميل</td></tr>
<?php else: foreach ($contracts as $c): ?>
<tr>
  <td class="font-monospace"><?= e($c['contract_number']) ?></td>
  <td><?= e($c['title']) ?></td>
  <td><?= e($c['contract_type'] ?: '—') ?></td>
  <td><?= number_format((float)$c['value'],0) ?> ر.س</td>
  <td><?= statusBadge($c['status']) ?></td>
  <td style="font-size:12px"><?= $c['start_date'] ? dDate($c['start_date']) : '—' ?></td>
  <td style="font-size:12px"><?= $c['end_date'] ? dDate($c['end_date']) : '—' ?></td>
  <td>
    <?php if (can('contracts','edit')): ?>
    <a href="contracts.php?edit_c=<?= $c['id'] ?>&return_to=<?= urlencode($_returnHere.'&tab=contracts') ?>" class="btn btn-sm btn-outline-primary" title="تعديل"><i class="fas fa-edit"></i></a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="fw-semibold">الوكالات</div>
  <?php if (can('contracts','add')): ?>
  <a class="btn btn-primary btn-sm" href="contracts.php?client_id=<?= $client_id ?>&open_new=poa&return_to=<?= urlencode($_returnHere.'&tab=contracts') ?>">
    <i class="fas fa-plus me-1"></i>وكالة جديدة</a>
  <?php endif; ?>
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>رقم الوكالة</th><th>العنوان</th><th>الوكيل</th><th>الحالة</th><th>الإصدار</th><th>الانتهاء</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$poas): ?>
<tr><td colspan="7" class="text-center text-muted py-4">لا توجد وكالات لهذا العميل</td></tr>
<?php else: foreach ($poas as $p): ?>
<tr>
  <td class="font-monospace"><?= e($p['poa_number']) ?></td>
  <td><?= e($p['title']) ?></td>
  <td><?= e($p['granted_to'] ?: '—') ?></td>
  <td><?= statusBadge($p['status']) ?></td>
  <td style="font-size:12px"><?= $p['issue_date'] ? dDate($p['issue_date']) : '—' ?></td>
  <td style="font-size:12px"><?= $p['expiry_date'] ? dDate($p['expiry_date']) : '—' ?></td>
  <td>
    <?php if (can('contracts','edit')): ?>
    <a href="contracts.php?edit_p=<?= $p['id'] ?>&return_to=<?= urlencode($_returnHere.'&tab=contracts') ?>" class="btn btn-sm btn-outline-primary" title="تعديل"><i class="fas fa-edit"></i></a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php if ($tab === 'invoices'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="fw-semibold">فواتير العميل — الإجمالي <?= number_format($invoices_total,0) ?> ر.س</div>
  <?php if (can('invoices','add')): ?>
  <a class="btn btn-primary btn-sm" href="invoices.php?client_id=<?= $client_id ?>&return_to=<?= urlencode($_returnHere.'&tab=invoices') ?>">
    <i class="fas fa-plus me-1"></i>فاتورة جديدة</a>
  <?php endif; ?>
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>رقم الفاتورة</th><th>العنوان</th><th>النوع</th><th>الإجمالي</th><th>الحالة</th><th>تاريخ الإصدار</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$invoices): ?>
<tr><td colspan="7" class="text-center text-muted py-4">لا توجد فواتير لهذا العميل</td></tr>
<?php else: foreach ($invoices as $iv): ?>
<tr>
  <td class="font-monospace"><?= e($iv['invoice_number']) ?></td>
  <td><?= e($iv['title']) ?></td>
  <td><?= statusBadge($iv['direction'] ?: 'income') ?></td>
  <td class="fw-bold"><?= number_format((float)$iv['total'],2) ?> ر.س</td>
  <td><?= statusBadge($iv['status']) ?></td>
  <td style="font-size:12px"><?= $iv['issue_date'] ? dDate($iv['issue_date']) : '—' ?></td>
  <td class="d-flex gap-1">
    <a href="invoice_pdf.php?id=<?= $iv['id'] ?>&view=1" target="_blank" class="btn btn-sm btn-outline-secondary" title="PDF"><i class="fas fa-file-pdf"></i></a>
    <?php if (can('invoices','edit')): ?>
    <a href="invoices.php?edit=<?= $iv['id'] ?>&return_to=<?= urlencode($_returnHere.'&tab=invoices') ?>" class="btn btn-sm btn-outline-primary" title="تعديل"><i class="fas fa-edit"></i></a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php if ($tab === 'services'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="fw-semibold">طلبات الخدمات الرقمية</div>
  <?php if (can('services','add')): ?>
  <a class="btn btn-primary btn-sm" href="digital_services.php?client_id=<?= $client_id ?>&return_to=<?= urlencode($_returnHere.'&tab=services') ?>">
    <i class="fas fa-plus me-1"></i>طلب خدمة جديد</a>
  <?php endif; ?>
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>التاريخ</th><th>الخدمة</th><th>الإجمالي</th><th>الحالة</th><th>الفاتورة</th></tr></thead>
<tbody>
<?php if (!$services): ?>
<tr><td colspan="5" class="text-center text-muted py-4">لا توجد طلبات خدمات لهذا العميل</td></tr>
<?php else: foreach ($services as $sv): ?>
<tr>
  <td style="font-size:12px;white-space:nowrap"><?= e(dDate($sv['created_at'], true)) ?></td>
  <td><?= e($sv['service_name']) ?></td>
  <td class="fw-bold"><?= number_format((float)$sv['total'],2) ?> ر.س</td>
  <td>
    <?php if (can('services','edit')): ?>
    <form method="POST" action="digital_services.php" class="d-inline">
      <input type="hidden" name="form_type" value="req_status">
      <input type="hidden" name="id" value="<?= $sv['id'] ?>">
      <input type="hidden" name="return_to" value="<?= e($_returnHere.'&tab=services') ?>">
      <select name="status" class="form-select form-select-sm" style="min-width:120px" onchange="this.form.submit()">
        <option value="new" <?= $sv['status']==='new'?'selected':'' ?>>جديد</option>
        <option value="in_progress" <?= $sv['status']==='in_progress'?'selected':'' ?>>قيد التنفيذ</option>
        <option value="completed" <?= $sv['status']==='completed'?'selected':'' ?>>مكتمل</option>
        <option value="cancelled" <?= $sv['status']==='cancelled'?'selected':'' ?>>ملغى</option>
      </select>
    </form>
    <?php else: echo statusBadge($sv['status']); endif; ?>
  </td>
  <td>
    <?php if ($sv['invoice_id']): ?>
    <a href="invoice_pdf.php?id=<?= (int)$sv['invoice_id'] ?>&view=1" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file-pdf"></i></a>
    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php if ($tab === 'interactions'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="fw-semibold">سجل التواصل — مكالمات واجتماعات وملاحظات</div>
  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#intModal" onclick="intNew()">
    <i class="fas fa-plus me-1"></i>تواصل جديد</button>
</div>
<?php if (!$interactions): ?>
<div class="card"><div class="card-body">
  <div class="mk-empty">
    <div class="mk-empty-icon"><i class="fas fa-comments"></i></div>
    <div class="mk-empty-title">لا يوجد تواصل مسجّل بعد</div>
    <div class="mk-empty-desc">سجّل أول مكالمة أو اجتماع مع هذا العميل</div>
  </div>
</div></div>
<?php else: ?>
<div class="row g-2">
<?php foreach ($interactions as $it): $tm = $_typeMap[$it['type']] ?? ['—','fa-circle','#64748b']; ?>
  <div class="col-12">
    <div class="card">
      <div class="card-body d-flex align-items-start gap-3 py-2">
        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:36px;height:36px;background:<?= $tm[2] ?>22;color:<?= $tm[2] ?>">
          <i class="fas <?= $tm[1] ?>"></i>
        </div>
        <div class="flex-grow-1">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
            <div class="fw-semibold"><?= $tm[0] ?> <span class="text-muted fw-normal" style="font-size:12px">— <?= dDate($it['interaction_date'], false, false) ?></span></div>
            <div class="d-flex gap-1">
              <button type="button" class="btn btn-sm btn-outline-primary" onclick='intEdit(<?= json_encode($it, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
              <a href="client_file.php?id=<?= $client_id ?>&tab=interactions&del_int=<?= $it['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا السجل؟')"><i class="fas fa-trash"></i></a>
            </div>
          </div>
          <?php if ($it['summary']): ?><div style="font-size:13px;color:#374151;white-space:pre-wrap;margin-top:4px"><?= e($it['summary']) ?></div><?php endif; ?>
          <?php if ($it['created_by_name']): ?><div class="text-muted mt-1" style="font-size:11px">بواسطة: <?= e($it['created_by_name']) ?></div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Modal: تواصل جديد/تعديل -->
<div class="modal fade" id="intModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="intModalTitle"><i class="fas fa-comments me-2"></i>تواصل جديد</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="form_type" value="interaction">
        <input type="hidden" name="int_id" id="int_id" value="">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">نوع التواصل</label>
            <select name="type" id="int_type" class="form-select">
              <?php foreach ($_typeMap as $k=>$v): ?>
              <option value="<?= $k ?>"><?= $v[0] ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">التاريخ</label>
            <input type="date" name="interaction_date" id="int_date" class="form-control" required>
          </div>
          <div class="mb-1">
            <label class="form-label fw-semibold">ملخّص / ملاحظات</label>
            <textarea name="summary" id="int_summary" class="form-control" rows="3" placeholder="ماذا تم الاتفاق عليه أو مناقشته..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function intNew() {
  document.getElementById('intModalTitle').innerHTML = '<i class="fas fa-comments me-2"></i>تواصل جديد';
  document.getElementById('int_id').value = '';
  document.getElementById('int_type').value = 'call';
  document.getElementById('int_date').value = new Date().toISOString().slice(0,10);
  document.getElementById('int_summary').value = '';
}
function intEdit(it) {
  document.getElementById('intModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>تعديل التواصل';
  document.getElementById('int_id').value = it.id;
  document.getElementById('int_type').value = it.type;
  document.getElementById('int_date').value = it.interaction_date;
  document.getElementById('int_summary').value = it.summary || '';
  new bootstrap.Modal(document.getElementById('intModal')).show();
}
<?php if ($edit_int): ?>
document.addEventListener('DOMContentLoaded', function () {
  intEdit(<?= json_encode($edit_int, JSON_HEX_APOS|JSON_HEX_QUOT) ?>);
  new bootstrap.Modal(document.getElementById('intModal')).show();
});
<?php endif; ?>
</script>

<?php include '../includes/office_footer.php'; ?>
