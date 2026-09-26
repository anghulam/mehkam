<?php
require_once '../includes/functions.php';
require_once '../includes/storage.php';
require_once '../includes/contract_templates.php';
require_once '../config/db.php';
requireOffice();
if (!can('contracts','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'العقود والوكالات';
$oid = (int)$_SESSION['office_id'];
$tab = $_GET['tab'] ?? 'contracts';
// فتح نافذة عقد/وكالة جديدة مباشرة مع تحديد العميل مسبقاً — من ملف العميل (client_file.php)
$_new_for_client = (int)($_GET['client_id'] ?? 0);
$_open_new = $_GET['open_new'] ?? '';

// حالة التوقيع الإلكتروني لكل عقد/وكالة — موديول إضافي، نعرضها فقط إذا مفعَّلة لهذا المكتب
$_canEsign = hasModule($conn, $oid, 'esignature') && can('esignature','view');
$_esignMap = ['contract'=>[], 'poa'=>[]];
if ($_canEsign) {
    $_esr = $conn->query("SELECT doc_type, doc_id, status, id FROM esign_requests
        WHERE office_id=$oid AND doc_type IN ('contract','poa') AND doc_id IS NOT NULL
        ORDER BY id DESC");
    if ($_esr) while ($_e = $_esr->fetch_assoc()) {
        // أحدث طلب توقيع لكل مستند فقط (النتائج مرتّبة تنازلياً، أول ظهور = الأحدث)
        if (!isset($_esignMap[$_e['doc_type']][$_e['doc_id']])) $_esignMap[$_e['doc_type']][$_e['doc_id']] = $_e;
    }
}
$_esignStatusMap = ['pending'=>['بانتظار التوقيع','warning'],'signed'=>['تم التوقيع','success'],'declined'=>['مرفوض','danger']];

// التحقق من ميزة العقود
if (!hasFeature($conn, $oid, 'has_contracts')) {
    header("Location: profile.php?tab=upgrade&feature=contracts"); exit;
}

// إضافة الوقت لتواريخ العقود والوكالات (كانت تواريخ بلا وقت) — توسيع آمن، لا يفقد القيم القديمة
foreach ([
    "ALTER TABLE contracts MODIFY COLUMN start_date DATETIME DEFAULT NULL",
    "ALTER TABLE contracts MODIFY COLUMN end_date DATETIME DEFAULT NULL",
    "ALTER TABLE poa MODIFY COLUMN issue_date DATETIME DEFAULT NULL",
    "ALTER TABLE poa MODIFY COLUMN expiry_date DATETIME DEFAULT NULL",
] as $_d) { try { $conn->query($_d); } catch (\Throwable $e) {} }

// ترقية جدول العقود — بنود / تمهيد / قوالب / ربط
foreach ([
    "ALTER TABLE contracts ADD COLUMN preamble TEXT DEFAULT NULL",
    "ALTER TABLE contracts ADD COLUMN clauses MEDIUMTEXT DEFAULT NULL",
    "ALTER TABLE contracts ADD COLUMN template_key VARCHAR(60) DEFAULT NULL",
    "ALTER TABLE contracts ADD COLUMN linked_ids VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE contracts ADD COLUMN party_first TEXT DEFAULT NULL",
    "ALTER TABLE contracts ADD COLUMN party_second TEXT DEFAULT NULL",
    "CREATE TABLE IF NOT EXISTS office_contract_templates (
        id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL,
        name VARCHAR(200) NOT NULL, contract_type VARCHAR(100) DEFAULT NULL,
        preamble TEXT DEFAULT NULL, clauses MEDIUMTEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_office (office_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
] as $_c) { try { $conn->query($_c); } catch (\Throwable $e) {} }

$_can_tpl = currentRole() === 'office_owner' || can('contracts', 'edit');

// حذف قالب مكتب
if (isset($_GET['del_tpl']) && $_can_tpl) {
    $conn->query("DELETE FROM office_contract_templates WHERE id=".(int)$_GET['del_tpl']." AND office_id=$oid");
    header("Location: contracts.php?tab=templates&msg=deleted"); exit;
}

// حفظ قالب مكتب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'office_template' && $_can_tpl) {
    $tn = $conn->real_escape_string(trim($_POST['name'] ?? ''));
    $tt = $conn->real_escape_string(trim($_POST['contract_type'] ?? ''));
    $tp = $conn->real_escape_string(trim($_POST['preamble'] ?? ''));
    $tc = [];
    if (!empty($_POST['clause_title']) && is_array($_POST['clause_title'])) {
        foreach ($_POST['clause_title'] as $i => $ct) {
            $cb = $_POST['clause_body'][$i] ?? '';
            if (trim($ct) === '' && trim($cb) === '') continue;
            $tc[] = ['title' => trim($ct), 'body' => trim($cb)];
        }
    }
    $tcj = $conn->real_escape_string(json_encode($tc, JSON_UNESCAPED_UNICODE));
    if ($tn !== '') {
        if (!empty($_POST['id'])) {
            $tid = (int)$_POST['id'];
            $conn->query("UPDATE office_contract_templates SET name='$tn',contract_type='$tt',preamble='$tp',clauses='$tcj' WHERE id=$tid AND office_id=$oid");
        } else {
            $conn->query("INSERT INTO office_contract_templates (office_id,name,contract_type,preamble,clauses) VALUES ($oid,'$tn','$tt','$tp','$tcj')");
        }
    }
    header("Location: contracts.php?tab=templates&msg=saved"); exit;
}

// قوالب المكتب (للدمج في نافذة العقد + تبويب الإدارة)
$officeTpls = [];
$otq = $conn->query("SELECT * FROM office_contract_templates WHERE office_id=$oid ORDER BY name");
if ($otq) while ($r = $otq->fetch_assoc()) {
    $officeTpls[] = [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'type' => $r['contract_type'] ?? '',
        'preamble' => $r['preamble'] ?? '',
        'clauses' => $r['clauses'] ? (json_decode($r['clauses'], true) ?: []) : [],
    ];
}
$edit_tpl = null;
if (isset($_GET['edit_tpl']) && $_can_tpl) {
    $edit_tpl = $conn->query("SELECT * FROM office_contract_templates WHERE id=".(int)$_GET['edit_tpl']." AND office_id=$oid")->fetch_assoc();
}

// حذف عقد
if (isset($_GET['delete_c'])) {
    $conn->query("DELETE FROM contracts WHERE id=".(int)$_GET['delete_c']." AND office_id=$oid");
    header("Location: contracts.php?tab=contracts&msg=deleted"); exit;
}
// حذف وكالة
if (isset($_GET['delete_p'])) {
    $conn->query("DELETE FROM poa WHERE id=".(int)$_GET['delete_p']." AND office_id=$oid");
    header("Location: contracts.php?tab=poa&msg=deleted"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['form_type'] === 'contract') {
        $cnum   = $conn->real_escape_string($_POST['contract_number']);
        $title  = $conn->real_escape_string($_POST['title']);
        $ctype  = $conn->real_escape_string($_POST['contract_type']);
        $cid    = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : 'NULL';
        $sdate_raw = str_replace('T', ' ', trim($_POST['start_date'] ?? ''));
        $edate_raw = str_replace('T', ' ', trim($_POST['end_date']   ?? ''));
        $sdate_sql = $sdate_raw !== '' ? "'".$conn->real_escape_string($sdate_raw)."'" : 'NULL';
        $edate_sql = $edate_raw !== '' ? "'".$conn->real_escape_string($edate_raw)."'" : 'NULL';
        $val    = (float)$_POST['value'];
        $status = $conn->real_escape_string($_POST['status']);

        // التمهيد + البنود + الأطراف + الربط
        $preamble   = $conn->real_escape_string(trim($_POST['preamble'] ?? ''));
        $tpl_key    = $conn->real_escape_string(trim($_POST['template_key'] ?? ''));
        $p_first    = $conn->real_escape_string(trim($_POST['party_first'] ?? ''));
        $p_second   = $conn->real_escape_string(trim($_POST['party_second'] ?? ''));
        $clauses = [];
        if (!empty($_POST['clause_title']) && is_array($_POST['clause_title'])) {
            foreach ($_POST['clause_title'] as $i => $ct) {
                $cb = $_POST['clause_body'][$i] ?? '';
                if (trim($ct) === '' && trim($cb) === '') continue;
                $clauses[] = ['title' => trim($ct), 'body' => trim($cb)];
            }
        }
        $clauses_json = $conn->real_escape_string(json_encode($clauses, JSON_UNESCAPED_UNICODE));
        $linked = [];
        if (!empty($_POST['linked_ids']) && is_array($_POST['linked_ids'])) {
            foreach ($_POST['linked_ids'] as $lid) { $lid = (int)$lid; if ($lid) $linked[] = $lid; }
        }
        $linked_sql = $conn->real_escape_string(implode(',', $linked));

        // رفع ملف(ات) العقد — أول ملف يُحفظ في العمود الرئيسي، والباقي كمرفقات تابعة للعقد
        $contractFiles = normalizeFilesArray($_FILES['contract_file'] ?? [], $_POST['contract_file_label'] ?? null);
        $fn=''; $fp=''; $fd='local';
        if ($contractFiles) {
            $up = storage_upload($contractFiles[0], 'contracts', $oid, $_SESSION['office_name'] ?? '');
            if ($up['success']) { $fn=$conn->real_escape_string($up['name']); $fp=$conn->real_escape_string($up['path']); $fd=$conn->real_escape_string($up['driver']); }
        }
        $extra = "preamble='$preamble',clauses='$clauses_json',template_key='$tpl_key',linked_ids='$linked_sql',party_first='$p_first',party_second='$p_second'";
        if (!empty($_POST['id'])) {
            $id = (int)$_POST['id'];
            $fsql = $fp ? ",file_name='$fn',file_path='$fp',file_driver='$fd'" : '';
            $conn->query("UPDATE contracts SET contract_number='$cnum',title='$title',contract_type='$ctype',client_id=$cid,start_date=$sdate_sql,end_date=$edate_sql,value=$val,status='$status',$extra$fsql WHERE id=$id AND office_id=$oid");
            if (function_exists('logAction')) logAction($conn, 'update', 'contract', $id, 'تعديل عقد ' . $cnum);
        } else {
            $conn->query("INSERT INTO contracts (office_id,contract_number,title,contract_type,client_id,start_date,end_date,value,status,file_name,file_path,file_driver,preamble,clauses,template_key,linked_ids,party_first,party_second) VALUES ($oid,'$cnum','$title','$ctype',$cid,$sdate_sql,$edate_sql,$val,'$status','$fn','$fp','$fd','$preamble','$clauses_json','$tpl_key','$linked_sql','$p_first','$p_second')");
            $id = (int)$conn->insert_id;
            if (function_exists('logAction')) logAction($conn, 'create', 'contract', $id, 'إنشاء عقد ' . $cnum);
        }
        for ($i = 1; $i < count($contractFiles); $i++) {
            $up2 = storage_upload($contractFiles[$i], 'contracts', $oid, $_SESSION['office_name'] ?? '');
            if ($up2['success']) {
                $conn->query("INSERT INTO file_attachments
                    (office_id,entity_type,entity_id,original_name,stored_name,file_path,file_size,driver,uploaded_by)
                    VALUES ($oid,'contract',$id,'".$conn->real_escape_string($up2['name'])."','".$conn->real_escape_string(basename($up2['path']))."','".$conn->real_escape_string($up2['path'])."',".(int)($contractFiles[$i]['size'] ?? 0).",'".$conn->real_escape_string($up2['driver'])."',{$_SESSION['user_id']})");
            }
        }
        $_retTo = trim($_POST['return_to'] ?? '');
        if (!preg_match('/^[a-z_]+\.php(\?[a-z0-9_=&%.\-]*)?$/i', $_retTo)) $_retTo = '';
        header("Location: " . ($_retTo !== '' ? $_retTo . (str_contains($_retTo,'?')?'&':'?') . 'msg=saved' : 'contracts.php?tab=contracts&msg=saved')); exit;
    }
    if ($_POST['form_type'] === 'poa') {
        $pnum  = $conn->real_escape_string($_POST['poa_number']);
        $title = $conn->real_escape_string($_POST['title']);
        $cid   = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : 'NULL';
        $gto   = $conn->real_escape_string($_POST['granted_to']);
        $idate_raw = str_replace('T', ' ', trim($_POST['issue_date']  ?? ''));
        $edate_raw = str_replace('T', ' ', trim($_POST['expiry_date'] ?? ''));
        $idate_sql = $idate_raw !== '' ? "'".$conn->real_escape_string($idate_raw)."'" : 'NULL';
        $edate_sql = $edate_raw !== '' ? "'".$conn->real_escape_string($edate_raw)."'" : 'NULL';
        $status= $conn->real_escape_string($_POST['status']);
        // رفع ملف(ات) الوكالة — أول ملف يُحفظ في العمود الرئيسي، والباقي كمرفقات تابعة
        $poaFiles = normalizeFilesArray($_FILES['poa_file'] ?? [], $_POST['poa_file_label'] ?? null);
        $fn=''; $fp=''; $fd='local';
        if ($poaFiles) {
            $up = storage_upload($poaFiles[0], 'poa', $oid, $_SESSION['office_name'] ?? '');
            if ($up['success']) { $fn=$conn->real_escape_string($up['name']); $fp=$conn->real_escape_string($up['path']); $fd=$conn->real_escape_string($up['driver']); }
        }
        if (!empty($_POST['id'])) {
            $id = (int)$_POST['id'];
            $fsql = $fp ? ",file_name='$fn',file_path='$fp',file_driver='$fd'" : '';
            $conn->query("UPDATE poa SET poa_number='$pnum',title='$title',client_id=$cid,granted_to='$gto',issue_date=$idate_sql,expiry_date=$edate_sql,status='$status'$fsql WHERE id=$id AND office_id=$oid");
        } else {
            $conn->query("INSERT INTO poa (office_id,poa_number,title,client_id,granted_to,issue_date,expiry_date,status,file_name,file_path,file_driver) VALUES ($oid,'$pnum','$title',$cid,'$gto',$idate_sql,$edate_sql,'$status','$fn','$fp','$fd')");
            $id = (int)$conn->insert_id;
        }
        for ($i = 1; $i < count($poaFiles); $i++) {
            $up2 = storage_upload($poaFiles[$i], 'poa', $oid, $_SESSION['office_name'] ?? '');
            if ($up2['success']) {
                $conn->query("INSERT INTO file_attachments
                    (office_id,entity_type,entity_id,original_name,stored_name,file_path,file_size,driver,uploaded_by)
                    VALUES ($oid,'poa',$id,'".$conn->real_escape_string($up2['name'])."','".$conn->real_escape_string(basename($up2['path']))."','".$conn->real_escape_string($up2['path'])."',".(int)($poaFiles[$i]['size'] ?? 0).",'".$conn->real_escape_string($up2['driver'])."',{$_SESSION['user_id']})");
            }
        }
        $_retTo = trim($_POST['return_to'] ?? '');
        if (!preg_match('/^[a-z_]+\.php(\?[a-z0-9_=&%.\-]*)?$/i', $_retTo)) $_retTo = '';
        header("Location: " . ($_retTo !== '' ? $_retTo . (str_contains($_retTo,'?')?'&':'?') . 'msg=saved' : 'contracts.php?tab=poa&msg=saved')); exit;
    }
}

$clients = $conn->query("SELECT id,full_name FROM clients WHERE office_id=$oid ORDER BY full_name");
$clients_arr = [];
while ($cl = $clients->fetch_assoc()) $clients_arr[$cl['id']] = $cl['full_name'];

$contracts = $conn->query("SELECT ct.*, cl.full_name client_name FROM contracts ct LEFT JOIN clients cl ON ct.client_id=cl.id WHERE ct.office_id=$oid ORDER BY ct.id DESC");
$poas      = $conn->query("SELECT p.*, cl.full_name client_name FROM poa p LEFT JOIN clients cl ON p.client_id=cl.id WHERE p.office_id=$oid ORDER BY p.id DESC");

$edit_c = null; $edit_p = null;
if (isset($_GET['edit_c'])) $edit_c = $conn->query("SELECT * FROM contracts WHERE id=".(int)$_GET['edit_c']." AND office_id=$oid")->fetch_assoc();
if (isset($_GET['edit_p'])) $edit_p = $conn->query("SELECT * FROM poa WHERE id=".(int)$_GET['edit_p']." AND office_id=$oid")->fetch_assoc();

// بنود العقد الجاري تعديله
$edit_clauses = [];
if ($edit_c && !empty($edit_c['clauses'])) {
    $edit_clauses = json_decode($edit_c['clauses'], true) ?: [];
}
$edit_linked = ($edit_c && !empty($edit_c['linked_ids'])) ? array_filter(array_map('intval', explode(',', $edit_c['linked_ids']))) : [];

// قائمة العقود السابقة (للربط)
$prev_contracts = [];
$pcq = $conn->query("SELECT id, contract_number, title FROM contracts WHERE office_id=$oid" . ($edit_c ? " AND id<>".(int)$edit_c['id'] : "") . " ORDER BY id DESC LIMIT 200");
if ($pcq) while ($r = $pcq->fetch_assoc()) $prev_contracts[] = $r;

$TPL = contract_templates();
$_office_name_tpl = $_SESSION['office_name'] ?? ($conn->query("SELECT name FROM offices WHERE id=$oid")->fetch_assoc()['name'] ?? 'المكتب');

include '../includes/office_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='contracts'?'active':'' ?>" href="contracts.php?tab=contracts"><i class="fas fa-file-signature me-1"></i>العقود</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='poa'?'active':'' ?>" href="contracts.php?tab=poa"><i class="fas fa-stamp me-1"></i>الوكالات</a></li>
  <?php if ($_can_tpl): ?>
  <li class="nav-item"><a class="nav-link <?= $tab==='templates'?'active':'' ?>" href="contracts.php?tab=templates"><i class="fas fa-layer-group me-1"></i>قوالب العقود</a></li>
  <?php endif; ?>
</ul>

<?php if ($tab === 'templates' && $_can_tpl):
  $et_clauses = ($edit_tpl && !empty($edit_tpl['clauses'])) ? (json_decode($edit_tpl['clauses'], true) ?: []) : [];
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="fas fa-<?= $edit_tpl?'pen':'plus' ?> me-2"></i><?= $edit_tpl ? 'تعديل قالب' : 'قالب عقد جديد' ?></div>
      <div class="card-body">
        <form method="POST" id="tplForm">
          <input type="hidden" name="form_type" value="office_template">
          <input type="hidden" name="id" value="<?= $edit_tpl['id'] ?? '' ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold">اسم القالب *</label>
            <input type="text" name="name" class="form-control" required value="<?= e($edit_tpl['name'] ?? '') ?>" placeholder="مثال: اتفاقية أتعاب — قضايا تجارية">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">نوع العقد</label>
            <input type="text" name="contract_type" class="form-control" value="<?= e($edit_tpl['contract_type'] ?? '') ?>" placeholder="اتفاقية أتعاب">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">التمهيد</label>
            <textarea name="preamble" id="tpl_preamble" class="form-control" rows="5" placeholder="يمكنك استخدام: {{office_name}} {{client_name}} {{today}} {{value}}"><?= e($edit_tpl['preamble'] ?? '') ?></textarea>
          </div>
          <div class="d-flex align-items-center justify-content-between mb-1">
            <label class="form-label fw-semibold mb-0">البنود</label>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="tplAddClause()"><i class="fas fa-plus me-1"></i>بند</button>
          </div>
          <div id="tpl_clauses">
            <?php foreach ($et_clauses as $i => $cl): ?>
            <div class="clause-row border rounded p-2 mb-2" style="background:#fafafa">
              <div class="d-flex gap-2 mb-1">
                <input type="text" name="clause_title[]" class="form-control form-control-sm fw-semibold" placeholder="عنوان البند" value="<?= e($cl['title'] ?? '') ?>">
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.clause-row').remove()">✕</button>
              </div>
              <textarea name="clause_body[]" class="form-control form-control-sm" rows="2" placeholder="نص البند"><?= e($cl['body'] ?? '') ?></textarea>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="mt-2 d-flex gap-2">
            <button class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ القالب</button>
            <?php if ($edit_tpl): ?><a href="contracts.php?tab=templates" class="btn btn-outline-secondary">إلغاء</a><?php endif; ?>
          </div>
          <div class="form-text mt-2">القوالب تظهر في نافذة «إضافة عقد» ← اختر القالب ← تعبئة تلقائية للتمهيد والبنود.</div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="fas fa-list me-2"></i>قوالب مكتبك (<?= count($officeTpls) ?>)</div>
      <div class="card-body p-0">
        <?php if (!$officeTpls): ?>
        <div class="p-4 text-center text-muted" style="font-size:13px">لا قوالب بعد. أنشئ أول قالب من النموذج المجاور — أو استخدم القوالب الجاهزة الموجودة أصلاً في نافذة العقد.</div>
        <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach ($officeTpls as $t): ?>
          <div class="list-group-item d-flex align-items-center gap-2">
            <div style="flex:1;min-width:0">
              <div class="fw-semibold"><?= e($t['name']) ?></div>
              <div class="text-muted" style="font-size:11.5px"><?= e($t['type'] ?: 'عقد') ?> · <?= count($t['clauses']) ?> بند</div>
            </div>
            <a href="contracts.php?tab=templates&edit_tpl=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
            <a href="contracts.php?tab=templates&del_tpl=<?= $t['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف القالب؟')"><i class="fas fa-trash"></i></a>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
function tplAddClause(t, b){
  var d = document.createElement('div');
  d.className = 'clause-row border rounded p-2 mb-2'; d.style.background = '#fafafa';
  d.innerHTML = '<div class="d-flex gap-2 mb-1"><input type="text" name="clause_title[]" class="form-control form-control-sm fw-semibold" placeholder="عنوان البند">'
    + '<button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest(\'.clause-row\').remove()">✕</button></div>'
    + '<textarea name="clause_body[]" class="form-control form-control-sm" rows="2" placeholder="نص البند"></textarea>';
  d.querySelector('input').value = t || ''; d.querySelector('textarea').value = b || '';
  document.getElementById('tpl_clauses').appendChild(d);
}
</script>
<?php include '../includes/office_footer.php'; exit; endif; ?>

<!-- العقود -->
<div class="<?= $tab!=='contracts'?'d-none':'' ?>">
  <div class="d-flex justify-content-end mb-3">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#contractModal">
      <i class="fas fa-plus me-1"></i>إضافة عقد
    </button>
  </div>
  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr>
            <th>رقم العقد</th><th>العنوان</th><th>النوع</th><th>العميل</th>
            <th>تاريخ البداية</th><th>تاريخ الانتهاء</th><th>القيمة</th><th>الحالة</th>
            <?php if ($_canEsign): ?><th>التوقيع الإلكتروني</th><?php endif; ?>
            <th>إجراءات</th>
          </tr></thead>
          <tbody>
          <?php while($c = $contracts->fetch_assoc()): ?>
          <tr>
            <td class="fw-semibold text-primary"><?= e($c['contract_number']) ?></td>
            <td><?= e($c['title']) ?></td>
            <td><span class="badge bg-secondary"><?= e($c['contract_type']) ?></span></td>
            <td><?= e($c['client_name']) ?></td>
            <td style="white-space:nowrap"><?= dDate($c['start_date'], true) ?></td>
            <td style="white-space:nowrap"><?= dDate($c['end_date'], true) ?></td>
            <td class="fw-semibold"><?= number_format($c['value']) ?> ر.س</td>
            <td><?= statusBadge($c['status']) ?></td>
            <?php if ($_canEsign): $_es = $_esignMap['contract'][$c['id']] ?? null; ?>
            <td>
              <?php if ($_es): $_esm = $_esignStatusMap[$_es['status']] ?? ['—','secondary']; ?>
                <span class="badge bg-<?= $_esm[1] ?> bg-opacity-10 text-<?= $_esm[1] ?>"><?= $_esm[0] ?></span>
                <a href="esignature.php" class="btn btn-sm btn-outline-secondary py-0 px-1" title="عرض في صفحة التوقيع الإلكتروني"><i class="fas fa-arrow-up-right-from-square" style="font-size:10px"></i></a>
              <?php elseif (can('esignature','add')): ?>
                <a href="esignature.php?new_doc_type=contract&new_doc_id=<?= $c['id'] ?>&new_title=<?= urlencode($c['contract_number'].' — '.$c['title']) ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-signature me-1"></i>طلب توقيع</a>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <?php endif; ?>
            <td>
              <?php if(!empty($c['file_path'])): ?>
              <?= storage_file_link($conn, 'contract', $c, 'view') ?>
              <?php endif; ?>
              <?php if(!empty($c['preamble']) || !empty($c['clauses'])): ?>
              <a href="contract_pdf.php?id=<?= $c['id'] ?>&view=1" target="_blank" class="btn btn-sm btn-outline-dark" title="معاينة PDF"><i class="fas fa-file-pdf"></i></a>
              <a href="contract_pdf.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="تحميل PDF"><i class="fas fa-download"></i></a>
              <?php endif; ?>
              <a href="contracts.php?tab=contracts&edit_c=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
              <a href="contracts.php?tab=contracts&delete_c=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف؟')"><i class="fas fa-trash"></i></a>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- الوكالات -->
<div class="<?= $tab!=='poa'?'d-none':'' ?>">
  <div class="d-flex justify-content-end mb-3">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#poaModal">
      <i class="fas fa-plus me-1"></i>إضافة وكالة
    </button>
  </div>
  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr>
            <th>رقم الوكالة</th><th>العنوان</th><th>الموكِّل</th><th>الوكيل</th>
            <th>تاريخ الإصدار</th><th>تاريخ الانتهاء</th><th>الحالة</th>
            <?php if ($_canEsign): ?><th>التوقيع الإلكتروني</th><?php endif; ?>
            <th>إجراءات</th>
          </tr></thead>
          <tbody>
          <?php while($p = $poas->fetch_assoc()): ?>
          <tr>
            <td class="fw-semibold text-primary"><?= e($p['poa_number']) ?></td>
            <td><?= e($p['title']) ?></td>
            <td><?= e($p['client_name']) ?></td>
            <td><?= e($p['granted_to']) ?></td>
            <td style="white-space:nowrap"><?= dDate($p['issue_date'], true) ?></td>
            <td style="white-space:nowrap"><?= dDate($p['expiry_date'], true) ?></td>
            <td><?= statusBadge($p['status']) ?></td>
            <?php if ($_canEsign): $_es = $_esignMap['poa'][$p['id']] ?? null; ?>
            <td>
              <?php if ($_es): $_esm = $_esignStatusMap[$_es['status']] ?? ['—','secondary']; ?>
                <span class="badge bg-<?= $_esm[1] ?> bg-opacity-10 text-<?= $_esm[1] ?>"><?= $_esm[0] ?></span>
                <a href="esignature.php" class="btn btn-sm btn-outline-secondary py-0 px-1" title="عرض في صفحة التوقيع الإلكتروني"><i class="fas fa-arrow-up-right-from-square" style="font-size:10px"></i></a>
              <?php elseif (can('esignature','add')): ?>
                <a href="esignature.php?new_doc_type=poa&new_doc_id=<?= $p['id'] ?>&new_title=<?= urlencode($p['poa_number'].' — '.$p['title']) ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-signature me-1"></i>طلب توقيع</a>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <?php endif; ?>
            <td>
              <?php if(!empty($p['file_path'])): ?>
              <?= storage_file_link($conn, 'poa', $p, 'view') ?>
              <?php endif; ?>
              <a href="contracts.php?tab=poa&edit_p=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
              <a href="contracts.php?tab=poa&delete_p=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف؟')"><i class="fas fa-trash"></i></a>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal العقد -->
<div class="modal fade" id="contractModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-file-signature me-2"></i><?= $edit_c ? 'تعديل العقد' : 'إضافة عقد جديد' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data" id="contractForm">
        <input type="hidden" name="form_type" value="contract">
        <input type="hidden" name="return_to" value="<?= e($_GET['return_to'] ?? '') ?>">
        <div class="modal-body">
          <input type="hidden" name="id" value="<?= $edit_c['id'] ?? '' ?>">
          <input type="hidden" name="template_key" id="c_tpl_key" value="<?= e($edit_c['template_key'] ?? '') ?>">

          <!-- شريط القوالب + الذكاء الاصطناعي -->
          <div class="p-3 rounded mb-3" style="background:#f0f9ff;border:1px solid #bae6fd">
            <div class="row g-2 align-items-end">
              <div class="col-md-5">
                <label class="form-label fw-semibold" style="font-size:12.5px"><i class="fas fa-layer-group me-1 text-info"></i>قالب جاهز</label>
                <select id="c_tpl_select" class="form-select form-select-sm">
                  <option value="">— بدون قالب —</option>
                  <?php if ($officeTpls): ?>
                  <optgroup label="قوالب مكتبك">
                    <?php foreach ($officeTpls as $t): ?>
                    <option value="off_<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                  <?php endif; ?>
                  <optgroup label="قوالب جاهزة">
                    <?php foreach ($TPL as $k => $t): ?>
                    <option value="<?= e($k) ?>"><?= e($t['name']) ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                </select>
              </div>
              <div class="col-md-3">
                <button type="button" class="btn btn-outline-info btn-sm w-100" onclick="applyTpl()"><i class="fas fa-wand-magic-sparkles me-1"></i>تطبيق القالب</button>
              </div>
              <div class="col-md-4">
                <button type="button" class="btn btn-dark btn-sm w-100" onclick="openAiBox()"><i class="fas fa-robot me-1"></i>صياغة / سحب البيانات بالـ AI</button>
              </div>
            </div>
            <div id="c_ai_box" class="mt-2" style="display:none">
              <textarea id="c_ai_input" class="form-control form-control-sm" rows="3" placeholder="اكتب وصفاً موجزاً للعقد المطلوب (نوعه، الأطراف، الأتعاب، أهم الشروط) — أو الصق نص عقد سابق لاستخراج بياناته."></textarea>
              <div class="d-flex gap-2 mt-2 flex-wrap">
                <button type="button" class="btn btn-primary btn-sm" onclick="aiDraft('draft')"><i class="fas fa-feather me-1"></i>صياغة عقد جديد</button>
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="aiDraft('extract')"><i class="fas fa-file-import me-1"></i>استخراج من نص سابق</button>
                <span id="c_ai_status" style="font-size:12px" class="align-self-center"></span>
              </div>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">رقم العقد *</label>
              <input type="text" name="contract_number" class="form-control" required value="<?= e($edit_c['contract_number'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">نوع العقد</label>
              <input type="text" name="contract_type" class="form-control" placeholder="اتفاقية أتعاب / استشارات ..." value="<?= e($edit_c['contract_type'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">عنوان العقد *</label>
              <input type="text" name="title" class="form-control" required value="<?= e($edit_c['title'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">العميل</label>
              <select name="client_id" class="form-select">
                <option value="0">-- اختر العميل --</option>
                <?php foreach($clients_arr as $cid=>$cn): ?>
                <option value="<?= $cid ?>" <?= ($edit_c ? ($edit_c['client_id']??0)==$cid : $_new_for_client===$cid) ? 'selected':'' ?>><?= e($cn) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">الحالة</label>
              <select name="status" class="form-select">
                <?php foreach(['draft'=>'مسودة','active'=>'نشط','expired'=>'منتهي','cancelled'=>'ملغي'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= ($edit_c['status']??'')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">تاريخ البداية</label>
              <input type="datetime-local" name="start_date" class="form-control"
                     value="<?= $edit_c && $edit_c['start_date'] ? date('Y-m-d\TH:i', strtotime($edit_c['start_date'])) : '' ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">تاريخ الانتهاء</label>
              <input type="datetime-local" name="end_date" class="form-control"
                     value="<?= $edit_c && $edit_c['end_date'] ? date('Y-m-d\TH:i', strtotime($edit_c['end_date'])) : '' ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">القيمة (ر.س)</label>
              <input type="number" name="value" class="form-control" step="0.01" value="<?= $edit_c['value'] ?? 0 ?>">
            </div>
            <!-- الأطراف -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">الطرف الأول</label>
              <input type="text" name="party_first" class="form-control" placeholder="مكتب <?= e($_office_name_tpl) ?> ..." value="<?= e($edit_c['party_first'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">الطرف الثاني</label>
              <input type="text" name="party_second" class="form-control" placeholder="اسم الموكّل / الشركة ..." value="<?= e($edit_c['party_second'] ?? '') ?>">
            </div>

            <!-- التمهيد -->
            <div class="col-12">
              <label class="form-label fw-semibold"><i class="fas fa-paragraph me-1 text-muted"></i>التمهيد</label>
              <textarea name="preamble" id="c_preamble" class="form-control" rows="5" placeholder="إنه في يوم ... تم الاتفاق بين ..."><?= e($edit_c['preamble'] ?? '') ?></textarea>
            </div>

            <!-- البنود -->
            <div class="col-12">
              <div class="d-flex align-items-center justify-content-between mb-1">
                <label class="form-label fw-semibold mb-0"><i class="fas fa-list-ol me-1 text-muted"></i>بنود العقد</label>
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addClause()"><i class="fas fa-plus me-1"></i>إضافة بند</button>
              </div>
              <div id="c_clauses">
                <?php
                $render_clauses = $edit_clauses ?: [];
                foreach ($render_clauses as $ci => $cl): ?>
                <div class="clause-row border rounded p-2 mb-2" style="background:#fafafa">
                  <div class="d-flex gap-2 mb-1">
                    <span class="badge bg-secondary align-self-center clause-num"><?= $ci+1 ?></span>
                    <input type="text" name="clause_title[]" class="form-control form-control-sm fw-semibold" placeholder="عنوان البند" value="<?= e($cl['title'] ?? '') ?>">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="moveClause(this,-1)" title="أعلى">↑</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="moveClause(this,1)" title="أسفل">↓</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.clause-row').remove();renumClauses()">✕</button>
                  </div>
                  <textarea name="clause_body[]" class="form-control form-control-sm" rows="2" placeholder="نص البند"><?= e($cl['body'] ?? '') ?></textarea>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- ربط عقود سابقة -->
            <?php if ($prev_contracts): ?>
            <div class="col-12">
              <label class="form-label fw-semibold"><i class="fas fa-link me-1 text-muted"></i>ربط بعقود سابقة</label>
              <select name="linked_ids[]" class="form-select" multiple size="4">
                <?php foreach ($prev_contracts as $pc): ?>
                <option value="<?= $pc['id'] ?>" <?= in_array((int)$pc['id'], $edit_linked, true) ? 'selected' : '' ?>>
                  <?= e($pc['contract_number']) ?> — <?= e(mb_substr($pc['title'], 0, 50)) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">اضغط Ctrl لاختيار أكثر من عقد (ملاحق، تجديدات، عقود ذات صلة).</div>
            </div>
            <?php endif; ?>

            <div class="col-12">
              <label class="form-label fw-semibold">رفع ملف العقد الموقّع <small class="text-muted">(PDF, Word — بحد 10MB)</small></label>
              <input type="file" name="contract_file" class="form-control mk-multi" accept=".pdf,.doc,.docx">
              <?php if($edit_c && !empty($edit_c['file_path'])): ?>
              <small class="text-muted mt-1 d-block"><?= storage_file_link($conn, 'contract', $edit_c) ?></small>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <?php if ($edit_c): ?>
          <a href="contract_pdf.php?id=<?= (int)$edit_c['id'] ?>&view=1" target="_blank" class="btn btn-outline-dark me-auto"><i class="fas fa-file-pdf me-1"></i>معاينة PDF</a>
          <?php endif; ?>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal الوكالة -->
<div class="modal fade" id="poaModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-stamp me-2"></i><?= $edit_p ? 'تعديل الوكالة' : 'إضافة وكالة جديدة' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="form_type" value="poa">
        <input type="hidden" name="return_to" value="<?= e($_GET['return_to'] ?? '') ?>">
        <div class="modal-body">
          <input type="hidden" name="id" value="<?= $edit_p['id'] ?? '' ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">رقم الوكالة *</label>
              <input type="text" name="poa_number" class="form-control" required value="<?= e($edit_p['poa_number'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">الموكِّل (العميل)</label>
              <select name="client_id" class="form-select">
                <option value="0">-- اختر العميل --</option>
                <?php foreach($clients_arr as $cid=>$cn): ?>
                <option value="<?= $cid ?>" <?= ($edit_p ? ($edit_p['client_id']??0)==$cid : $_new_for_client===$cid) ? 'selected':'' ?>><?= e($cn) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">عنوان الوكالة *</label>
              <input type="text" name="title" class="form-control" required value="<?= e($edit_p['title'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">اسم الوكيل</label>
              <input type="text" name="granted_to" class="form-control" value="<?= e($edit_p['granted_to'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">تاريخ الإصدار</label>
              <input type="datetime-local" name="issue_date" class="form-control"
                     value="<?= $edit_p && $edit_p['issue_date'] ? date('Y-m-d\TH:i', strtotime($edit_p['issue_date'])) : '' ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">تاريخ الانتهاء</label>
              <input type="datetime-local" name="expiry_date" class="form-control"
                     value="<?= $edit_p && $edit_p['expiry_date'] ? date('Y-m-d\TH:i', strtotime($edit_p['expiry_date'])) : '' ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">الحالة</label>
              <select name="status" class="form-select">
                <option value="active"  <?= ($edit_p['status']??'')==='active'?'selected':'' ?>>سارية</option>
                <option value="expired" <?= ($edit_p['status']??'')==='expired'?'selected':'' ?>>منتهية</option>
                <option value="revoked" <?= ($edit_p['status']??'')==='revoked'?'selected':'' ?>>ملغاة</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">رفع ملف الوكالة <small class="text-muted">(PDF, صورة — بحد 10MB)</small></label>
              <input type="file" name="poa_file" class="form-control mk-multi" accept=".pdf,.jpg,.jpeg,.png">
              <?php if($edit_p && !empty($edit_p['file_path'])): ?>
              <small class="text-muted mt-1 d-block"><?= storage_file_link($conn, 'poa', $edit_p) ?></small>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const CONTRACT_TPL = <?= json_encode($TPL, JSON_UNESCAPED_UNICODE) ?>;
<?php foreach ($officeTpls as $t): ?>
CONTRACT_TPL['off_<?= (int)$t['id'] ?>'] = <?= json_encode(['name'=>$t['name'],'type'=>$t['type'],'preamble'=>$t['preamble'],'clauses'=>$t['clauses']], JSON_UNESCAPED_UNICODE) ?>;
<?php endforeach; ?>
const TPL_VARS = {
  office_name: <?= json_encode($_office_name_tpl, JSON_UNESCAPED_UNICODE) ?>,
  today: <?= json_encode(function_exists('hijriDate') ? (dayName(date('Y-m-d')).' '.hijriDate(date('Y-m-d')).' ('.date('Y/m/d').'م)') : date('Y/m/d'), JSON_UNESCAPED_UNICODE) ?>
};

function fillVars(t){
  const cn = document.querySelector('#contractForm select[name=client_id]');
  const clientName = cn && cn.value !== '0' ? cn.options[cn.selectedIndex].text : '.........';
  const val = document.querySelector('#contractForm input[name=value]');
  return (t||'')
    .replaceAll('{{office_name}}', TPL_VARS.office_name)
    .replaceAll('{{client_name}}', clientName)
    .replaceAll('{{today}}', TPL_VARS.today)
    .replaceAll('{{value}}', (val && parseFloat(val.value)) ? Number(val.value).toLocaleString('ar-EG') : '.........');
}

function clauseRow(title, body, n){
  const d = document.createElement('div');
  d.className = 'clause-row border rounded p-2 mb-2';
  d.style.background = '#fafafa';
  d.innerHTML = `<div class="d-flex gap-2 mb-1">
    <span class="badge bg-secondary align-self-center clause-num">${n}</span>
    <input type="text" name="clause_title[]" class="form-control form-control-sm fw-semibold" placeholder="عنوان البند">
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="moveClause(this,-1)" title="أعلى">↑</button>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="moveClause(this,1)" title="أسفل">↓</button>
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.clause-row').remove();renumClauses()">✕</button>
  </div>
  <textarea name="clause_body[]" class="form-control form-control-sm" rows="2" placeholder="نص البند"></textarea>`;
  d.querySelector('input').value = title || '';
  d.querySelector('textarea').value = body || '';
  return d;
}
function renumClauses(){
  document.querySelectorAll('#c_clauses .clause-num').forEach((b,i)=>b.textContent=i+1);
}
function addClause(title, body){
  const box = document.getElementById('c_clauses');
  box.appendChild(clauseRow(title||'', body||'', box.children.length+1));
  renumClauses();
}
function moveClause(btn, dir){
  const row = btn.closest('.clause-row');
  if (dir<0 && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
  if (dir>0 && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
  renumClauses();
}
function applyTpl(){
  const key = document.getElementById('c_tpl_select').value;
  if (!key || !CONTRACT_TPL[key]) return;
  const t = CONTRACT_TPL[key];
  if (document.getElementById('c_clauses').children.length && !confirm('سيُستبدل التمهيد والبنود الحالية بالقالب. متابعة؟')) return;
  document.getElementById('c_tpl_key').value = key;
  const tf = document.querySelector('#contractForm input[name=contract_type]');
  if (tf && !tf.value) tf.value = t.type || '';
  document.getElementById('c_preamble').value = fillVars(t.preamble);
  document.getElementById('c_clauses').innerHTML = '';
  (t.clauses||[]).forEach(c => addClause(fillVars(c.title), fillVars(c.body)));
}
function openAiBox(){
  const b = document.getElementById('c_ai_box');
  b.style.display = b.style.display === 'none' ? 'block' : 'none';
}
function aiDraft(mode){
  const txt = document.getElementById('c_ai_input').value.trim();
  const st  = document.getElementById('c_ai_status');
  if (!txt){ st.innerHTML = '<span class="text-danger">اكتب وصفاً أو الصق نصاً أولاً</span>'; return; }
  st.innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>جارٍ التوليد…</span>';
  const sys = 'أنت محامٍ سعودي خبير في صياغة العقود وفق أنظمة المملكة. أعِد فقط JSON صالحاً بالشكل: '
    + '{"contract_type":"","title":"","preamble":"","clauses":[{"title":"","body":""}],"value":0} دون أي نص خارج JSON.';
  const usr = (mode === 'extract')
    ? ('استخرج بيانات هذا العقد إلى JSON:\n\n' + txt)
    : ('اصغ عقداً كاملاً بالعربية الفصحى بناءً على الوصف التالي، مع تمهيد وبنود مرقّمة ومفصّلة:\n\n' + txt);
  fetch('ai_proxy.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ system: sys, messages:[{role:'user', content: usr}] })
  })
  .then(r=>r.json())
  .then(d=>{
    if (d.error){ st.innerHTML = '<span class="text-danger">'+d.error+'</span>'; return; }
    let raw = (d.content||'').trim().replace(/^```json/i,'').replace(/```$/,'').trim();
    let obj;
    try { obj = JSON.parse(raw); }
    catch(e){
      const m = raw.match(/\{[\s\S]*\}/);
      try { obj = JSON.parse(m ? m[0] : ''); } catch(e2){ st.innerHTML = '<span class="text-danger">تعذّر قراءة رد الذكاء الاصطناعي</span>'; return; }
    }
    if (obj.contract_type){ const f=document.querySelector('#contractForm input[name=contract_type]'); if(f) f.value = obj.contract_type; }
    if (obj.title){ const f=document.querySelector('#contractForm input[name=title]'); if(f && !f.value) f.value = obj.title; }
    if (obj.value){ const f=document.querySelector('#contractForm input[name=value]'); if(f && !parseFloat(f.value)) f.value = obj.value; }
    if (obj.preamble) document.getElementById('c_preamble').value = obj.preamble;
    if (Array.isArray(obj.clauses) && obj.clauses.length){
      document.getElementById('c_clauses').innerHTML = '';
      obj.clauses.forEach(c => addClause(c.title||'', c.body||''));
    }
    st.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>تم — راجع الصياغة وعدّل حسب الحاجة</span>';
  })
  .catch(()=>{ st.innerHTML = '<span class="text-danger">خطأ في الاتصال بخدمة الذكاء الاصطناعي</span>'; });
}
</script>
<?php if ($edit_c): ?>
<script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('contractModal')).show();});</script>
<?php elseif ($edit_p): ?>
<script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('poaModal')).show();});</script>
<?php elseif ($_new_for_client && $_open_new === 'contract'): ?>
<script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('contractModal')).show();});</script>
<?php elseif ($_new_for_client && $_open_new === 'poa'): ?>
<script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('poaModal')).show();});</script>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
