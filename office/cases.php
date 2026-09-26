<?php
require_once '../includes/functions.php';
require_once '../includes/storage.php';
require_once '../includes/telegram.php';
require_once '../config/db.php';
requireOffice();
if (!can('cases','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'القضايا والجلسات';
$oid = (int)$_SESSION['office_id'];
$_can_add = can('cases','add'); $_can_edit = can('cases','edit'); $_can_del = can('cases','delete');
// فتح نافذة «قضية جديدة» مباشرة مع تحديد العميل مسبقاً — يُستخدم من ملف العميل (client_file.php)
$_new_for_client = (int)($_GET['client_id'] ?? 0);
// عرض الأتعاب/المبالغ المحصَّلة يتبع صلاحية «الشؤون المالية» — تُخفى عن مدخل بيانات لا يملكها
$_permFinance = can('finance','view');

try { $conn->query("ALTER TABLE invoices ADD COLUMN direction ENUM('income','expense') DEFAULT 'income'"); } catch (\Exception $e) {}
// إضافة الوقت لموعد الجلسة القادمة (كان تاريخاً بلا وقت) — توسيع آمن، لا يفقد القيم القديمة
try { $conn->query("ALTER TABLE cases MODIFY COLUMN next_session DATETIME DEFAULT NULL"); } catch (\Exception $e) {}
// تقارير العمل الشخصية لكل موظّف مُسند على القضية
try { $conn->query("CREATE TABLE IF NOT EXISTS case_work_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    office_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (\Exception $e) {}

// أرشفة القضية المنتهية: تبقى بكامل بياناتها وملفاتها لكنها تخرج من القوائم النشطة
foreach ([
    "ALTER TABLE cases ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE cases ADD COLUMN archived_at DATETIME DEFAULT NULL",
    "ALTER TABLE cases ADD COLUMN archived_by INT DEFAULT NULL",
    "ALTER TABLE cases ADD COLUMN archive_note VARCHAR(500) DEFAULT NULL",
] as $_ddl) { try { $conn->query($_ddl); } catch (\Exception $e) {} }

/* ── أرشفة / استعادة قضية ── */
if (isset($_GET['archive']) || isset($_GET['unarchive'])) {
    requirePerm('cases','edit','cases.php?msg=denied');
    $arch = isset($_GET['archive']);
    $acid = (int)($arch ? $_GET['archive'] : $_GET['unarchive']);
    $arow = $conn->query("SELECT id,status,case_number FROM cases WHERE id=$acid AND office_id=$oid")->fetch_assoc();
    if (!$arow || !canSeeCase($conn, $acid)) { header("Location: cases.php?msg=denied"); exit; }
    if ($arch) {
        // الأرشفة للقضايا المنتهية فقط (مغلقة/مكسوبة/خاسرة/متسوية)
        if (!in_array($arow['status'], ['closed','won','lost','settled'], true)) {
            header("Location: cases.php?msg=archive_active"); exit;
        }
        $uid = (int)$_SESSION['user_id'];
        $conn->query("UPDATE cases SET is_archived=1, archived_at=NOW(), archived_by=$uid WHERE id=$acid AND office_id=$oid");
        logAction($conn, 'archive', 'case', $acid, 'أرشفة قضية ' . $arow['case_number']);
        header("Location: cases.php?msg=case_archived"); exit;
    }
    $conn->query("UPDATE cases SET is_archived=0, archived_at=NULL, archived_by=NULL WHERE id=$acid AND office_id=$oid");
    logAction($conn, 'unarchive', 'case', $acid, 'استعادة قضية من الأرشيف ' . $arow['case_number']);
    header("Location: cases.php?msg=case_restored"); exit;
}

/* ── معالجة الحذف ── */
if (isset($_GET['delete'])) {
    requirePerm('cases','delete','cases.php?msg=denied');
    $conn->query("DELETE FROM cases WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    logAction($conn, 'delete', 'case', (int)$_GET['delete'], 'حذف قضية');
    header("Location: cases.php?msg=deleted"); exit;
}
if (isset($_GET['del_session'])) {
    requirePerm('sessions','delete','cases.php?msg=denied');
    $sid = (int)$_GET['del_session'];
    $sCase = (int)dbVal($conn, "SELECT case_id FROM sessions WHERE id=$sid AND office_id=$oid");
    $conn->query("DELETE FROM sessions WHERE id=$sid AND office_id=$oid");
    header("Location: cases.php" . ($sCase ? "?open_case=$sCase&msg=deleted" : "?msg=deleted")); exit;
}
if (isset($_GET['del_attach'])) {
    $att = $conn->query("SELECT * FROM file_attachments WHERE id=".(int)$_GET['del_attach']." AND office_id=$oid")->fetch_assoc();
    $backCase = 0;
    if ($att) {
        if ($att['entity_type'] === 'case') $backCase = (int)$att['entity_id'];
        storage_delete($att['file_path'] ?? '', $att['driver'] ?? 'local');
        $conn->query("DELETE FROM file_attachments WHERE id=".(int)$_GET['del_attach']." AND office_id=$oid");
    }
    header("Location: cases.php" . ($backCase ? "?open_case=$backCase&open_att=1&msg=deleted" : "?msg=deleted")); exit;
}
// أرشفة مرفق قضية بضغطة واحدة — يُنشئ سجلاً في الأرشيف يشير لنفس الملف
if (isset($_GET['archive_attach']) && hasFeature($conn, $oid, 'has_archive')) {
    $aid = (int)$_GET['archive_attach'];
    $att = $conn->query("SELECT fa.*, ca.case_number, ca.title case_title
        FROM file_attachments fa
        LEFT JOIN cases ca ON fa.entity_type='case' AND fa.entity_id=ca.id
        WHERE fa.id=$aid AND fa.office_id=$oid")->fetch_assoc();
    $backCase = ($att && $att['entity_type'] === 'case') ? (int)$att['entity_id'] : 0;
    if ($att && !empty($att['file_path'])) {
        $exists = $conn->query("SELECT id FROM archive WHERE office_id=$oid AND file_path='".$conn->real_escape_string($att['file_path'])."' LIMIT 1");
        if (!$exists || !$exists->num_rows) {
            $a_title = $conn->real_escape_string($att['original_name'] ?: 'ملف قضية');
            $a_cat   = $conn->real_escape_string($att['case_number'] ? 'قضية ' . $att['case_number'] : 'قضايا');
            $a_fn    = $conn->real_escape_string($att['original_name'] ?? '');
            $a_size  = $conn->real_escape_string(function_exists('formatSize') ? formatSize((int)$att['file_size']) : (string)$att['file_size']);
            $a_fp    = $conn->real_escape_string($att['file_path']);
            $a_fd    = $conn->real_escape_string($att['driver'] ?? 'local');
            $a_desc  = $conn->real_escape_string('مؤرشف من القضية: ' . ($att['case_title'] ?? '') . ($att['case_number'] ? ' (' . $att['case_number'] . ')' : ''));
            $conn->query("INSERT INTO archive (office_id,title,category,file_name,file_size,file_path,file_driver,description)
                VALUES ($oid,'$a_title','$a_cat','$a_fn','$a_size','$a_fp','$a_fd','$a_desc')");
            if (function_exists('logAction')) logAction($conn, 'archive', 'attachment', $aid, 'أرشفة ملف: ' . $att['original_name']);
        }
    }
    header("Location: cases.php" . ($backCase ? "?open_case=$backCase&open_att=1&msg=archived" : "?msg=archived")); exit;
}

/* ── حفظ القضية ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'case') {
    $_isEdit = !empty($_POST['id']);
    requirePerm('cases', $_isEdit ? 'edit' : 'add', 'cases.php?msg=denied');
    $title  = $conn->real_escape_string($_POST['title'] ?? '');
    $cnum   = $conn->real_escape_string($_POST['case_number'] ?? '');
    $cid    = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : 'NULL';
    $ctype  = $conn->real_escape_string($_POST['case_type'] ?? '');
    $court  = $conn->real_escape_string($_POST['court_name'] ?? '');
    $status = $conn->real_escape_string($_POST['status'] ?? 'active');
    $prio   = $conn->real_escape_string($_POST['priority'] ?? 'medium');
    $ns_raw = str_replace('T', ' ', trim($_POST['next_session'] ?? ''));
    $ns     = $ns_raw !== '' ? "'".$conn->real_escape_string($ns_raw)."'" : 'NULL';
    $fees   = (float)($_POST['fees'] ?? 0);
    $desc   = $conn->real_escape_string($_POST['description'] ?? '');
    $notes  = $conn->real_escape_string($_POST['notes'] ?? '');

    $edit_id = (int)($_POST['id'] ?? 0);
    if ($edit_id) {
        $conn->query("UPDATE cases SET case_number='$cnum',client_id=$cid,title='$title',
            case_type='$ctype',court_name='$court',status='$status',priority='$prio',
            next_session=$ns,fees=$fees,description='$desc',notes='$notes'
            WHERE id=$edit_id AND office_id=$oid");
        logAction($conn, 'update', 'case', $edit_id, 'تعديل قضية ' . $_POST['case_number']);
    } else {
        // التحقق من الحد
        if (!canAddMore($conn, $oid, 'cases')) {
            header("Location: cases.php?msg=limit"); exit;
        }
        $conn->query("INSERT INTO cases (office_id,case_number,client_id,title,case_type,court_name,
            status,priority,next_session,fees,description,notes)
            VALUES ($oid,'$cnum',$cid,'$title','$ctype','$court','$status','$prio',$ns,$fees,'$desc','$notes')");
        $new_case_id = $conn->insert_id;
        logAction($conn, 'create', 'case', $new_case_id, 'قضية جديدة ' . $_POST['case_number'] . ' — ' . mb_substr($_POST['title'] ?? '', 0, 80));

        // رفع الملف(ات) إن وُجدت — عبر طبقة التخزين (تحترم إعدادات المكتب + التشفير)
        if (hasFeature($conn, $oid, 'has_archive')) {
            foreach (normalizeFilesArray($_FILES['case_file'] ?? [], $_POST['case_file_label'] ?? null) as $cf) {
                $up = storage_upload($cf, 'cases', $oid, $_SESSION['office_name'] ?? '');
                if ($up['success']) {
                    $size     = (int)($cf['size'] ?? 0);
                    $orig_esc = $conn->real_escape_string($up['name']);
                    $stor_esc = $conn->real_escape_string(basename($up['path']));
                    $path_esc = $conn->real_escape_string($up['path']);
                    $drv_esc  = $conn->real_escape_string($up['driver']);
                    $conn->query("INSERT INTO file_attachments
                        (office_id,entity_type,entity_id,original_name,stored_name,file_path,file_size,driver,uploaded_by)
                        VALUES ($oid,'case',$new_case_id,'$orig_esc','$stor_esc','$path_esc',$size,'$drv_esc',{$_SESSION['user_id']})");
                }
            }
        }
    }

    // مزامنة إسناد القضية للمحامين/الموظفين المختارين
    $_assignCaseId = $edit_id ?: $new_case_id;
    $_assignees = array_map('intval', (array)($_POST['assignees'] ?? []));
    // صاحب الصلاحية غير المالك يبقى مُسنداً تلقائياً لأي قضية يُنشئها بنفسه — حتى لا تختفي من قائمته فور الحفظ
    $_curRole = $_SESSION['role'] ?? '';
    if (!$edit_id && !in_array($_curRole, ['office_owner','admin'], true)) {
        $_assignees[] = (int)$_SESSION['user_id'];
    }
    $_assignees = array_unique(array_filter($_assignees));
    $conn->query("DELETE FROM case_assignments WHERE case_id=$_assignCaseId");
    foreach ($_assignees as $_auid) {
        $conn->query("INSERT IGNORE INTO case_assignments (case_id,user_id) VALUES ($_assignCaseId,$_auid)");
    }

    $_retTo = trim($_POST['return_to'] ?? '');
    // يُسمح فقط برابط نسبي داخل نفس مجلد office/ (لا مضيف خارجي) لمنع Open Redirect
    if (!preg_match('/^[a-z_]+\.php(\?[a-z0-9_=&%.\-]*)?$/i', $_retTo)) $_retTo = '';
    header("Location: " . ($_retTo !== '' ? $_retTo . (str_contains($_retTo,'?')?'&':'?') . 'msg=saved' : 'cases.php?msg=saved')); exit;
}

/* ── حفظ الجلسة ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'session') {
    requirePerm('sessions', !empty($_POST['session_id']) ? 'edit' : 'add', 'cases.php?msg=denied');
    $case_id = (int)$_POST['case_id'];
    $sdate_raw = str_replace('T', ' ', trim($_POST['session_date'] ?? ''));
    $sdate_sql = $sdate_raw !== '' ? "'".$conn->real_escape_string($sdate_raw)."'" : 'NULL';
    $desc    = $conn->real_escape_string($_POST['description'] ?? '');
    $result  = $conn->real_escape_string($_POST['result'] ?? '');
    $sstatus = $conn->real_escape_string($_POST['status'] ?? 'scheduled');
    $notes   = $conn->real_escape_string($_POST['notes'] ?? '');
    $sess_id = (int)($_POST['session_id'] ?? 0);

    if ($sess_id) {
        $conn->query("UPDATE sessions SET session_date=$sdate_sql,description='$desc',result='$result',status='$sstatus',notes='$notes'
            WHERE id=$sess_id AND office_id=$oid");
        logAction($conn, 'update', 'session', $sess_id, 'تحديث جلسة');
    } else {
        $conn->query("INSERT INTO sessions (case_id,office_id,session_date,description,result,status,notes)
            VALUES ($case_id,$oid,$sdate_sql,'$desc','$result','$sstatus','$notes')");
        logAction($conn, 'create', 'session', $conn->insert_id, 'إضافة جلسة لقضية #' . $case_id);
    }
    // موعد الجلسة القادمة في القضية يتحدّث تلقائياً بآخر جلسة أُدخلت أو عُدّلت لها
    if ($sdate_raw !== '') {
        $conn->query("UPDATE cases SET next_session=$sdate_sql WHERE id=$case_id AND office_id=$oid");
    }
    if (!$sess_id) {
        // تنبيه فوري بجلسة جديدة
        if ($sdate_raw !== '' && $sstatus === 'scheduled') {
            $cr = $conn->query("SELECT case_number, title FROM cases WHERE id=$case_id AND office_id=$oid LIMIT 1");
            if ($cr && $cc = $cr->fetch_assoc()) {
                try { tg_notify_new_session($conn, $oid, $cc['title'], $cc['case_number'], $sdate_raw); } catch (\Throwable $e) {}
            }
        }
    }
    // الجلسة تُضاف دائماً من داخل نافذة قضية محدَّدة — ارجع إليها بعد الحفظ
    $return_case = (int)($_POST['return_case'] ?? $case_id);
    $redir = $return_case ? "cases.php?open_case=$return_case&msg=saved" : "cases.php?msg=saved";
    header("Location: $redir"); exit;
}

/* ── رفع مرفق لقضية (ملف واحد أو عدة ملفات دفعة واحدة) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'attachment') {
    $entity_type = $conn->real_escape_string($_POST['entity_type'] ?? 'case');
    $entity_id   = (int)($_POST['entity_id'] ?? 0);
    $return_case = (int)($_POST['return_case'] ?? ($entity_type === 'case' ? $entity_id : 0));
    $backTo      = "cases.php" . ($return_case ? "?open_case=$return_case&open_att=1" : "");
    $sep         = $return_case ? '&' : '?';

    if (!hasFeature($conn, $oid, 'has_archive')) {
        header("Location: $backTo{$sep}msg=feature_locked"); exit;
    }
    $notes_a = $conn->real_escape_string($_POST['notes'] ?? '');

    $files = normalizeFilesArray($_FILES['attach_file'] ?? [], $_POST['attach_file_label'] ?? null);
    $uploaded = 0;
    foreach ($files as $file) {
        $up = storage_upload($file, 'attachments', $oid, $_SESSION['office_name'] ?? '');
        if ($up['success']) {
            $size     = (int)($file['size'] ?? 0);
            $orig_esc = $conn->real_escape_string($up['name']);
            $stor_esc = $conn->real_escape_string(basename($up['path']));
            $path_esc = $conn->real_escape_string($up['path']);
            $drv_esc  = $conn->real_escape_string($up['driver']);
            $conn->query("INSERT INTO file_attachments
                (office_id,entity_type,entity_id,original_name,stored_name,file_path,file_size,driver,notes,uploaded_by)
                VALUES ($oid,'$entity_type',$entity_id,'$orig_esc','$stor_esc','$path_esc',$size,'$drv_esc','$notes_a',{$_SESSION['user_id']})");
            $uploaded++;
        }
    }
    $msg = $uploaded ? 'uploaded' : 'upload_failed';
    header("Location: $backTo{$sep}msg=$msg"); exit;
}

/* ── جلب البيانات ── */
$_viewArchived = ($_GET['view'] ?? '') === 'archived';
$where = "c.office_id=$oid" . caseScope('c') . " AND c.is_archived=" . ($_viewArchived ? 1 : 0);
if (!empty($_GET['q']))        $where .= " AND (c.case_number LIKE '%".$conn->real_escape_string($_GET['q'])."%' OR c.title LIKE '%".$conn->real_escape_string($_GET['q'])."%')";
if (!empty($_GET['status_f'])) $where .= " AND c.status='".$conn->real_escape_string($_GET['status_f'])."'";

$cases = $conn->query("
    SELECT c.*, cl.full_name client_name,
        IFNULL((
            SELECT SUM(inv.total)
            FROM invoices inv
            WHERE inv.case_id = c.id
              AND inv.status = 'paid'
              AND (inv.direction = 'income' OR inv.direction IS NULL)
              AND inv.office_id = c.office_id
        ), 0) AS inv_paid
    FROM cases c
    LEFT JOIN clients cl ON c.client_id = cl.id
    WHERE $where
    ORDER BY FIELD(c.priority,'urgent','high','medium','low'), c.next_session ASC
");
$clients = $conn->query("SELECT id,full_name FROM clients WHERE office_id=$oid ORDER BY full_name");
$clients_arr = [];
while ($cl = $clients->fetch_assoc()) $clients_arr[$cl['id']] = $cl['full_name'];

$cases_for_select = $conn->query("SELECT id,case_number,title FROM cases WHERE office_id=$oid AND is_archived=0 ORDER BY case_number");
$cases_arr = [];
while ($c = $cases_for_select->fetch_assoc()) $cases_arr[$c['id']] = $c['case_number'].' — '.mb_substr($c['title'],0,40);

// المحامون/الموظفون القابلون للإسناد على القضايا (المالك/المشرف يرون كل شي أصلاً فلا حاجة لإسنادهم)
$ROLE_LBL = ['lawyer'=>'محامٍ','secretary'=>'سكرتير','trainee'=>'متدرّب'];
$officeUsersRes = $conn->query("SELECT id,full_name,role FROM users WHERE office_id=$oid AND role NOT IN('office_owner','admin') AND is_active=1 ORDER BY full_name");
$officeUsers = [];
if ($officeUsersRes) while ($u = $officeUsersRes->fetch_assoc()) $officeUsers[] = $u;

// المُسندون الحاليون لكل قضية — لتحديد الخانات المعلَّمة بنافذة التعديل ولعرض الأسماء بجدول القضايا
$assigneesByCase = [];
$asRes = $conn->query("SELECT ca.case_id, ca.user_id, u.full_name
    FROM case_assignments ca JOIN users u ON ca.user_id=u.id
    WHERE u.office_id=$oid");
if ($asRes) while ($a = $asRes->fetch_assoc()) $assigneesByCase[(int)$a['case_id']][] = $a;

// جلسات كل قضية ومرفقاتها مجمّعة (لعرضها داخل نافذة «جلسات القضية» بلا استعلام لكل صف في الجدول)
$sessionsByCase = [];
$allSessRes = $conn->query("SELECT id,case_id,session_date,description,result,status
    FROM sessions WHERE office_id=$oid ORDER BY session_date DESC");
if ($allSessRes) while ($s = $allSessRes->fetch_assoc()) {
    $s['session_date_fmt'] = $s['session_date'] ? dDate($s['session_date'], true) : '';
    $sessionsByCase[(int)$s['case_id']][] = $s;
}
$attachmentsByCase = [];
$caseAttRes = $conn->query("SELECT id,entity_id,original_name,file_size,notes,created_at
    FROM file_attachments WHERE office_id=$oid AND entity_type='case' ORDER BY created_at DESC");
if ($caseAttRes) while ($a = $caseAttRes->fetch_assoc()) {
    $a['size_fmt'] = formatSize($a['file_size']);
    $a['time_ago'] = timeAgo($a['created_at']);
    $attachmentsByCase[(int)$a['entity_id']][] = $a;
}

$edit = null;
$edit_assignee_ids = [];
if (isset($_GET['edit'])) {
    $edit = $conn->query("SELECT * FROM cases WHERE id=".(int)$_GET['edit']." AND office_id=$oid")->fetch_assoc();
    if ($edit && !canSeeCase($conn, $edit['id'])) { $edit = null; }
    if ($edit) $edit_assignee_ids = array_column($assigneesByCase[(int)$edit['id']] ?? [], 'user_id');
}
$edit_session = null;
if (isset($_GET['edit_session'])) {
    $edit_session = $conn->query("SELECT * FROM sessions WHERE id=".(int)$_GET['edit_session']." AND office_id=$oid")->fetch_assoc();
}

$total_cases  = (int)dbVal($conn,"SELECT COUNT(*) FROM cases WHERE office_id=$oid AND is_archived=0");
$active_count = (int)dbVal($conn,"SELECT COUNT(*) FROM cases WHERE office_id=$oid AND status='active' AND is_archived=0");
$won_count    = (int)dbVal($conn,"SELECT COUNT(*) FROM cases WHERE office_id=$oid AND status='won' AND is_archived=0");
$archived_count = (int)dbVal($conn,"SELECT COUNT(*) FROM cases WHERE office_id=$oid AND is_archived=1" . caseScope('cases'));

include '../includes/office_header.php';
?>

<!-- Page Header -->
<div class="mk-page-hdr">
  <div>
    <div class="mk-page-title"><i class="fas fa-gavel"></i> القضايا والجلسات</div>
    <div class="mk-page-sub"><?= $total_cases ?> قضية · <?= $active_count ?> نشطة · <?= $won_count ?> مكسوبة</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($_viewArchived): ?>
    <a href="cases.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-right"></i> القضايا النشطة</a>
    <?php else: ?>
    <a href="cases.php?view=archived" class="btn btn-outline-dark">
      <i class="fas fa-box-archive"></i> القضايا المؤرشفة<?= $archived_count ? " ($archived_count)" : '' ?>
    </a>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#caseModal">
      <i class="fas fa-plus"></i> قضية جديدة
    </button>
    <?php endif; ?>
  </div>
</div>

<div>
  <div class="card mb-3">
    <div class="card-body py-2">
      <form class="row g-2 align-items-center" method="GET">
        <?php if ($_viewArchived): ?><input type="hidden" name="view" value="archived"><?php endif; ?>
        <div class="col-auto flex-grow-1">
          <input type="text" name="q" class="form-control" placeholder="بحث برقم القضية أو العنوان..."
                 value="<?= e($_GET['q'] ?? '') ?>">
        </div>
        <div class="col-auto">
          <select name="status_f" class="form-select">
            <option value="">جميع الحالات</option>
            <?php foreach(['active'=>'نشطة','closed'=>'مغلقة','won'=>'مكسوبة','lost'=>'خاسرة','settled'=>'متسوية','suspended'=>'موقوفة'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= ($_GET['status_f']??'')===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button class="btn btn-primary"><i class="fas fa-search"></i></button>
          <a href="cases.php<?= $_viewArchived ? '?view=archived' : '' ?>" class="btn btn-outline-secondary ms-1"><i class="fas fa-undo"></i></a>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr>
            <th>رقم القضية</th><th>العنوان</th><th>العميل</th><th>النوع</th>
            <th>المحكمة</th><th>الجلسة القادمة</th>
            <?php if ($_permFinance): ?><th>الأتعاب</th><?php endif; ?>
            <th>الحالة</th><th>الأولوية</th><th>إجراءات</th>
          </tr></thead>
          <tbody>
          <?php if ($cases->num_rows === 0): ?>
          <tr><td colspan="<?= $_permFinance ? 10 : 9 ?>">
            <div class="mk-empty">
              <div class="mk-empty-icon"><i class="fas fa-gavel"></i></div>
              <div class="mk-empty-title"><?= $_viewArchived ? 'لا توجد قضايا مؤرشفة' : 'لا توجد قضايا' ?></div>
              <div class="mk-empty-desc"><?= $_viewArchived ? 'أرشِف القضية المنتهية من زر الأرشفة في صفّها' : 'أضف أول قضية بالضغط على الزر أعلاه' ?></div>
            </div>
          </td></tr>
          <?php else: $caseHeaderMap = []; ?>
          <?php while ($c = $cases->fetch_assoc()):
            $nearSession = $c['next_session'] && (strtotime($c['next_session']) - time()) < 3*86400 && strtotime($c['next_session']) >= time();
            $caseHeaderMap[$c['id']] = ['number' => $c['case_number'], 'title' => $c['title']];
          ?>
          <tr class="<?= $nearSession?'table-warning-subtle':'' ?>">
            <td>
              <span class="fw-bold text-primary"><?= e($c['case_number']) ?></span>
            </td>
            <td style="max-width:220px">
              <div class="fw-semibold text-truncate" title="<?= e($c['title']) ?>"><?= e($c['title']) ?></div>
              <?php $cAssignees = $assigneesByCase[$c['id']] ?? []; if ($cAssignees): ?>
              <div class="text-muted text-truncate" style="font-size:10.5px" title="<?= e(implode('، ', array_column($cAssignees,'full_name'))) ?>">
                <i class="fas fa-user-group me-1"></i><?= e(implode('، ', array_slice(array_column($cAssignees,'full_name'), 0, 2))) ?><?= count($cAssignees) > 2 ? ' +'.(count($cAssignees)-2) : '' ?>
              </div>
              <?php endif; ?>
            </td>
            <td><?= e($c['client_name'] ?? '—') ?></td>
            <td><span class="badge bg-secondary-subtle text-secondary"><?= e($c['case_type']) ?></span></td>
            <td style="font-size:12px;max-width:140px;white-space:normal"><?= e($c['court_name']) ?></td>
            <td>
              <?php if ($c['next_session']): ?>
              <span class="badge <?= $nearSession?'bg-danger-subtle text-danger':'bg-warning-subtle text-warning' ?>">
                <?= dDate($c['next_session'], true) ?>
              </span>
              <?php else: ?> — <?php endif; ?>
            </td>
            <?php if ($_permFinance): ?>
            <td>
              <?php
                $total_collected = (float)$c['inv_paid'];
                $fees_val = (float)$c['fees'];
                $pct = $fees_val > 0 ? min(100, round($total_collected / $fees_val * 100)) : 0;
              ?>
              <div class="<?= $total_collected > 0 ? 'text-success' : 'text-muted' ?> fw-semibold" style="font-size:12.5px">
                <?= number_format($total_collected) ?> ر.س
              </div>
              <div class="text-muted" style="font-size:11px">من <?= number_format($fees_val) ?> ر.س</div>
              <?php if ($fees_val > 0): ?>
              <div class="progress mt-1" style="height:4px;min-width:80px">
                <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
              </div>
              <small class="text-muted" style="font-size:10px"><?= $pct ?>%</small>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td><?= statusBadge($c['status']) ?></td>
            <td><?= priorityBadge($c['priority']) ?></td>
            <td>
              <div class="d-flex gap-1">
                <?php $cSessCount = count($sessionsByCase[$c['id']] ?? []); $cAttCount = count($attachmentsByCase[$c['id']] ?? []); ?>
                <button type="button" class="btn btn-sm btn-outline-info position-relative"
                        onclick="openCaseSessions(<?= $c['id'] ?>)" title="جلسات القضية">
                  <i class="fas fa-calendar-days"></i>
                  <?php if ($cSessCount): ?>
                  <span class="badge rounded-pill bg-info position-absolute top-0 start-0 translate-middle" style="font-size:9px">
                    <?= $cSessCount ?>
                  </span>
                  <?php endif; ?>
                </button>
                <button type="button" class="btn btn-sm btn-outline-warning position-relative"
                        onclick="openCaseAttachments(<?= $c['id'] ?>)" title="مرفقات القضية">
                  <i class="fas fa-paperclip"></i>
                  <?php if ($cAttCount): ?>
                  <span class="badge rounded-pill bg-warning text-dark position-absolute top-0 start-0 translate-middle" style="font-size:9px">
                    <?= $cAttCount ?>
                  </span>
                  <?php endif; ?>
                </button>
                <?php if ($_viewArchived): ?>
                <a href="case_archive_export.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-success" title="تنزيل ملف القضية الكامل (ZIP)">
                  <i class="fas fa-download"></i>
                </a>
                <?php if ($_can_edit): ?>
                <a href="cases.php?unarchive=<?= $c['id'] ?>" class="btn btn-sm btn-outline-dark"
                   data-confirm="استعادة هذه القضية من الأرشيف؟" title="استعادة من الأرشيف">
                  <i class="fas fa-box-open"></i>
                </a>
                <?php endif; ?>
                <?php else: ?>
                <?php if ($_can_edit && in_array($c['status'], ['closed','won','lost','settled'], true)): ?>
                <a href="cases.php?archive=<?= $c['id'] ?>" class="btn btn-sm btn-outline-dark"
                   data-confirm="أرشفة القضية بكامل بياناتها وملفاتها؟ ستخرج من القضايا النشطة ويمكن استعادتها لاحقاً." title="أرشفة القضية">
                  <i class="fas fa-box-archive"></i>
                </a>
                <?php endif; ?>
                <a href="cases.php?edit=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="تعديل">
                  <i class="fas fa-edit"></i>
                </a>
                <?php endif; ?>
                <a href="cases.php?delete=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger"
                   data-confirm="هل تريد حذف هذه القضية؟" title="حذف">
                  <i class="fas fa-trash"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ═══ Modal القضية ═══ -->
<div class="modal fade" id="caseModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-gavel me-2"></i><?= $edit?'تعديل القضية':'قضية جديدة' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="form_type" value="case">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
        <input type="hidden" name="return_to" value="<?= e($_GET['return_to'] ?? '') ?>">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">رقم القضية *</label>
              <input type="text" name="case_number" class="form-control" required value="<?= e($edit['case_number'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">العميل</label>
              <select name="client_id" class="form-select">
                <option value="0">— اختر —</option>
                <?php foreach ($clients_arr as $cid=>$cn): ?>
                <option value="<?= $cid ?>" <?= ($edit ? ($edit['client_id']??'')==$cid : $_new_for_client===$cid) ? 'selected':'' ?>><?= e($cn) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">نوع القضية</label>
              <input type="text" name="case_type" class="form-control" value="<?= e($edit['case_type'] ?? '') ?>" placeholder="عمالية / تجارية / جنائية...">
            </div>
            <div class="col-12">
              <label class="form-label">عنوان القضية *</label>
              <input type="text" name="title" class="form-control" required value="<?= e($edit['title'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">المحكمة</label>
              <input type="text" name="court_name" class="form-control" value="<?= e($edit['court_name'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">الحالة</label>
              <select name="status" class="form-select">
                <?php foreach(['active'=>'نشطة','closed'=>'مغلقة','suspended'=>'موقوفة','won'=>'مكسوبة','lost'=>'خاسرة','settled'=>'متسوية'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= ($edit['status']??'active')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">الأولوية</label>
              <select name="priority" class="form-select">
                <?php foreach(['low'=>'منخفضة','medium'=>'متوسطة','high'=>'عالية','urgent'=>'عاجلة'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= ($edit['priority']??'medium')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">موعد الجلسة القادمة</label>
              <input type="datetime-local" name="next_session" class="form-control"
                     value="<?= $edit && $edit['next_session'] ? date('Y-m-d\TH:i', strtotime($edit['next_session'])) : '' ?>">
            </div>
            <?php if ($_permFinance): ?>
            <div class="col-md-4">
              <label class="form-label">الأتعاب المتفق عليها (ر.س)</label>
              <input type="number" name="fees" class="form-control mk-currency" step="0.01" value="<?= $edit['fees'] ?? 0 ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">المبلغ المحصَّل (ر.س)</label>
              <?php
                $edit_collected = $edit ? (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE case_id={$edit['id']} AND status='paid' AND (direction='income' OR direction IS NULL) AND office_id=$oid")->fetch_assoc()['s'] : 0;
              ?>
              <div class="form-control bg-light d-flex align-items-center justify-content-between" style="cursor:default">
                <span class="fw-bold <?= $edit_collected > 0 ? 'text-success' : 'text-muted' ?>"><?= number_format($edit_collected, 2) ?> ر.س</span>
                <small class="text-muted" style="font-size:11px">من الفواتير المدفوعة</small>
              </div>
              <div class="form-text">
                <a href="invoices.php" class="text-primary text-decoration-none"><i class="fas fa-file-invoice-dollar"></i> إصدار فاتورة لهذه القضية</a>
              </div>
            </div>
            <?php else: ?>
            <!-- بلا صلاحية الشؤون المالية: نحافظ على قيمة الأتعاب الحالية عبر حقل مخفي بدل إظهارها -->
            <input type="hidden" name="fees" value="<?= $edit['fees'] ?? 0 ?>">
            <?php endif; ?>
            <div class="col-12">
              <label class="form-label">وصف القضية</label>
              <textarea name="description" class="form-control" rows="2" placeholder="تفاصيل القضية..."><?= e($edit['description'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">ملاحظات داخلية</label>
              <textarea name="notes" class="form-control" rows="2"><?= e($edit['notes'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">
                <i class="fas fa-user-group me-1 text-primary"></i>إسناد القضية إلى
                <small class="text-muted fw-normal">— يرى المُسنَدون هذه القضية وجلساتها ومهامها وفواتيرها، ويكتب كل واحد تقرير عمله الخاص بها</small>
              </label>
              <?php if (!$officeUsers): ?>
              <div class="text-muted" style="font-size:12.5px">لا يوجد محامون/موظفون بعد — أضفهم من صفحة إدارة المستخدمين</div>
              <?php else: ?>
              <div class="d-flex flex-wrap gap-2 p-2 rounded border" style="max-height:150px;overflow:auto">
                <?php foreach ($officeUsers as $ou): ?>
                <label class="d-flex align-items-center gap-1 px-2 py-1 rounded border" style="font-size:12.5px;cursor:pointer;background:#f8fafc">
                  <input type="checkbox" name="assignees[]" value="<?= $ou['id'] ?>"
                         <?= in_array($ou['id'], $edit_assignee_ids) ? 'checked' : '' ?>>
                  <?= e($ou['full_name']) ?>
                  <span class="text-muted">(<?= e($ROLE_LBL[$ou['role']] ?? $ou['role']) ?>)</span>
                </label>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
            <?php if (!$edit && hasFeature($conn, $oid, 'has_archive')): ?>
            <div class="col-12">
              <label class="form-label">رفع وثيقة <small class="text-muted">(PDF, Word, صورة — حد 20MB)</small></label>
              <input type="file" name="case_file" class="form-control mk-multi"
                     accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
            </div>
            <?php endif; ?>
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

<!-- ═══ Modal الجلسة ═══ -->
<div class="modal fade" id="sessionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i><?= $edit_session?'تعديل الجلسة':'جلسة جديدة' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="form_type" value="session">
        <input type="hidden" name="session_id" value="<?= $edit_session['id'] ?? '' ?>">
        <input type="hidden" name="return_case" id="sessReturnCase" value="<?= $edit_session['case_id'] ?? '' ?>">
        <div class="modal-body row g-3">
          <div class="col-12">
            <label class="form-label">القضية *</label>
            <select name="case_id" id="sessCaseSelect" class="form-select" required>
              <option value="">— اختر —</option>
              <?php foreach ($cases_arr as $cid=>$ctitle): ?>
              <option value="<?= $cid ?>" <?= ($edit_session['case_id']??'')==$cid?'selected':'' ?>><?= e($ctitle) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">تاريخ ووقت الجلسة *</label>
            <input type="datetime-local" name="session_date" class="form-control" required
                   value="<?= $edit_session ? date('Y-m-d\TH:i', strtotime($edit_session['session_date'])) : '' ?>">
          </div>
          <div class="col-12">
            <label class="form-label">الحالة</label>
            <select name="status" class="form-select">
              <?php foreach(['scheduled'=>'مجدولة','held'=>'عُقدت','postponed'=>'مؤجلة','cancelled'=>'ملغاة'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= ($edit_session['status']??'scheduled')===$v?'selected':'' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">وصف الجلسة</label>
            <textarea name="description" class="form-control" rows="2"><?= e($edit_session['description'] ?? '') ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">نتيجة الجلسة</label>
            <textarea name="result" class="form-control" rows="2"><?= e($edit_session['result'] ?? '') ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">ملاحظات</label>
            <textarea name="notes" class="form-control" rows="2"><?= e($edit_session['notes'] ?? '') ?></textarea>
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

<!-- ═══ Modal جلسات القضية (يُملأ حسب القضية المضغوط عليها) ═══ -->
<div class="modal fade" id="caseSessModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-calendar-days me-2"></i>جلسات القضية <span id="csTitle" class="text-muted fw-normal"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex justify-content-end gap-2 mb-2">
          <a id="csPdfLink" href="#" target="_blank" class="btn btn-sm btn-outline-dark">
            <i class="fas fa-file-pdf me-1"></i>PDF
          </a>
          <?php if (can('sessions','add')): ?>
          <button type="button" class="btn btn-sm btn-primary" onclick="csAddSession()">
            <i class="fas fa-plus me-1"></i>جلسة جديدة
          </button>
          <?php endif; ?>
        </div>
        <div class="table-responsive mb-2">
          <table class="table table-sm table-hover mb-0">
            <thead><tr><th>التاريخ</th><th>الوصف</th><th>النتيجة</th><th>الحالة</th><th></th></tr></thead>
            <tbody id="csSessBody"></tbody>
          </table>
        </div>
        <div id="csSessEmpty" class="mk-empty d-none">
          <div class="mk-empty-icon"><i class="fas fa-calendar-xmark"></i></div>
          <div class="mk-empty-title">لا توجد جلسات لهذه القضية بعد</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إغلاق</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ Modal مرفقات القضية (يُملأ حسب القضية المضغوط عليها) ═══ -->
<div class="modal fade" id="caseAttModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-paperclip me-2"></i>مرفقات القضية <span id="caTitle" class="text-muted fw-normal"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="caAttList" class="mb-2"></div>
        <div id="caAttEmpty" class="mk-empty d-none">
          <div class="mk-empty-icon"><i class="fas fa-folder-open"></i></div>
          <div class="mk-empty-title">لا توجد مرفقات لهذه القضية بعد</div>
        </div>

        <?php if (!hasFeature($conn, $oid, 'has_archive')): ?>
        <div class="mk-upgrade-banner">
          <div class="mk-upgrade-icon"><i class="fas fa-paperclip"></i></div>
          <div class="mk-upgrade-body">
            <div class="mk-upgrade-title">نظام رفع المرفقات</div>
            <div class="mk-upgrade-desc">متوفر في باقة الاحترافية وما فوق</div>
          </div>
          <a href="profile.php?tab=upgrade" class="btn btn-gold flex-shrink-0">ترقية الباقة</a>
        </div>
        <?php elseif ($_can_edit): ?>
        <form method="POST" enctype="multipart/form-data" class="border rounded p-3">
          <input type="hidden" name="form_type" value="attachment">
          <input type="hidden" name="entity_type" value="case">
          <input type="hidden" name="entity_id" id="caAttEntityId" value="">
          <input type="hidden" name="return_case" id="caAttReturnCase" value="">
          <label class="form-label mb-1">إضافة مرفق <small class="text-muted">— زر «إضافة ملف آخر» لرفع عدة ملفات دفعة واحدة (حتى 20MB لكل ملف)</small></label>
          <input type="file" name="attach_file" class="form-control mk-multi" required
                 accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip,.rar">
          <div class="row g-2 align-items-end mt-2">
            <div class="col">
              <label class="form-label">ملاحظة</label>
              <input type="text" name="notes" class="form-control" placeholder="اختياري (تُطبَّق على كل الملفات)">
            </div>
            <div class="col-auto">
              <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-1"></i>رفع</button>
            </div>
          </div>
        </form>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إغلاق</button>
      </div>
    </div>
  </div>
</div>

<script>
window.CASE_SESS_DATA = <?= json_encode($sessionsByCase, JSON_UNESCAPED_UNICODE) ?>;
window.CASE_ATT_DATA  = <?= json_encode($attachmentsByCase, JSON_UNESCAPED_UNICODE) ?>;
window.CASE_HEADER    = <?= json_encode($caseHeaderMap ?? [], JSON_UNESCAPED_UNICODE) ?>;
var CAN_EDIT_SESS   = <?= can('sessions','edit') ? 'true' : 'false' ?>;
var CAN_DEL_SESS    = <?= can('sessions','delete') ? 'true' : 'false' ?>;
var CAN_ARCHIVE_ATT = <?= hasFeature($conn, $oid, 'has_archive') ? 'true' : 'false' ?>;
var CURRENT_CASE_ID = null;

var SESS_STATUS_BADGE = {
  scheduled: ['bg-warning-subtle text-warning', 'مجدولة'],
  held: ['bg-success-subtle text-success', 'عُقدت'],
  postponed: ['bg-secondary-subtle text-secondary', 'مؤجلة'],
  cancelled: ['bg-danger-subtle text-danger', 'ملغاة']
};

function csEsc(s) {
  return (s === null || s === undefined ? '' : String(s)).replace(/[&<>"']/g, function (c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}

function csWireConfirm(root) {
  root.querySelectorAll('[data-confirm]').forEach(function (el) {
    if (el.dataset.confirmWired) return;
    el.dataset.confirmWired = '1';
    el.addEventListener('click', function (e) {
      if (!confirm(el.getAttribute('data-confirm') || 'هل أنت متأكد؟')) e.preventDefault();
    });
  });
}

function caseLabel(caseId) {
  var head = window.CASE_HEADER[caseId] || {number: '', title: ''};
  var t = (head.number ? head.number + ' — ' : '') + (head.title || '');
  return t ? '— ' + t : '';
}

function openCaseSessions(caseId) {
  CURRENT_CASE_ID = caseId;
  document.getElementById('csTitle').textContent = caseLabel(caseId);
  document.getElementById('csPdfLink').href = 'sessions_pdf.php?case=' + caseId + '&view=1';

  var sessions = window.CASE_SESS_DATA[caseId] || [];
  var body = document.getElementById('csSessBody');
  body.innerHTML = '';
  document.getElementById('csSessEmpty').classList.toggle('d-none', sessions.length > 0);
  sessions.forEach(function (s) {
    var badge = SESS_STATUS_BADGE[s.status] || ['bg-secondary-subtle text-secondary', s.status];
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td style="white-space:nowrap;font-weight:600;font-size:12.5px">' + csEsc(s.session_date_fmt) + '</td>' +
      '<td style="font-size:12px;max-width:160px" class="text-truncate">' + csEsc((s.description || '').slice(0, 60)) + '</td>' +
      '<td style="font-size:12px">' + csEsc(s.result || '—') + '</td>' +
      '<td><span class="badge ' + badge[0] + '">' + csEsc(badge[1]) + '</span></td>' +
      '<td class="text-nowrap">' +
        (CAN_EDIT_SESS ? '<a href="cases.php?edit_session=' + s.id + '" class="btn btn-sm btn-outline-primary" title="تعديل"><i class="fas fa-edit"></i></a> ' : '') +
        (CAN_DEL_SESS ? '<a href="cases.php?del_session=' + s.id + '" class="btn btn-sm btn-outline-danger" data-confirm="حذف هذه الجلسة؟" title="حذف"><i class="fas fa-trash"></i></a>' : '') +
      '</td>';
    body.appendChild(tr);
  });

  var modalEl = document.getElementById('caseSessModal');
  csWireConfirm(modalEl);
  bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function openCaseAttachments(caseId) {
  CURRENT_CASE_ID = caseId;
  document.getElementById('caTitle').textContent = caseLabel(caseId);

  var atts = window.CASE_ATT_DATA[caseId] || [];
  var attWrap = document.getElementById('caAttList');
  attWrap.innerHTML = '';
  document.getElementById('caAttEmpty').classList.toggle('d-none', atts.length > 0);
  atts.forEach(function (a) {
    var div = document.createElement('div');
    div.className = 'mk-file-item';
    div.innerHTML =
      '<div class="mk-file-icon other"><i class="fas fa-file"></i></div>' +
      '<div class="mk-file-body">' +
        '<div class="mk-file-name">' + csEsc(a.original_name) + '</div>' +
        '<div class="mk-file-meta">' + csEsc(a.size_fmt) + ' · ' + csEsc(a.time_ago) +
          (a.notes ? ' · <span class="text-muted">' + csEsc(a.notes) + '</span>' : '') +
        '</div>' +
      '</div>' +
      '<div class="d-flex gap-1 flex-shrink-0">' +
        '<a href="file.php?t=att&id=' + a.id + '" class="btn btn-sm btn-outline-primary" target="_blank" title="عرض"><i class="fas fa-eye"></i></a>' +
        '<a href="file.php?t=att&id=' + a.id + '&dl=1" class="btn btn-sm btn-outline-secondary" title="تحميل"><i class="fas fa-download"></i></a>' +
        (CAN_ARCHIVE_ATT ? '<a href="cases.php?archive_attach=' + a.id + '" class="btn btn-sm btn-outline-dark" data-confirm="أرشفة هذا الملف في الأرشيف الإلكتروني؟" title="أرشفة"><i class="fas fa-box-archive"></i></a>' : '') +
        '<a href="cases.php?del_attach=' + a.id + '" class="btn btn-sm btn-outline-danger" data-confirm="حذف هذا الملف؟" title="حذف"><i class="fas fa-trash"></i></a>' +
      '</div>';
    attWrap.appendChild(div);
  });

  document.getElementById('caAttEntityId').value = caseId;
  document.getElementById('caAttReturnCase').value = caseId;

  var modalEl = document.getElementById('caseAttModal');
  csWireConfirm(modalEl);
  bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function csAddSession() {
  document.getElementById('sessCaseSelect').value = CURRENT_CASE_ID;
  document.getElementById('sessReturnCase').value = CURRENT_CASE_ID;
  var cur = bootstrap.Modal.getInstance(document.getElementById('caseSessModal'));
  if (cur) cur.hide();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('sessionModal')).show();
}

<?php if ($edit): ?>
document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('caseModal')).show();});
<?php elseif ($_new_for_client): ?>
document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('caseModal')).show();});
<?php elseif ($edit_session): ?>
document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('sessionModal')).show();});
<?php endif; ?>
<?php if ($openCaseId = (int)($_GET['open_case'] ?? 0)): ?>
document.addEventListener('DOMContentLoaded', function () {
  <?php if (!empty($_GET['open_att'])): ?>
  openCaseAttachments(<?= $openCaseId ?>);
  <?php else: ?>
  openCaseSessions(<?= $openCaseId ?>);
  <?php endif; ?>
});
<?php endif; ?>
</script>

<?php include '../includes/office_footer.php'; ?>
