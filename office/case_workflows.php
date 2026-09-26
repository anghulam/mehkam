<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('case_workflows','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'case_workflows')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'أتمتة إجراءات القضية';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('case_workflows','add'); $_canDel = can('case_workflows','delete');
$_canApprove = can('case_workflows','approve'); // مستوى المدير: يرى كل التشغيلات ويدير القوالب
$tab = ($_GET['tab'] ?? 'runs') === 'templates' ? 'templates' : 'runs';

$conn->query("CREATE TABLE IF NOT EXISTS workflow_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    case_type VARCHAR(100) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("CREATE TABLE IF NOT EXISTS workflow_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    step_order INT DEFAULT 0,
    title VARCHAR(255) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    anchor ENUM('start','judgment') DEFAULT 'start',
    offset_days INT DEFAULT 0,
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    INDEX idx_tpl (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("CREATE TABLE IF NOT EXISTS case_workflow_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    case_id INT NOT NULL,
    template_id INT NOT NULL,
    start_date DATE NOT NULL,
    judgment_date DATE DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id), INDEX idx_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $conn->query("ALTER TABLE tasks ADD COLUMN workflow_run_id INT NULL DEFAULT NULL"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE tasks ADD COLUMN workflow_step_id INT NULL DEFAULT NULL"); } catch (\Throwable $e) {}

/* ── قوالب افتراضية (تُنشأ مرة لكل مكتب — قيم إرشادية قابلة للتعديل) ── */
function wf_seed($conn, $oid) {
    $post = [
        ['استلام صورة الحكم وتسجيل تاريخ التبليغ', 'judgment', 0, 'urgent'],
        ['دراسة الحكم وإبلاغ العميل بالنتيجة واتخاذ قرار الاعتراض', 'judgment', 5, 'high'],
        ['الموعد النظامي للاعتراض (٣٠ يوماً — تحقّق من تاريخ التبليغ)', 'judgment', 30, 'urgent'],
        ['متابعة اكتساب الحكم صفة القطعية وتقديم طلب التنفيذ إن لزم', 'judgment', 40, 'medium'],
    ];
    $defs = [
        ['قضية عمالية', 'عمالية', 'مراحل الدعوى العمالية', [
            ['مراجعة ملف العميل وتحديد الطلبات (أجور، مكافأة، تعويض)', 'start', 0, 'high'],
            ['التحقق من إتمام مرحلة التسوية الودية قبل رفع الدعوى', 'start', 3, 'high'],
            ['تجهيز صحيفة الدعوى والمرفقات', 'start', 7, 'medium'],
            ['قيد الدعوى ومتابعة إحالتها وتحديد الجلسة', 'start', 10, 'high'],
            ['تجهيز المذكرات للجلسة الأولى', 'start', 20, 'medium'],
        ]],
        ['قضية تجارية', 'تجارية', 'مراحل الدعوى التجارية', [
            ['دراسة المستندات (عقود، فواتير، شيكات) وتحديد سند المطالبة', 'start', 0, 'high'],
            ['إرسال إنذار/مطالبة رسمية للطرف الآخر إن لزم', 'start', 3, 'medium'],
            ['تجهيز صحيفة الدعوى وقيدها', 'start', 10, 'high'],
            ['متابعة التبليغ وتحديد الجلسة', 'start', 14, 'medium'],
            ['تجهيز المذكرات والمستندات للجلسة الأولى', 'start', 25, 'medium'],
        ]],
        ['قضية أحوال شخصية', 'أحوال شخصية', 'مراحل قضايا الأحوال الشخصية', [
            ['جمع بيانات الأطراف والوثائق (صك زواج، هويات، مستندات)', 'start', 0, 'high'],
            ['التحقق من متطلبات الصلح/التسوية الأسرية قبل القيد', 'start', 4, 'medium'],
            ['تجهيز الطلب وقيده', 'start', 8, 'high'],
            ['متابعة تحديد الجلسة وتبليغ الأطراف', 'start', 14, 'medium'],
        ]],
        ['قضية جزائية', 'جزائية', 'مراحل القضايا الجزائية', [
            ['الاطلاع على ملف القضية ولائحة الاتهام/الشكوى', 'start', 0, 'urgent'],
            ['مقابلة العميل وجمع الأدلة والشهود', 'start', 3, 'high'],
            ['تجهيز مذكرة الدفاع/الرد', 'start', 10, 'high'],
            ['متابعة موعد الجلسة وتجهيز المرافعة', 'start', 18, 'high'],
        ]],
        ['إجراءات عامة', '', 'قالب عام لأي قضية', [
            ['فتح الملف وجمع المستندات', 'start', 0, 'medium'],
            ['تجهيز الصحيفة أو الرد وقيدها', 'start', 7, 'high'],
            ['متابعة الجلسة الأولى', 'start', 21, 'medium'],
        ]],
    ];
    foreach ($defs as [$name, $type, $desc, $steps]) {
        $n = $conn->real_escape_string($name); $t = $conn->real_escape_string($type); $d = $conn->real_escape_string($desc);
        $conn->query("INSERT INTO workflow_templates (office_id,name,case_type,description) VALUES ($oid,'$n','$t','$d')");
        $tid = (int)$conn->insert_id; $ord = 0;
        foreach (array_merge($steps, $post) as [$title, $anchor, $off, $pri]) {
            $ti = $conn->real_escape_string($title);
            $conn->query("INSERT INTO workflow_steps (template_id,step_order,title,anchor,offset_days,priority) VALUES ($tid,".(++$ord).",'$ti','$anchor',$off,'$pri')");
        }
    }
}
$hasTpl = (int)$conn->query("SELECT COUNT(*) c FROM workflow_templates WHERE office_id=$oid")->fetch_assoc()['c'];
if (!$hasTpl && !isset($_GET['noseed'])) wf_seed($conn, $oid);

/** ينشئ مهام الخطوات ذات المرساة المحددة لتشغيل معيّن */
function wf_make_tasks($conn, $oid, $uid, $run_id, $case_id, $template_id, $anchor, $base_date, $assignee_id, $assignee_name) {
    $r = $conn->query("SELECT * FROM workflow_steps WHERE template_id=".(int)$template_id." AND anchor='$anchor' ORDER BY step_order");
    $n = 0;
    while ($s = $r->fetch_assoc()) {
        $exists = $conn->query("SELECT id FROM tasks WHERE workflow_run_id=$run_id AND workflow_step_id=".(int)$s['id'])->num_rows;
        if ($exists) continue;
        $due = date('Y-m-d 09:00:00', strtotime($base_date . ' +' . (int)$s['offset_days'] . ' days'));
        $ti = $conn->real_escape_string($s['title']);
        $an = $conn->real_escape_string($assignee_name);
        $conn->query("INSERT INTO tasks (office_id,case_id,title,description,assigned_to,assigned_to_id,priority,status,due_date,created_by,workflow_run_id,workflow_step_id)
            VALUES ($oid,$case_id,'$ti','مهمة من أتمتة الإجراءات','$an',".($assignee_id ?: 'NULL').",'{$s['priority']}','pending','$due',".($uid ?: 'NULL').",$run_id,".(int)$s['id'].")");
        $n++;
    }
    return $n;
}

$officeUsers = [];
$ur = $conn->query("SELECT id, full_name FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
if ($ur) while ($u = $ur->fetch_assoc()) $officeUsers[$u['id']] = $u['full_name'];
$_assignable = $_canApprove ? $officeUsers : array_intersect_key($officeUsers, [$uid => true]);

/* ── تطبيق قالب على قضية ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'apply') {
    requirePerm('case_workflows', 'add', 'case_workflows.php?msg=denied');
    $case_id = (int)($_POST['case_id'] ?? 0);
    $tpl_id  = (int)($_POST['template_id'] ?? 0);
    $start   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['start_date'] ?? '') ? $_POST['start_date'] : date('Y-m-d');
    $asg     = (int)($_POST['assigned_to'] ?? 0);
    if (!isset($_assignable[$asg])) $asg = $_canApprove ? $asg : $uid;
    $okCase = $conn->query("SELECT id FROM cases WHERE id=$case_id AND office_id=$oid" . caseScope('cases'))->fetch_assoc();
    $okTpl  = $conn->query("SELECT id FROM workflow_templates WHERE id=$tpl_id AND office_id=$oid")->fetch_assoc();
    if (!$okCase || !$okTpl) { header('Location: case_workflows.php?msg=invalid'); exit; }
    $conn->query("INSERT INTO case_workflow_runs (office_id,case_id,template_id,start_date,assigned_to,created_by)
        VALUES ($oid,$case_id,$tpl_id,'$start',".($asg ?: 'NULL').",".($uid ?: 'NULL').")");
    $run_id = (int)$conn->insert_id;
    $n = wf_make_tasks($conn, $oid, $uid, $run_id, $case_id, $tpl_id, 'start', $start, $asg, $officeUsers[$asg] ?? '');
    header("Location: case_workflows.php?msg=applied&n=$n"); exit;
}

/* ── تسجيل صدور الحكم → يولّد مهام ما بعد الحكم ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'judgment') {
    $rid = (int)($_POST['run_id'] ?? 0);
    $jd  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['judgment_date'] ?? '') ? $_POST['judgment_date'] : '';
    $own = $_canApprove ? '' : " AND (created_by=$uid OR assigned_to=$uid)";
    $run = $jd ? $conn->query("SELECT * FROM case_workflow_runs WHERE id=$rid AND office_id=$oid$own")->fetch_assoc() : null;
    if (!$run) { header('Location: case_workflows.php?msg=invalid'); exit; }
    $conn->query("UPDATE case_workflow_runs SET judgment_date='$jd' WHERE id=$rid");
    $n = wf_make_tasks($conn, $oid, $uid, $rid, (int)$run['case_id'], (int)$run['template_id'], 'judgment', $jd, (int)$run['assigned_to'], $officeUsers[(int)$run['assigned_to']] ?? '');
    header("Location: case_workflows.php?msg=judgment&n=$n"); exit;
}

/* ── حذف تشغيل (تُحذف مهامه غير المنجزة) ── */
if (isset($_GET['delete_run']) && $_canDel) {
    $rid = (int)$_GET['delete_run'];
    $own = $_canApprove ? '' : " AND created_by=$uid";
    $run = $conn->query("SELECT id FROM case_workflow_runs WHERE id=$rid AND office_id=$oid$own")->fetch_assoc();
    if ($run) {
        $conn->query("DELETE FROM tasks WHERE workflow_run_id=$rid AND office_id=$oid AND status IN ('pending','in_progress')");
        $conn->query("UPDATE tasks SET workflow_run_id=NULL WHERE workflow_run_id=$rid AND office_id=$oid");
        $conn->query("DELETE FROM case_workflow_runs WHERE id=$rid");
    }
    header('Location: case_workflows.php?msg=deleted'); exit;
}

/* ── إدارة القوالب (مستوى المدير) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'tpl') {
    if (!$_canApprove) { header('Location: case_workflows.php?msg=denied'); exit; }
    $n = $conn->real_escape_string(trim($_POST['name'] ?? '')); $ct = $conn->real_escape_string(trim($_POST['case_type'] ?? ''));
    if ($n !== '') $conn->query("INSERT INTO workflow_templates (office_id,name,case_type) VALUES ($oid,'$n','$ct')");
    header('Location: case_workflows.php?tab=templates&msg=saved'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'step') {
    if (!$_canApprove) { header('Location: case_workflows.php?msg=denied'); exit; }
    $tid = (int)($_POST['template_id'] ?? 0);
    $ok = $conn->query("SELECT id FROM workflow_templates WHERE id=$tid AND office_id=$oid")->fetch_assoc();
    $title = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $anchor = ($_POST['anchor'] ?? '') === 'judgment' ? 'judgment' : 'start';
    $off = max(0, min(730, (int)($_POST['offset_days'] ?? 0)));
    $pri = in_array($_POST['priority'] ?? '', ['low','medium','high','urgent'], true) ? $_POST['priority'] : 'medium';
    if ($ok && $title !== '') {
        $ord = (int)$conn->query("SELECT COALESCE(MAX(step_order),0)+1 o FROM workflow_steps WHERE template_id=$tid")->fetch_assoc()['o'];
        $conn->query("INSERT INTO workflow_steps (template_id,step_order,title,anchor,offset_days,priority) VALUES ($tid,$ord,'$title','$anchor',$off,'$pri')");
    }
    header('Location: case_workflows.php?tab=templates&msg=saved'); exit;
}
if (isset($_GET['del_step']) && $_canApprove) {
    $sid = (int)$_GET['del_step'];
    $conn->query("DELETE s FROM workflow_steps s JOIN workflow_templates t ON t.id=s.template_id WHERE s.id=$sid AND t.office_id=$oid");
    header('Location: case_workflows.php?tab=templates&msg=deleted'); exit;
}
if (isset($_GET['del_tpl']) && $_canApprove) {
    $tid = (int)$_GET['del_tpl'];
    $used = (int)$conn->query("SELECT COUNT(*) c FROM case_workflow_runs WHERE template_id=$tid AND office_id=$oid")->fetch_assoc()['c'];
    if (!$used) {
        $conn->query("DELETE FROM workflow_steps WHERE template_id=$tid AND template_id IN (SELECT id FROM workflow_templates WHERE office_id=$oid)");
        $conn->query("DELETE FROM workflow_templates WHERE id=$tid AND office_id=$oid");
    }
    header('Location: case_workflows.php?tab=templates&msg=' . ($used ? 'inuse' : 'deleted')); exit;
}

/* ── بيانات ── */
$templates = [];
$tr = $conn->query("SELECT * FROM workflow_templates WHERE office_id=$oid ORDER BY id");
if ($tr) while ($t = $tr->fetch_assoc()) $templates[$t['id']] = $t;

$scope = $_canApprove ? '' : " AND (r.created_by=$uid OR r.assigned_to=$uid)";
$runs = [];
$rr = $conn->query("SELECT r.*, c.case_number, c.title case_title, t.name tpl_name, u.full_name asg_name,
    (SELECT COUNT(*) FROM tasks k WHERE k.workflow_run_id=r.id) total_tasks,
    (SELECT COUNT(*) FROM tasks k WHERE k.workflow_run_id=r.id AND k.status='completed') done_tasks,
    (SELECT COUNT(*) FROM tasks k WHERE k.workflow_run_id=r.id AND k.status IN ('pending','in_progress') AND k.due_date < NOW()) late_tasks
    FROM case_workflow_runs r LEFT JOIN cases c ON c.id=r.case_id LEFT JOIN workflow_templates t ON t.id=r.template_id
    LEFT JOIN users u ON u.id=r.assigned_to
    WHERE r.office_id=$oid$scope ORDER BY r.created_at DESC LIMIT 200");
if ($rr) while ($x = $rr->fetch_assoc()) $runs[] = $x;

$cases_arr = [];
$cr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid AND status='active'" . caseScope('cases') . " ORDER BY id DESC LIMIT 300");
if ($cr) while ($x = $cr->fetch_assoc()) $cases_arr[$x['id']] = $x['case_number'] . ' — ' . mb_substr($x['title'], 0, 40);

$stepsBy = [];
if ($tab === 'templates' && $templates) {
    $sr = $conn->query("SELECT * FROM workflow_steps WHERE template_id IN (".implode(',', array_map('intval', array_keys($templates))).") ORDER BY step_order");
    if ($sr) while ($s = $sr->fetch_assoc()) $stepsBy[$s['template_id']][] = $s;
}
$_priMap = ['low'=>'منخفضة','medium'=>'متوسطة','high'=>'عالية','urgent'=>'عاجلة'];

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-diagram-project"></i> أتمتة إجراءات القضية</div>
<div class="mk-page-sub mb-3">اختر نوع القضية فتُولَّد خطة مراحلها ومهامها ومواعيدها تلقائياً — وعند صدور الحكم تُنشأ مهام الاعتراض والتنفيذ</div>

<?php if (isset($_GET['msg'])):
  $mm = ['applied'=>['success','تم تطبيق القالب وإنشاء '.(int)($_GET['n'] ?? 0).' مهمة'],'judgment'=>['success','سُجّل صدور الحكم وأُنشئت '.(int)($_GET['n'] ?? 0).' مهمة ما بعد الحكم'],
         'saved'=>['success','تم الحفظ'],'deleted'=>['success','تم الحذف'],'inuse'=>['warning','لا يمكن حذف قالب مستخدم في تشغيلات قائمة'],
         'invalid'=>['danger','بيانات غير صحيحة'],'denied'=>['danger','ليست لديك صلاحية لهذا الإجراء']];
  $m = $mm[$_GET['msg']] ?? ['success','تم']; ?>
<div class="alert alert-<?= $m[0] ?> alert-dismissible fade show"><?= $m[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='runs'?'active':'' ?>" href="case_workflows.php">التشغيلات على القضايا</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='templates'?'active':'' ?>" href="case_workflows.php?tab=templates">القوالب والخطوات</a></li>
</ul>

<?php if ($tab === 'runs'): ?>
<div class="d-flex justify-content-end mb-3">
  <?php if ($_canAdd): ?><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#applyModal"><i class="fas fa-play me-1"></i>تطبيق قالب على قضية</button><?php endif; ?>
</div>
<div class="row g-3">
<?php if (!$runs): ?><div class="col-12"><div class="text-muted text-center py-5">لا توجد تشغيلات بعد — طبّق قالباً على قضية لتبدأ</div></div>
<?php else: foreach ($runs as $r): $pct = $r['total_tasks'] ? round($r['done_tasks'] / $r['total_tasks'] * 100) : 0; ?>
<div class="col-md-6 col-lg-4"><div class="card h-100"><div class="card-body">
  <div class="fw-bold"><?= e($r['case_number'] ?: '—') ?> <small class="text-muted"><?= e(mb_substr($r['case_title'] ?? '', 0, 40)) ?></small></div>
  <div class="text-muted mb-2" style="font-size:12px"><i class="fas fa-diagram-project me-1"></i><?= e($r['tpl_name'] ?: '—') ?> · بدأ <?= e($r['start_date']) ?><?= $r['asg_name'] ? ' · '.e($r['asg_name']) : '' ?></div>
  <div class="progress mb-1" style="height:8px"><div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div></div>
  <div style="font-size:12px"><?= (int)$r['done_tasks'] ?> من <?= (int)$r['total_tasks'] ?> مهمة مكتملة<?= $r['late_tasks'] ? ' <span class="text-danger fw-bold">· '.(int)$r['late_tasks'].' متأخرة</span>' : '' ?></div>
  <div class="mt-2" style="font-size:12px"><?= $r['judgment_date'] ? '<span class="badge bg-info bg-opacity-10 text-info">صدر الحكم: '.e($r['judgment_date']).'</span>' : '<span class="text-muted">لم يصدر الحكم بعد</span>' ?></div>
  <div class="d-flex gap-1 mt-3">
    <?php if (!$r['judgment_date']): ?><button class="btn btn-sm btn-outline-primary" onclick="jOpen(<?= (int)$r['id'] ?>)"><i class="fas fa-gavel me-1"></i>صدر الحكم</button><?php endif; ?>
    <a href="tasks.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-list-check me-1"></i>المهام</a>
    <?php if ($_canDel): ?><a href="case_workflows.php?delete_run=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف التشغيل ومهامه غير المنجزة؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </div>
</div></div></div>
<?php endforeach; endif; ?>
</div>

<?php else: ?>
<div class="alert alert-info" style="font-size:13px"><i class="fas fa-circle-info me-1"></i>المدد والخطوات الافتراضية للإرشاد فقط وقابلة للتعديل — تحقّق دائماً من المدد النظامية وتاريخ التبليغ قبل الاعتماد عليها.</div>
<?php if ($_canApprove): ?>
<form method="POST" class="card card-body mb-3"><input type="hidden" name="form_type" value="tpl">
  <div class="row g-2 align-items-end">
    <div class="col-md-5"><label class="form-label fw-semibold">قالب جديد</label><input name="name" class="form-control form-control-sm" placeholder="مثال: قضية إيجار" required></div>
    <div class="col-md-4"><label class="form-label fw-semibold">نوع القضية (اختياري)</label><input name="case_type" class="form-control form-control-sm"></div>
    <div class="col-md-3"><button class="btn btn-primary btn-sm w-100"><i class="fas fa-plus me-1"></i>إضافة قالب</button></div>
  </div>
</form>
<?php endif; ?>
<?php foreach ($templates as $t): ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center">
  <div class="fw-bold"><i class="fas fa-diagram-project me-1"></i><?= e($t['name']) ?> <?= $t['case_type'] ? '<span class="badge bg-light text-muted">'.e($t['case_type']).'</span>' : '' ?></div>
  <?php if ($_canApprove): ?><a href="case_workflows.php?del_tpl=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف القالب؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
</div>
<div class="table-responsive"><table class="table table-sm mb-0" style="font-size:13px">
  <thead><tr><th>الخطوة</th><th>المرجع</th><th>بعد (يوم)</th><th>الأولوية</th><?php if ($_canApprove): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($stepsBy[$t['id']] ?? [] as $s): ?>
  <tr><td><?= e($s['title']) ?></td><td><?= $s['anchor']==='judgment' ? 'صدور الحكم' : 'بدء القضية' ?></td><td><?= (int)$s['offset_days'] ?></td><td><?= $_priMap[$s['priority']] ?? '' ?></td>
    <?php if ($_canApprove): ?><td><a href="case_workflows.php?del_step=<?= (int)$s['id'] ?>" class="text-danger" onclick="return confirm('حذف الخطوة؟')"><i class="fas fa-xmark"></i></a></td><?php endif; ?></tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php if ($_canApprove): ?>
<form method="POST" class="card-body border-top"><input type="hidden" name="form_type" value="step"><input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
  <div class="row g-2 align-items-end">
    <div class="col-md-5"><input name="title" class="form-control form-control-sm" placeholder="خطوة جديدة" required></div>
    <div class="col-md-2"><select name="anchor" class="form-select form-select-sm"><option value="start">من بدء القضية</option><option value="judgment">من صدور الحكم</option></select></div>
    <div class="col-md-2"><input type="number" name="offset_days" class="form-control form-control-sm" min="0" value="0" title="عدد الأيام"></div>
    <div class="col-md-2"><select name="priority" class="form-select form-select-sm"><?php foreach ($_priMap as $k => $v): ?><option value="<?= $k ?>" <?= $k==='medium'?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="col-md-1"><button class="btn btn-outline-primary btn-sm w-100"><i class="fas fa-plus"></i></button></div>
  </div>
</form>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="modal fade" id="applyModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">تطبيق قالب على قضية</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="apply">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">القضية *</label><select name="case_id" class="form-select" required><option value="">— اختر —</option>
        <?php foreach ($cases_arr as $cid => $cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?></select></div>
      <div class="mb-3"><label class="form-label fw-semibold">القالب *</label><select name="template_id" class="form-select" required>
        <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
      <div class="row g-3"><div class="col-md-6"><label class="form-label fw-semibold">تاريخ البدء</label><input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">إسناد المهام إلى</label><select name="assigned_to" class="form-select">
          <?php foreach ($_assignable as $auid => $aun): ?><option value="<?= $auid ?>" <?= $auid==$uid?'selected':'' ?>><?= e($aun) ?></option><?php endforeach; ?></select></div></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary"><i class="fas fa-play me-1"></i>تطبيق</button></div>
  </form>
</div></div></div>

<div class="modal fade" id="judgModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">صدور الحكم</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="judgment"><input type="hidden" name="run_id" id="j_run">
    <div class="modal-body"><label class="form-label fw-semibold">تاريخ الحكم / التبليغ به</label><input type="date" name="judgment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
      <div class="form-text">تُنشأ مهام الاعتراض والتنفيذ بمواعيدها من هذا التاريخ.</div></div>
    <div class="modal-footer"><button class="btn btn-primary w-100">تسجيل</button></div>
  </form>
</div></div></div>
<script>function jOpen(id){document.getElementById('j_run').value=id;new bootstrap.Modal(document.getElementById('judgModal')).show();}</script>

<?php include '../includes/office_footer.php'; ?>
