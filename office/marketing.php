<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('marketing','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'marketing')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'إدارة التسويق';

/* ── إنشاء الجداول ذاتياً ── */
$conn->query("CREATE TABLE IF NOT EXISTS mkt_channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    platform ENUM('social','email','sms','whatsapp','website','event','other') DEFAULT 'other',
    handle VARCHAR(255) DEFAULT NULL,
    followers_count INT DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS mkt_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    channel_id INT DEFAULT NULL,
    goal VARCHAR(255) DEFAULT NULL,
    budget DECIMAL(10,2) DEFAULT 0,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    status ENUM('planned','active','paused','completed') DEFAULT 'planned',
    notes TEXT,
    assigned_to INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $conn->query("ALTER TABLE mkt_campaigns ADD COLUMN assigned_to INT DEFAULT NULL"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE mkt_campaigns ADD COLUMN created_by INT DEFAULT NULL"); } catch (\Throwable $e) {}

$conn->query("CREATE TABLE IF NOT EXISTS mkt_content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    type ENUM('post','video','article','design','other') DEFAULT 'post',
    campaign_id INT DEFAULT NULL,
    channel_id INT DEFAULT NULL,
    publish_date DATE DEFAULT NULL,
    status ENUM('draft','scheduled','published') DEFAULT 'draft',
    link VARCHAR(500) DEFAULT NULL,
    notes TEXT,
    assigned_to INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $conn->query("ALTER TABLE mkt_content ADD COLUMN assigned_to INT DEFAULT NULL"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE mkt_content ADD COLUMN created_by INT DEFAULT NULL"); } catch (\Throwable $e) {}

$conn->query("CREATE TABLE IF NOT EXISTS mkt_leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    full_name VARCHAR(200) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    campaign_id INT DEFAULT NULL,
    channel_id INT DEFAULT NULL,
    status ENUM('new','contacted','qualified','converted','lost') DEFAULT 'new',
    assigned_to INT DEFAULT NULL,
    notes TEXT,
    converted_client_id INT DEFAULT NULL,
    converted_at TIMESTAMP NULL DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $conn->query("ALTER TABLE mkt_leads ADD COLUMN created_by INT DEFAULT NULL"); } catch (\Throwable $e) {}

$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('marketing','add'); $_canEdit = can('marketing','edit'); $_canDel = can('marketing','delete');
$_canApprove = can('marketing','approve'); // مستوى المدير: يشوف كل شي، يعتمد تفعيل الحملات وتحويل الفرص لعملاء
$_canFin = can('invoices','view') && can('finance','view');

/* ══════════ حذف ══════════ */
if (isset($_GET['del_channel']) && $_canDel) {
    $conn->query("DELETE FROM mkt_channels WHERE id=".(int)$_GET['del_channel']." AND office_id=$oid");
    header("Location: marketing.php?tab=channels&msg=deleted"); exit;
}
if (isset($_GET['del_campaign']) && $_canDel) {
    $conn->query("DELETE FROM mkt_campaigns WHERE id=".(int)$_GET['del_campaign']." AND office_id=$oid");
    header("Location: marketing.php?tab=campaigns&msg=deleted"); exit;
}
if (isset($_GET['del_content']) && $_canDel) {
    $conn->query("DELETE FROM mkt_content WHERE id=".(int)$_GET['del_content']." AND office_id=$oid");
    header("Location: marketing.php?tab=content&msg=deleted"); exit;
}
if (isset($_GET['del_lead']) && $_canDel) {
    $conn->query("DELETE FROM mkt_leads WHERE id=".(int)$_GET['del_lead']." AND office_id=$oid");
    header("Location: marketing.php?tab=leads&msg=deleted"); exit;
}

/* ══════════ تحويل فرصة إلى عميل — يتطلب مستوى الاعتماد (مدير) ══════════ */
if (isset($_GET['convert_lead']) && $_canApprove && can('clients','add')) {
    $lid = (int)$_GET['convert_lead'];
    $lead = $conn->query("SELECT * FROM mkt_leads WHERE id=$lid AND office_id=$oid LIMIT 1")->fetch_assoc();
    if ($lead && !$lead['converted_client_id'] && canAddMore($conn, $oid, 'clients')) {
        $nm = $conn->real_escape_string($lead['full_name']);
        $ph = $conn->real_escape_string($lead['phone'] ?? '');
        $em = $conn->real_escape_string($lead['email'] ?? '');
        $conn->query("INSERT INTO clients (office_id,full_name,phone,email,client_type) VALUES ($oid,'$nm','$ph','$em','individual')");
        $new_cid = (int)$conn->insert_id;
        $conn->query("UPDATE mkt_leads SET status='converted', converted_client_id=$new_cid, converted_at=NOW() WHERE id=$lid AND office_id=$oid");
        header("Location: client_file.php?id=$new_cid"); exit;
    }
    header("Location: marketing.php?tab=leads&msg=denied"); exit;
}

/* ══════════ حفظ قناة ══════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'channel') {
    $cid = (int)($_POST['id'] ?? 0);
    requirePerm('marketing', $cid ? 'edit' : 'add', 'marketing.php?tab=channels&msg=denied');
    $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
    $plat = in_array($_POST['platform'] ?? '', ['social','email','sms','whatsapp','website','event','other'], true) ? $_POST['platform'] : 'other';
    $handle = $conn->real_escape_string(trim($_POST['handle'] ?? ''));
    $foll = max(0, (int)($_POST['followers_count'] ?? 0));
    $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    if ($cid) {
        $conn->query("UPDATE mkt_channels SET name='$name',platform='$plat',handle='$handle',followers_count=$foll,notes='$notes' WHERE id=$cid AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO mkt_channels (office_id,name,platform,handle,followers_count,notes) VALUES ($oid,'$name','$plat','$handle',$foll,'$notes')");
    }
    header("Location: marketing.php?tab=channels&msg=saved"); exit;
}

/* ══════════ حفظ حملة ══════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'campaign') {
    $cid = (int)($_POST['id'] ?? 0);
    requirePerm('marketing', $cid ? 'edit' : 'add', 'marketing.php?tab=campaigns&msg=denied');
    // موظف عادي (بلا صلاحية اعتماد) لا يملك على قضية ليست له إلا إذا كانت مُسندة له
    if ($cid && !$_canApprove) {
        $own = $conn->query("SELECT id FROM mkt_campaigns WHERE id=$cid AND office_id=$oid AND (assigned_to=$uid OR created_by=$uid)")->num_rows;
        if (!$own) { header("Location: marketing.php?tab=campaigns&msg=denied"); exit; }
    }
    $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
    $ch = !empty($_POST['channel_id']) ? (int)$_POST['channel_id'] : 'NULL';
    $goal = $conn->real_escape_string(trim($_POST['goal'] ?? ''));
    $budget = max(0, (float)($_POST['budget'] ?? 0));
    $sd = trim($_POST['start_date'] ?? '') !== '' ? "'".$conn->real_escape_string($_POST['start_date'])."'" : 'NULL';
    $ed = trim($_POST['end_date'] ?? '') !== '' ? "'".$conn->real_escape_string($_POST['end_date'])."'" : 'NULL';
    $status = in_array($_POST['status'] ?? '', ['planned','active','paused','completed'], true) ? $_POST['status'] : 'planned';
    // تفعيل الحملة (active) قرار مدير — نمنع فقط الانتقال الجديد لـ"نشطة"، لا نُلغي تفعيلاً قائماً أصلاً عند تعديل موظف عادي لحقل آخر
    if ($status === 'active' && !$_canApprove) {
        $prevStatus = $cid ? ($conn->query("SELECT status FROM mkt_campaigns WHERE id=$cid AND office_id=$oid")->fetch_assoc()['status'] ?? '') : '';
        if ($prevStatus !== 'active') $status = 'planned';
    }
    $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    $assigned = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : ($_canApprove ? 'NULL' : $uid);
    if ($cid) {
        $conn->query("UPDATE mkt_campaigns SET name='$name',channel_id=$ch,goal='$goal',budget=$budget,start_date=$sd,end_date=$ed,status='$status',notes='$notes',assigned_to=$assigned WHERE id=$cid AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO mkt_campaigns (office_id,name,channel_id,goal,budget,start_date,end_date,status,notes,assigned_to,created_by) VALUES ($oid,'$name',$ch,'$goal',$budget,$sd,$ed,'$status','$notes',$assigned,$uid)");
    }
    header("Location: marketing.php?tab=campaigns&msg=saved"); exit;
}

/* ══════════ حفظ محتوى ══════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'content') {
    $cid = (int)($_POST['id'] ?? 0);
    requirePerm('marketing', $cid ? 'edit' : 'add', 'marketing.php?tab=content&msg=denied');
    if ($cid && !$_canApprove) {
        $own = $conn->query("SELECT id FROM mkt_content WHERE id=$cid AND office_id=$oid AND (assigned_to=$uid OR created_by=$uid)")->num_rows;
        if (!$own) { header("Location: marketing.php?tab=content&msg=denied"); exit; }
    }
    $title = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $type = in_array($_POST['type'] ?? '', ['post','video','article','design','other'], true) ? $_POST['type'] : 'post';
    $camp = !empty($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 'NULL';
    $ch = !empty($_POST['channel_id']) ? (int)$_POST['channel_id'] : 'NULL';
    $pd = trim($_POST['publish_date'] ?? '') !== '' ? "'".$conn->real_escape_string($_POST['publish_date'])."'" : 'NULL';
    $status = in_array($_POST['status'] ?? '', ['draft','scheduled','published'], true) ? $_POST['status'] : 'draft';
    $link = $conn->real_escape_string(trim($_POST['link'] ?? ''));
    $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    $assigned = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : ($_canApprove ? 'NULL' : $uid);
    if ($cid) {
        $conn->query("UPDATE mkt_content SET title='$title',type='$type',campaign_id=$camp,channel_id=$ch,publish_date=$pd,status='$status',link='$link',notes='$notes',assigned_to=$assigned WHERE id=$cid AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO mkt_content (office_id,title,type,campaign_id,channel_id,publish_date,status,link,notes,assigned_to,created_by) VALUES ($oid,'$title','$type',$camp,$ch,$pd,'$status','$link','$notes',$assigned,$uid)");
    }
    header("Location: marketing.php?tab=content&msg=saved"); exit;
}

/* ══════════ حفظ فرصة تسويقية ══════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'lead') {
    $lid = (int)($_POST['id'] ?? 0);
    requirePerm('marketing', $lid ? 'edit' : 'add', 'marketing.php?tab=leads&msg=denied');
    if ($lid && !$_canApprove) {
        $own = $conn->query("SELECT id FROM mkt_leads WHERE id=$lid AND office_id=$oid AND (assigned_to=$uid OR created_by=$uid)")->num_rows;
        if (!$own) { header("Location: marketing.php?tab=leads&msg=denied"); exit; }
    }
    $name = $conn->real_escape_string(trim($_POST['full_name'] ?? ''));
    $phone = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
    $email = $conn->real_escape_string(trim($_POST['email'] ?? ''));
    $camp = !empty($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 'NULL';
    $ch = !empty($_POST['channel_id']) ? (int)$_POST['channel_id'] : 'NULL';
    $status = in_array($_POST['status'] ?? '', ['new','contacted','qualified','converted','lost'], true) ? $_POST['status'] : 'new';
    // موظف عادي لا يقدر يفرّغ إسناد الفرصة (لتبقى مرئية له) — المدير فقط يقدر يسندها لأي أحد أو يتركها بدون
    $assigned = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : ($_canApprove ? 'NULL' : $uid);
    $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    if ($lid) {
        // لا نغيّر status إلى/من converted هنا — التحويل الفعلي يمر فقط عبر رابط convert_lead
        $conn->query("UPDATE mkt_leads SET full_name='$name',phone='$phone',email='$email',campaign_id=$camp,channel_id=$ch,
            status=IF(converted_client_id IS NOT NULL,'converted','$status'),assigned_to=$assigned,notes='$notes' WHERE id=$lid AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO mkt_leads (office_id,full_name,phone,email,campaign_id,channel_id,status,assigned_to,notes,created_by) VALUES ($oid,'$name','$phone','$email',$camp,$ch,'$status',$assigned,'$notes',$uid)");
    }
    header("Location: marketing.php?tab=leads&msg=saved"); exit;
}

/* ── قوائم مساعدة (قنوات/حملات/موظفون) ── */
$channels_arr = [];
$chr = $conn->query("SELECT id,name FROM mkt_channels WHERE office_id=$oid ORDER BY name");
if ($chr) while ($r = $chr->fetch_assoc()) $channels_arr[$r['id']] = $r['name'];

$campaigns_arr = [];
$cpr = $conn->query("SELECT id,name FROM mkt_campaigns WHERE office_id=$oid ORDER BY id DESC");
if ($cpr) while ($r = $cpr->fetch_assoc()) $campaigns_arr[$r['id']] = $r['name'];

$officeUsers = [];
$our = $conn->query("SELECT id,full_name FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
if ($our) while ($r = $our->fetch_assoc()) $officeUsers[$r['id']] = $r['full_name'];
// موظف عادي لا يقدر يسند شي لغيره — الإسناد لأشخاص آخرين قرار مدير فقط
$_assignableUsers = $_canApprove ? $officeUsers : array_intersect_key($officeUsers, [$uid => true]);

/* ── تحميل بيانات كل تبويب ──
   موظف عادي (بلا صلاحية اعتماد) يشوف فقط ما يخصّه (مُسند له أو أضافه بنفسه) —
   المدير (صلاحية اعتماد) يشوف كل شي. القنوات تبقى مشتركة للكل دائماً. ── */
$_ownScope = !$_canApprove ? " AND (assigned_to=$uid OR created_by=$uid)" : "";

$channels = []; $cr2 = $conn->query("SELECT * FROM mkt_channels WHERE office_id=$oid ORDER BY id DESC");
if ($cr2) while ($r = $cr2->fetch_assoc()) $channels[] = $r;

$campaigns = []; $cp2 = $conn->query("SELECT c.*, ch.name channel_name, u.full_name assigned_name FROM mkt_campaigns c
    LEFT JOIN mkt_channels ch ON c.channel_id=ch.id LEFT JOIN users u ON c.assigned_to=u.id
    WHERE c.office_id=$oid$_ownScope ORDER BY c.id DESC");
if ($cp2) while ($r = $cp2->fetch_assoc()) $campaigns[] = $r;

$content = []; $co2 = $conn->query("SELECT co.*, cp.name campaign_name, ch.name channel_name, u.full_name assigned_name FROM mkt_content co
    LEFT JOIN mkt_campaigns cp ON co.campaign_id=cp.id LEFT JOIN mkt_channels ch ON co.channel_id=ch.id LEFT JOIN users u ON co.assigned_to=u.id
    WHERE co.office_id=$oid$_ownScope ORDER BY co.id DESC");
if ($co2) while ($r = $co2->fetch_assoc()) $content[] = $r;

$leads = []; $ld2 = $conn->query("SELECT l.*, cp.name campaign_name, ch.name channel_name, u.full_name assigned_name, cl.full_name client_name
    FROM mkt_leads l LEFT JOIN mkt_campaigns cp ON l.campaign_id=cp.id LEFT JOIN mkt_channels ch ON l.channel_id=ch.id
    LEFT JOIN users u ON l.assigned_to=u.id LEFT JOIN clients cl ON l.converted_client_id=cl.id
    WHERE l.office_id=$oid$_ownScope ORDER BY l.id DESC");
if ($ld2) while ($r = $ld2->fetch_assoc()) $leads[] = $r;

/* ── إحصائيات للنظرة العامة ── */
$leads_by_status = ['new'=>0,'contacted'=>0,'qualified'=>0,'converted'=>0,'lost'=>0];
foreach ($leads as $l) { if (isset($leads_by_status[$l['status']])) $leads_by_status[$l['status']]++; }
$total_leads = count($leads);
$conv_rate = $total_leads > 0 ? round($leads_by_status['converted'] / $total_leads * 100) : 0;
$active_campaigns = count(array_filter($campaigns, fn($c) => $c['status']==='active'));
$total_budget = array_sum(array_column($campaigns, 'budget'));
$published_content = count(array_filter($content, fn($c) => $c['status']==='published'));

/* ── قياس النتائج: الإيراد المنسوب لكل حملة عبر العملاء المُحوَّلين ── */
$campaign_revenue = [];
if ($_canFin) {
    foreach ($campaigns as $cp) {
        $cids = [];
        foreach ($leads as $l) if ((int)($l['campaign_id'] ?? 0) === (int)$cp['id'] && $l['converted_client_id']) $cids[] = (int)$l['converted_client_id'];
        $rev = 0.0;
        if ($cids) {
            $idsList = implode(',', $cids);
            $rr = $conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE client_id IN ($idsList) AND office_id=$oid AND direction!='expense'");
            $rev = $rr ? (float)$rr->fetch_assoc()['s'] : 0.0;
        }
        $campaign_revenue[$cp['id']] = $rev;
    }
}

$_tabs = [
    'overview'  => 'نظرة عامة',
    'campaigns' => 'الحملات (' . count($campaigns) . ')',
    'content'   => 'المحتوى الإعلامي (' . count($content) . ')',
    'channels'  => 'القنوات والتواصل (' . count($channels) . ')',
    'leads'     => 'الفرص التسويقية (' . $total_leads . ')',
    'results'   => 'قياس النتائج والمبيعات',
];
$tab = $_GET['tab'] ?? 'overview';
if (!isset($_tabs[$tab])) $tab = 'overview';

$_platMap = ['social'=>'اجتماعي','email'=>'بريد','sms'=>'SMS','whatsapp'=>'واتساب','website'=>'موقع','event'=>'فعالية','other'=>'أخرى'];
$_typeMap = ['post'=>'منشور','video'=>'فيديو','article'=>'مقال','design'=>'تصميم','other'=>'أخرى'];

function mktBadge($label, $color) { return "<span class='badge bg-{$color} bg-opacity-10 text-{$color}'>{$label}</span>"; }
$_campStatusMap = ['planned'=>['مخطَّطة','secondary'],'active'=>['نشطة','primary'],'paused'=>['متوقفة مؤقتاً','warning'],'completed'=>['منتهية','success']];
$_contStatusMap = ['draft'=>['مسودة','secondary'],'scheduled'=>['مجدول','info'],'published'=>['منشور','success']];
$_leadStatusMap = ['new'=>['جديدة','danger'],'contacted'=>['تم التواصل','info'],'qualified'=>['مؤهّلة','warning'],'converted'=>['محوَّلة','success'],'lost'=>['خسرناها','secondary']];

include '../includes/office_header.php';
?>

<style>
.mkt-stat{background:#fff;border:1px solid #eef1f6;border-radius:14px;padding:16px;height:100%;display:flex;align-items:center;gap:12px}
.mkt-stat-ico{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;flex-shrink:0}
.mkt-funnel-bar{height:26px;border-radius:6px;display:flex;align-items:center;padding:0 10px;color:#fff;font-size:11px;font-weight:700;white-space:nowrap}
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <div class="mk-page-title"><i class="fas fa-bullhorn"></i> إدارة التسويق</div>
    <div class="mk-page-sub">حملات، محتوى، قنوات، فرص وعملاء محتملون، وتحليلات النتائج</div>
  </div>
</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<ul class="nav nav-pills mb-3 flex-wrap">
  <?php foreach ($_tabs as $tk => $tl): ?>
  <li class="nav-item"><a class="nav-link <?= $tab===$tk?'active':'' ?>" href="marketing.php?tab=<?= $tk ?>"><?= $tl ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): ?>
<div class="row g-3 mb-3">
  <?php foreach ([
    ['الحملات النشطة', $active_campaigns, 'rocket', '#2563eb'],
    ['إجمالي الميزانيات', number_format($total_budget,0).' ر.س', 'coins', '#d97706'],
    ['محتوى منشور', $published_content, 'photo-film', '#7c3aed'],
    ['الفرص التسويقية', $total_leads, 'user-plus', '#0891b2'],
    ['معدّل التحويل', $conv_rate.'%', 'percent', '#16a34a'],
  ] as [$lbl,$val,$ic,$col]): ?>
  <div class="col-6 col-lg">
    <div class="mkt-stat">
      <div class="mkt-stat-ico" style="background:<?= $col ?>"><i class="fas fa-<?= $ic ?>"></i></div>
      <div><div class="fw-bold" style="font-size:17px"><?= $val ?></div><div class="text-muted" style="font-size:11px"><?= $lbl ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header"><i class="fas fa-filter me-2 text-primary"></i>قمع الفرص التسويقية (Funnel)</div>
  <div class="card-body">
    <?php
    $funnelColors = ['new'=>'#94a3b8','contacted'=>'#3b82f6','qualified'=>'#f59e0b','converted'=>'#16a34a','lost'=>'#dc2626'];
    $funnelLabels = ['new'=>'جديدة','contacted'=>'تم التواصل','qualified'=>'مؤهّلة','converted'=>'محوَّلة لعميل','lost'=>'خسرناها'];
    foreach ($leads_by_status as $st => $cnt):
      $pct = $total_leads > 0 ? max(6, round($cnt / $total_leads * 100)) : 0;
    ?>
    <div class="d-flex align-items-center gap-2 mb-2">
      <div style="width:110px;font-size:12px;color:#475569"><?= $funnelLabels[$st] ?></div>
      <div class="flex-grow-1" style="background:#f1f5f9;border-radius:6px">
        <div class="mkt-funnel-bar" style="width:<?= $cnt>0?$pct:2 ?>%;background:<?= $funnelColors[$st] ?>"><?= $cnt ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$total_leads): ?><div class="text-muted text-center py-3">لا توجد فرص تسويقية بعد</div><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'campaigns'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="fw-semibold">الحملات التسويقية</div>
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#campModal" onclick="campNew()"><i class="fas fa-plus me-1"></i>حملة جديدة</button>
  <?php endif; ?>
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>الاسم</th><th>القناة</th><th>الهدف</th><th>الميزانية</th><th>الحالة</th><th>المسؤول</th><th>البداية</th><th>النهاية</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$campaigns): ?><tr><td colspan="9" class="text-center text-muted py-4">لا توجد حملات بعد</td></tr>
<?php else: foreach ($campaigns as $c): ?>
<tr>
  <td class="fw-semibold"><?= e($c['name']) ?></td>
  <td><?= e($c['channel_name'] ?: '—') ?></td>
  <td style="font-size:12px"><?= e($c['goal'] ?: '—') ?></td>
  <td><?= number_format((float)$c['budget'],0) ?> ر.س</td>
  <td><?= mktBadge($_campStatusMap[$c['status']][0] ?? $c['status'], $_campStatusMap[$c['status']][1] ?? 'secondary') ?></td>
  <td style="font-size:12px"><?= e($c['assigned_name'] ?: '—') ?></td>
  <td style="font-size:12px"><?= $c['start_date'] ? dDate($c['start_date']) : '—' ?></td>
  <td style="font-size:12px"><?= $c['end_date'] ? dDate($c['end_date']) : '—' ?></td>
  <td>
    <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='campEdit(<?= json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
    <?php if ($_canDel): ?><a href="marketing.php?tab=campaigns&del_campaign=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذه الحملة؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php if ($tab === 'content'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="fw-semibold">المحتوى الإعلامي</div>
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#contModal" onclick="contNew()"><i class="fas fa-plus me-1"></i>محتوى جديد</button>
  <?php endif; ?>
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>العنوان</th><th>النوع</th><th>الحملة</th><th>القناة</th><th>الحالة</th><th>المسؤول</th><th>تاريخ النشر</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$content): ?><tr><td colspan="8" class="text-center text-muted py-4">لا يوجد محتوى بعد</td></tr>
<?php else: foreach ($content as $c): ?>
<tr>
  <td class="fw-semibold"><?= e($c['title']) ?><?php if ($c['link']): ?> <a href="<?= e($c['link']) ?>" target="_blank" class="ms-1"><i class="fas fa-up-right-from-square" style="font-size:11px"></i></a><?php endif; ?></td>
  <td><?= $_typeMap[$c['type']] ?? $c['type'] ?></td>
  <td><?= e($c['campaign_name'] ?: '—') ?></td>
  <td><?= e($c['channel_name'] ?: '—') ?></td>
  <td><?= mktBadge($_contStatusMap[$c['status']][0] ?? $c['status'], $_contStatusMap[$c['status']][1] ?? 'secondary') ?></td>
  <td style="font-size:12px"><?= e($c['assigned_name'] ?: '—') ?></td>
  <td style="font-size:12px"><?= $c['publish_date'] ? dDate($c['publish_date']) : '—' ?></td>
  <td>
    <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='contEdit(<?= json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
    <?php if ($_canDel): ?><a href="marketing.php?tab=content&del_content=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا المحتوى؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php if ($tab === 'channels'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="fw-semibold">القنوات والتواصل</div>
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#chanModal" onclick="chanNew()"><i class="fas fa-plus me-1"></i>قناة جديدة</button>
  <?php endif; ?>
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>الاسم</th><th>النوع</th><th>الحساب/الرابط</th><th>المتابعون</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$channels): ?><tr><td colspan="5" class="text-center text-muted py-4">لا توجد قنوات مسجّلة بعد</td></tr>
<?php else: foreach ($channels as $c): ?>
<tr>
  <td class="fw-semibold"><?= e($c['name']) ?></td>
  <td><?= $_platMap[$c['platform']] ?? $c['platform'] ?></td>
  <td style="font-size:12px" class="font-monospace"><?= e($c['handle'] ?: '—') ?></td>
  <td><?= number_format((int)$c['followers_count']) ?></td>
  <td>
    <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='chanEdit(<?= json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
    <?php if ($_canDel): ?><a href="marketing.php?tab=channels&del_channel=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذه القناة؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php if ($tab === 'leads'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="fw-semibold">الفرص التسويقية / العملاء المحتملون</div>
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#leadModal" onclick="leadNew()"><i class="fas fa-plus me-1"></i>فرصة جديدة</button>
  <?php endif; ?>
</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>الاسم</th><th>التواصل</th><th>المصدر</th><th>الحالة</th><th>المسؤول</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$leads): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد فرص تسويقية بعد</td></tr>
<?php else: foreach ($leads as $l): ?>
<tr>
  <td class="fw-semibold"><?= e($l['full_name']) ?><?php if ($l['client_name']): ?><br><small class="text-success"><i class="fas fa-user-check me-1"></i><?= e($l['client_name']) ?></small><?php endif; ?></td>
  <td style="font-size:12px"><?= e($l['phone'] ?: '') ?><?php if ($l['email']): ?><br><?= e($l['email']) ?><?php endif; ?></td>
  <td style="font-size:12px"><?= e($l['campaign_name'] ?: $l['channel_name'] ?: '—') ?></td>
  <td><?= mktBadge($_leadStatusMap[$l['status']][0] ?? $l['status'], $_leadStatusMap[$l['status']][1] ?? 'secondary') ?></td>
  <td style="font-size:12px"><?= e($l['assigned_name'] ?: '—') ?></td>
  <td class="d-flex gap-1">
    <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='leadEdit(<?= json_encode($l, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
    <?php if ($_canApprove && can('clients','add') && !$l['converted_client_id']): ?>
    <a href="marketing.php?convert_lead=<?= $l['id'] ?>" class="btn btn-sm btn-outline-success" title="تحويل لعميل" onclick="return confirm('تحويل هذه الفرصة إلى عميل جديد؟')"><i class="fas fa-user-check"></i></a>
    <?php elseif ($l['status']==='qualified' && !$l['converted_client_id']): ?>
    <span class="badge bg-light text-muted" style="font-size:10px" title="يحتاج اعتماد مدير للتحويل">بانتظار الاعتماد</span>
    <?php endif; ?>
    <?php if ($_canDel): ?><a href="marketing.php?tab=leads&del_lead=<?= $l['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذه الفرصة؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php if ($tab === 'results'): ?>
<div class="fw-semibold mb-3">قياس النتائج والمبيعات — عائد كل حملة</div>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>الحملة</th><th>الميزانية</th><th>عدد الفرص</th><th>المحوَّلة لعملاء</th><th>معدّل التحويل</th><th>الإيراد المنسوب</th><th>العائد (ROI)</th></tr></thead>
<tbody>
<?php if (!$campaigns): ?><tr><td colspan="7" class="text-center text-muted py-4">لا توجد حملات لقياسها بعد</td></tr>
<?php else: foreach ($campaigns as $cp):
  $campLeads = array_filter($leads, fn($l) => (int)($l['campaign_id'] ?? 0) === (int)$cp['id']);
  $cCount = count($campLeads);
  $cConv = count(array_filter($campLeads, fn($l) => $l['status']==='converted'));
  $cRate = $cCount > 0 ? round($cConv / $cCount * 100) : 0;
  $rev = $campaign_revenue[$cp['id']] ?? null;
  $roi = ($_canFin && $rev !== null && (float)$cp['budget'] > 0) ? round((($rev - (float)$cp['budget']) / (float)$cp['budget']) * 100) : null;
?>
<tr>
  <td class="fw-semibold"><?= e($cp['name']) ?></td>
  <td><?= number_format((float)$cp['budget'],0) ?> ر.س</td>
  <td><?= $cCount ?></td>
  <td><?= $cConv ?></td>
  <td><?= $cRate ?>%</td>
  <td>
    <?php if ($_canFin): ?><?= number_format($rev,0) ?> ر.س<?php else: ?><span class="text-muted">—</span><?php endif; ?>
  </td>
  <td>
    <?php if ($roi !== null): ?>
      <span class="fw-bold" style="color:<?= $roi>=0?'#16a34a':'#dc2626' ?>"><?= $roi>=0?'+':'' ?><?= $roi ?>%</span>
    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>
<?php if (!$_canFin): ?>
<div class="alert alert-info mt-3 mb-0" style="font-size:12px"><i class="fas fa-circle-info me-1"></i>يحتاج عرض الإيراد المنسوب وحساب العائد صلاحية «الفواتير» و«الشؤون المالية» معاً.</div>
<?php endif; ?>
<?php endif; ?>

<!-- ═══ Modal: قناة ═══ -->
<div class="modal fade" id="chanModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="chanTitle"><i class="fas fa-tower-broadcast me-2"></i>قناة جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="channel"><input type="hidden" name="id" id="chan_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">اسم القناة *</label><input type="text" name="name" id="chan_name" class="form-control" required></div>
      <div class="mb-3"><label class="form-label fw-semibold">النوع</label>
        <select name="platform" id="chan_platform" class="form-select">
          <?php foreach ($_platMap as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">الحساب / الرابط</label><input type="text" name="handle" id="chan_handle" class="form-control" placeholder="@account أو رابط"></div>
      <div class="mb-3"><label class="form-label fw-semibold">عدد المتابعين</label><input type="number" name="followers_count" id="chan_foll" class="form-control" min="0" value="0"></div>
      <div class="mb-1"><label class="form-label fw-semibold">ملاحظات</label><textarea name="notes" id="chan_notes" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<!-- ═══ Modal: حملة ═══ -->
<div class="modal fade" id="campModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="campTitle"><i class="fas fa-rocket me-2"></i>حملة جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="campaign"><input type="hidden" name="id" id="camp_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">اسم الحملة *</label><input type="text" name="name" id="camp_name" class="form-control" required></div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">القناة</label>
          <select name="channel_id" id="camp_channel" class="form-select"><option value="">— بدون —</option>
            <?php foreach ($channels_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label fw-semibold">الحالة</label>
          <select name="status" id="camp_status" class="form-select">
            <option value="planned">مخطَّطة</option>
            <option value="active"><?= !$_canApprove?'نشطة (يحتاج اعتماد مدير)':'نشطة' ?></option>
            <option value="paused">متوقفة مؤقتاً</option><option value="completed">منتهية</option>
          </select>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">الهدف</label><input type="text" name="goal" id="camp_goal" class="form-control" placeholder="مثال: جذب 50 عميل جديد"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">المسؤول</label>
          <select name="assigned_to" id="camp_assigned" class="form-select"><option value="">— بدون —</option>
            <?php foreach ($_assignableUsers as $uid6=>$un): ?><option value="<?= $uid6 ?>"><?= e($un) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-4"><label class="form-label fw-semibold">الميزانية (ر.س)</label><input type="number" name="budget" id="camp_budget" class="form-control" min="0" step="0.01" value="0"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">تاريخ البداية</label><input type="date" name="start_date" id="camp_start" class="form-control"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">تاريخ النهاية</label><input type="date" name="end_date" id="camp_end" class="form-control"></div>
      </div>
      <div class="mb-1"><label class="form-label fw-semibold">ملاحظات</label><textarea name="notes" id="camp_notes" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<!-- ═══ Modal: محتوى ═══ -->
<div class="modal fade" id="contModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="contTitle"><i class="fas fa-photo-film me-2"></i>محتوى جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="content"><input type="hidden" name="id" id="cont_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">العنوان *</label><input type="text" name="title" id="cont_title" class="form-control" required></div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">النوع</label>
          <select name="type" id="cont_type" class="form-select">
            <?php foreach ($_typeMap as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label fw-semibold">الحالة</label>
          <select name="status" id="cont_status" class="form-select">
            <option value="draft">مسودة</option><option value="scheduled">مجدول</option><option value="published">منشور</option>
          </select>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">الحملة المرتبطة</label>
          <select name="campaign_id" id="cont_campaign" class="form-select"><option value="">— بدون —</option>
            <?php foreach ($campaigns_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label fw-semibold">القناة</label>
          <select name="channel_id" id="cont_channel" class="form-select"><option value="">— بدون —</option>
            <?php foreach ($channels_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">تاريخ النشر</label><input type="date" name="publish_date" id="cont_pdate" class="form-control"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">رابط</label><input type="text" name="link" id="cont_link" class="form-control" placeholder="https://..."></div>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">المسؤول</label>
        <select name="assigned_to" id="cont_assigned" class="form-select"><option value="">— بدون —</option>
          <?php foreach ($_assignableUsers as $uid7=>$un): ?><option value="<?= $uid7 ?>"><?= e($un) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-1"><label class="form-label fw-semibold">ملاحظات</label><textarea name="notes" id="cont_notes" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<!-- ═══ Modal: فرصة تسويقية ═══ -->
<div class="modal fade" id="leadModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="leadTitle"><i class="fas fa-user-plus me-2"></i>فرصة جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="lead"><input type="hidden" name="id" id="lead_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">الاسم *</label><input type="text" name="full_name" id="lead_name" class="form-control" required></div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">الجوال</label><input type="text" name="phone" id="lead_phone" class="form-control"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">البريد الإلكتروني</label><input type="email" name="email" id="lead_email" class="form-control"></div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">الحملة المصدر</label>
          <select name="campaign_id" id="lead_campaign" class="form-select"><option value="">— بدون —</option>
            <?php foreach ($campaigns_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label fw-semibold">القناة المصدر</label>
          <select name="channel_id" id="lead_channel" class="form-select"><option value="">— بدون —</option>
            <?php foreach ($channels_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">الحالة</label>
          <select name="status" id="lead_status" class="form-select">
            <option value="new">جديدة</option><option value="contacted">تم التواصل</option><option value="qualified">مؤهّلة</option><option value="lost">خسرناها</option>
          </select>
          <div class="form-text" id="lead_conv_note" style="display:none">هذه الفرصة تحوَّلت بالفعل لعميل — استخدم زر «تحويل لعميل» من الجدول لعمليات تحويل جديدة.</div>
        </div>
        <div class="col-md-6"><label class="form-label fw-semibold">المسؤول</label>
          <select name="assigned_to" id="lead_assigned" class="form-select"><option value="">— بدون —</option>
            <?php foreach ($_assignableUsers as $uid8=>$un): ?><option value="<?= $uid8 ?>"><?= e($un) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mb-1"><label class="form-label fw-semibold">ملاحظات</label><textarea name="notes" id="lead_notes" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<script>
var CUR_UID = <?= (int)$uid ?>;
var CAN_APPROVE = <?= $_canApprove ? 'true' : 'false' ?>;
function chanNew(){document.getElementById('chanTitle').innerHTML='<i class="fas fa-tower-broadcast me-2"></i>قناة جديدة';document.getElementById('chan_id').value='';document.getElementById('chan_name').value='';document.getElementById('chan_platform').value='social';document.getElementById('chan_handle').value='';document.getElementById('chan_foll').value='0';document.getElementById('chan_notes').value='';}
function chanEdit(c){document.getElementById('chanTitle').innerHTML='<i class="fas fa-edit me-2"></i>تعديل قناة';document.getElementById('chan_id').value=c.id;document.getElementById('chan_name').value=c.name;document.getElementById('chan_platform').value=c.platform;document.getElementById('chan_handle').value=c.handle||'';document.getElementById('chan_foll').value=c.followers_count||0;document.getElementById('chan_notes').value=c.notes||'';new bootstrap.Modal(document.getElementById('chanModal')).show();}

function campNew(){document.getElementById('campTitle').innerHTML='<i class="fas fa-rocket me-2"></i>حملة جديدة';document.getElementById('camp_id').value='';document.getElementById('camp_name').value='';document.getElementById('camp_channel').value='';document.getElementById('camp_status').value='planned';document.getElementById('camp_goal').value='';document.getElementById('camp_assigned').value=CAN_APPROVE?'':CUR_UID;document.getElementById('camp_budget').value='0';document.getElementById('camp_start').value='';document.getElementById('camp_end').value='';document.getElementById('camp_notes').value='';}
function campEdit(c){document.getElementById('campTitle').innerHTML='<i class="fas fa-edit me-2"></i>تعديل حملة';document.getElementById('camp_id').value=c.id;document.getElementById('camp_name').value=c.name;document.getElementById('camp_channel').value=c.channel_id||'';document.getElementById('camp_status').value=c.status;document.getElementById('camp_goal').value=c.goal||'';document.getElementById('camp_assigned').value=c.assigned_to||'';document.getElementById('camp_budget').value=c.budget;document.getElementById('camp_start').value=(c.start_date||'').slice(0,10);document.getElementById('camp_end').value=(c.end_date||'').slice(0,10);document.getElementById('camp_notes').value=c.notes||'';new bootstrap.Modal(document.getElementById('campModal')).show();}

function contNew(){document.getElementById('contTitle').innerHTML='<i class="fas fa-photo-film me-2"></i>محتوى جديد';document.getElementById('cont_id').value='';document.getElementById('cont_title').value='';document.getElementById('cont_type').value='post';document.getElementById('cont_status').value='draft';document.getElementById('cont_campaign').value='';document.getElementById('cont_channel').value='';document.getElementById('cont_pdate').value='';document.getElementById('cont_link').value='';document.getElementById('cont_assigned').value=CAN_APPROVE?'':CUR_UID;document.getElementById('cont_notes').value='';}
function contEdit(c){document.getElementById('contTitle').innerHTML='<i class="fas fa-edit me-2"></i>تعديل محتوى';document.getElementById('cont_id').value=c.id;document.getElementById('cont_title').value=c.title;document.getElementById('cont_type').value=c.type;document.getElementById('cont_status').value=c.status;document.getElementById('cont_campaign').value=c.campaign_id||'';document.getElementById('cont_channel').value=c.channel_id||'';document.getElementById('cont_pdate').value=(c.publish_date||'').slice(0,10);document.getElementById('cont_link').value=c.link||'';document.getElementById('cont_assigned').value=c.assigned_to||'';document.getElementById('cont_notes').value=c.notes||'';new bootstrap.Modal(document.getElementById('contModal')).show();}

function leadNew(){document.getElementById('leadTitle').innerHTML='<i class="fas fa-user-plus me-2"></i>فرصة جديدة';document.getElementById('lead_id').value='';document.getElementById('lead_name').value='';document.getElementById('lead_phone').value='';document.getElementById('lead_email').value='';document.getElementById('lead_campaign').value='';document.getElementById('lead_channel').value='';document.getElementById('lead_status').value='new';document.getElementById('lead_assigned').value=CAN_APPROVE?'':CUR_UID;document.getElementById('lead_notes').value='';document.getElementById('lead_conv_note').style.display='none';document.getElementById('lead_status').disabled=false;}
function leadEdit(l){document.getElementById('leadTitle').innerHTML='<i class="fas fa-edit me-2"></i>تعديل فرصة';document.getElementById('lead_id').value=l.id;document.getElementById('lead_name').value=l.full_name;document.getElementById('lead_phone').value=l.phone||'';document.getElementById('lead_email').value=l.email||'';document.getElementById('lead_campaign').value=l.campaign_id||'';document.getElementById('lead_channel').value=l.channel_id||'';document.getElementById('lead_notes').value=l.notes||'';document.getElementById('lead_assigned').value=l.assigned_to||'';
  var convNote = document.getElementById('lead_conv_note');
  var stSel = document.getElementById('lead_status');
  if (l.converted_client_id) { convNote.style.display=''; stSel.value='qualified'; stSel.disabled=true; }
  else { convNote.style.display='none'; stSel.disabled=false; stSel.value=l.status; }
  new bootstrap.Modal(document.getElementById('leadModal')).show();
}
</script>

<?php include '../includes/office_footer.php'; ?>
