<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (currentRole() !== 'office_owner') { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'إدارة المستخدمين';
$oid = (int)$_SESSION['office_id'];

foreach ([
    "ALTER TABLE users ADD COLUMN permissions TEXT DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN restricted_scope TINYINT(1) NOT NULL DEFAULT 0",
    "CREATE TABLE IF NOT EXISTS case_assignments (case_id INT NOT NULL, user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (case_id,user_id), INDEX idx_user (user_id))
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
] as $_d) { try { $conn->query($_d); } catch (\Throwable $e) {} }

$SECTIONS = [
    'cases'=>'القضايا','clients'=>'العملاء','sessions'=>'الجلسات','tasks'=>'المهام',
    'contracts'=>'العقود','poa'=>'الوكالات','correspondence'=>'الصادر والوارد',
    'finance'=>'الشؤون المالية','invoices'=>'الفواتير','library'=>'المكتبة',
    'archive'=>'الأرشيف','precedents'=>'السوابق القضائية','services'=>'الخدمات الرقمية',
    'users'=>'المستخدمون','reports'=>'التقارير',
];
// أقسام موديولات إضافية — أي موديول مُفعَّل فعلياً لهذا المكتب (سواء فعّله الأدمن أو اشتراه المكتب بنفسه
// من متجر الموديولات) يظهر تلقائياً كقسم صلاحية جديد، بدون أي تعديل يدوي هنا لكل موديول جديد
try {
    $modRes = $conn->query("SELECT m.module_key, m.name FROM office_modules om
        JOIN modules m ON om.module_key = m.module_key
           JOIN offices ofc ON ofc.id = om.office_id
        WHERE om.office_id=$oid AND om.is_enabled=1 AND (om.package_id IS NULL OR om.package_id=ofc.package_id)");
    if ($modRes) while ($mr = $modRes->fetch_assoc()) $SECTIONS[$mr['module_key']] = $mr['name'];
} catch (\Throwable $e) {}
$ACTIONS = ['view'=>'اطلاع','add'=>'إضافة','edit'=>'تعديل','delete'=>'حذف','approve'=>'اعتماد','export'=>'تقارير/تصدير'];

// صلاحيات مبدئية لمستخدم جديد (كلها قابلة للتعديل بالكامل قبل الحفظ)
$NEW_DEFAULT = [
    'cases'=>['view','add','edit'], 'clients'=>['view','add','edit'],
    'sessions'=>['view','add','edit'], 'tasks'=>['view','add','edit'],
    'contracts'=>['view'], 'poa'=>['view'], 'correspondence'=>['view','add'],
    'finance'=>[], 'invoices'=>[], 'library'=>['view'], 'archive'=>['view'],
    'precedents'=>['view'], 'services'=>[], 'users'=>[], 'reports'=>[], 'marketing'=>[],
];

/* ═══ حفظ (إضافة/تعديل) مستخدم مع صلاحياته ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uname = $conn->real_escape_string(trim($_POST['username'] ?? ''));
    $fname = $conn->real_escape_string(trim($_POST['full_name'] ?? ''));
    $email = $conn->real_escape_string(trim($_POST['email'] ?? ''));
    $phone = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
    $role  = in_array($_POST['role'] ?? '', ['office_owner','lawyer','secretary','trainee'], true) ? $_POST['role'] : 'lawyer';
    $target_uid = 0;

    if (!empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $sql = "UPDATE users SET username='$uname',full_name='$fname',email='$email',phone='$phone',role='$role'";
        if (!empty($_POST['password'])) {
            $pw = $_POST['password'];
            if (strlen($pw) < 8 || !preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw) || !preg_match('/[0-9]/', $pw) || !preg_match('/[^A-Za-z0-9]/', $pw)) {
                header("Location: users.php?err=weak_pass"); exit;
            }
            $sql .= ",password='" . password_hash($pw, PASSWORD_BCRYPT) . "'";
        }
        $conn->query("$sql WHERE id=$id AND office_id=$oid");
        $target_uid = $id;
    } else {
        $pw = $_POST['password'] ?? '';
        if ($pw === '') { header("Location: users.php?err=no_pass"); exit; }
        if (strlen($pw) < 8 || !preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw) || !preg_match('/[0-9]/', $pw) || !preg_match('/[^A-Za-z0-9]/', $pw)) {
            header("Location: users.php?err=weak_pass"); exit;
        }
        if (!canAddMore($conn, $oid, 'users')) { header("Location: users.php?err=limit"); exit; }
        $hash = password_hash($pw, PASSWORD_BCRYPT);
        $conn->query("INSERT INTO users (office_id,username,password,full_name,email,phone,role,is_active,permissions)
            VALUES ($oid,'$uname','$hash','$fname','$email','$phone','$role',1,'{}')");
        $target_uid = (int)$conn->insert_id;
    }

    if ($target_uid) {
        if ($role === 'office_owner') {
            $conn->query("UPDATE users SET permissions=NULL, restricted_scope=0 WHERE id=$target_uid AND office_id=$oid");
            $conn->query("DELETE FROM case_assignments WHERE user_id=$target_uid");
        } else {
            // مصفوفة الصلاحيات — تُقرأ حرفياً من مربعات النموذج
            $perms = [];
            foreach (array_keys($SECTIONS) as $sec) {
                $chosen = (array)($_POST['perm'][$sec] ?? []);
                $perms[$sec] = array_values(array_intersect(array_keys($ACTIONS), $chosen));
            }
            $pj = "'" . $conn->real_escape_string(json_encode($perms, JSON_UNESCAPED_UNICODE)) . "'";
            // كل موظف غير المالك مقيَّد تلقائياً بقضاياه المُسندة — لا خانة اختيارية لهذا
            $conn->query("UPDATE users SET permissions=$pj, restricted_scope=1 WHERE id=$target_uid AND office_id=$oid");

            $conn->query("DELETE FROM case_assignments WHERE user_id=$target_uid");
            foreach ((array)($_POST['cases'] ?? []) as $cid) {
                $cid = (int)$cid;
                if ($cid) $conn->query("INSERT IGNORE INTO case_assignments (case_id,user_id) SELECT id,$target_uid FROM cases WHERE id=$cid AND office_id=$oid");
            }
        }
        if (function_exists('logAction')) logAction($conn, empty($_POST['id']) ? 'create' : 'update', 'user', $target_uid, 'مستخدم: ' . $fname);
    }
    header("Location: users.php?msg=saved"); exit;
}

if (isset($_GET['toggle'])) {
    $conn->query("UPDATE users SET is_active = 1 - is_active WHERE id=".(int)$_GET['toggle']." AND office_id=$oid AND role<>'office_owner'");
    header("Location: users.php"); exit;
}
if (isset($_GET['delete'])) {
    $conn->query("DELETE FROM users WHERE id=".(int)$_GET['delete']." AND office_id=$oid AND role<>'office_owner'");
    header("Location: users.php?msg=deleted"); exit;
}

/* ── بيانات ── */
$users = $conn->query("SELECT * FROM users WHERE office_id=$oid ORDER BY FIELD(role,'office_owner') DESC, id");
$user_count = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE office_id=$oid AND is_active=1")->fetch_assoc()['c'];
$user_limit = getFeatureLimit($conn, $oid, 'max_users');

$edit = null;
if (isset($_GET['edit'])) $edit = $conn->query("SELECT * FROM users WHERE id=".(int)$_GET['edit']." AND office_id=$oid")->fetch_assoc();

// صلاحيات لعرضها داخل النموذج
$formPerms = $NEW_DEFAULT;
if ($edit) {
    $dp = !empty($edit['permissions']) ? json_decode($edit['permissions'], true) : null;
    $formPerms = is_array($dp) ? $dp : [];
}
$formAssigned = [];
if ($edit) {
    $ar = $conn->query("SELECT case_id FROM case_assignments WHERE user_id=".(int)$edit['id']);
    if ($ar) while ($x = $ar->fetch_assoc()) $formAssigned[(int)$x['case_id']] = 1;
}
$officeCases = [];
$cq = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid ORDER BY id DESC");
if ($cq) while ($x = $cq->fetch_assoc()) $officeCases[] = $x;

// ملخص صلاحيات كل مستخدم للبطاقة
function perm_summary($json, $sections) {
    $p = json_decode($json ?: '{}', true);
    if (!is_array($p)) return [];
    $out = [];
    foreach ($sections as $k => $lbl) if (!empty($p[$k])) $out[] = $lbl;
    return $out;
}

include '../includes/office_header.php';
?>

<style>
.um-matrix{width:100%;border-collapse:collapse;font-size:12.5px}
.um-matrix th{background:var(--mk-bg,#f1f5f9);color:var(--mk-t3,#475569);font-weight:700;padding:8px 6px;text-align:center;border:1px solid var(--mk-border,#e2e8f0);white-space:nowrap}
.um-matrix th:first-child{text-align:right;min-width:130px}
.um-matrix td{padding:7px 6px;text-align:center;border:1px solid var(--mk-bg2,#eef2f7)}
.um-matrix td:first-child{text-align:right;font-weight:600;color:var(--mk-t2,#1e293b)}
.um-matrix tbody tr:hover{background:var(--mk-bg,#f8fafc)}
.um-matrix input[type=checkbox]{width:16px;height:16px;cursor:pointer}
.um-chip{display:inline-block;font-size:10.5px;background:#eef2ff;color:#4338ca;border-radius:20px;padding:1px 8px;margin:2px 2px 0 0}
</style>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_GET['err'])): ?>
<div class="alert alert-warning alert-dismissible fade show">
  <i class="fas fa-exclamation-triangle me-2"></i>
  <?php if($_GET['err']==='limit'): ?>وصلت للحد الأقصى من المستخدمين في باقتك. <a href="profile.php?tab=upgrade" class="alert-link ms-1">ترقية الباقة</a>
  <?php elseif($_GET['err']==='no_pass'): ?>يجب إدخال كلمة مرور للمستخدم الجديد.
  <?php elseif($_GET['err']==='weak_pass'): ?>كلمة المرور ضعيفة — 8 أحرف على الأقل مع حرف كبير وصغير ورقم ورمز خاص.
  <?php endif; ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-2">
    <span class="text-muted" style="font-size:13px"><i class="fas fa-users me-1"></i>المستخدمون: <strong><?= $user_count ?></strong><?= $user_limit > 0 ? " / $user_limit" : '' ?></span>
    <?php if ($user_limit > 0): ?>
    <div class="progress" style="width:100px;height:6px">
      <div class="progress-bar <?= $user_count >= $user_limit ? 'bg-danger' : 'bg-success' ?>" style="width:<?= min(100, round($user_count/$user_limit*100)) ?>%"></div>
    </div>
    <?php endif; ?>
  </div>
  <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#userModal"
          <?= !canAddMore($conn, $oid, 'users') ? 'disabled title="وصلت للحد الأقصى"' : '' ?>>
    <i class="fas fa-user-plus me-1"></i>إضافة مستخدم
  </button>
</div>

<div class="alert alert-light border" style="font-size:12.5px">
  <i class="fas fa-circle-info me-1 text-primary"></i>
  عند إضافة أو تعديل أي مستخدم تظهر <strong>مصفوفة الصلاحيات كاملة داخل النافذة</strong> — حدّد بنفسك ما يقدر يعمله في كل قسم.
  كل موظف (غير مالك المكتب) يرى تلقائياً قضاياه وجلساته ومهامه المُسندة إليه فقط — حدّد له القضايا من نافذة التعديل.
</div>

<div class="row g-3">
<?php while($u = $users->fetch_assoc()):
  $roles = ['office_owner'=>['مالك المكتب','danger'],'lawyer'=>['محامٍ','primary'],'secretary'=>['سكرتير','info'],'trainee'=>['متدرّب','secondary'],'admin'=>['مشرف','dark']];
  $rr = $roles[$u['role']] ?? [$u['role'],'secondary'];
  $summary = $u['role']==='office_owner' ? ['كل الصلاحيات'] : perm_summary($u['permissions'] ?? '{}', $SECTIONS);
?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100">
    <div class="card-body">
      <div class="d-flex align-items-center gap-3 mb-2">
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" style="width:48px;height:48px;font-size:18px;background:#0f2040;flex-shrink:0">
          <?= mb_substr($u['full_name'],0,1) ?>
        </div>
        <div style="min-width:0">
          <div class="fw-bold text-truncate"><?= e($u['full_name']) ?></div>
          <div class="text-muted" style="font-size:12px">@<?= e($u['username']) ?></div>
        </div>
        <?= $u['is_active'] ? "<span class='badge bg-success ms-auto'>نشط</span>" : "<span class='badge bg-danger ms-auto'>معطّل</span>" ?>
      </div>
      <div class="mb-2">
        <span class="badge bg-<?= $rr[1] ?>"><?= $rr[0] ?></span>
      </div>
      <?php if($u['email']): ?><div style="font-size:12px;color:#888"><i class="fas fa-envelope me-1"></i><?= e($u['email']) ?></div><?php endif; ?>
      <div class="mt-2" style="line-height:1.2">
        <?php if ($summary): foreach (array_slice($summary,0,6) as $s): ?><span class="um-chip"><?= e($s) ?></span><?php endforeach;
          if (count($summary)>6): ?><span class="um-chip">+<?= count($summary)-6 ?></span><?php endif;
        else: ?><span class="text-muted" style="font-size:11.5px">لا صلاحيات — عدّلها</span><?php endif; ?>
      </div>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
      <a href="users.php?edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill"><i class="fas fa-user-pen me-1"></i>تعديل + الصلاحيات</a>
      <?php if($u['role'] !== 'office_owner'): ?>
      <a href="users.php?toggle=<?= $u['id'] ?>" class="btn btn-sm btn-outline-<?= $u['is_active']?'warning':'success' ?>" onclick="return confirm('تغيير الحالة؟')"><i class="fas fa-<?= $u['is_active']?'pause':'play' ?>"></i></a>
      <a href="users.php?delete=<?= $u['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا المستخدم؟')"><i class="fas fa-trash"></i></a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endwhile; ?>
</div>

<!-- ═══ نافذة المستخدم ═══ -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-user-shield me-2"></i><?= $edit && ($edit['id']??0) ? 'تعديل المستخدم وصلاحياته' : 'مستخدم جديد وصلاحياته' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="userForm">
        <div class="modal-body">
          <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">الاسم الكامل *</label>
              <input type="text" name="full_name" class="form-control" required value="<?= e($edit['full_name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">اسم المستخدم *</label>
              <input type="text" name="username" class="form-control" required value="<?= e($edit['username'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">المسمّى</label>
              <select name="role" id="usr_role" class="form-select" onchange="usrRoleChange()">
                <option value="lawyer"       <?= ($edit['role']??'lawyer')==='lawyer'?'selected':'' ?>>محامٍ</option>
                <option value="secretary"    <?= ($edit['role']??'')==='secretary'?'selected':'' ?>>سكرتير</option>
                <option value="trainee"      <?= ($edit['role']??'')==='trainee'?'selected':'' ?>>متدرّب</option>
                <option value="office_owner" <?= ($edit['role']??'')==='office_owner'?'selected':'' ?>>مالك المكتب (كل الصلاحيات)</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">البريد الإلكتروني</label>
              <input type="email" name="email" class="form-control" value="<?= e($edit['email'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">رقم الجوال</label>
              <input type="text" name="phone" class="form-control" value="<?= e($edit['phone'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">كلمة المرور <?= ($edit && ($edit['id']??0)) ? '<span class="text-muted fw-normal">(فارغة = تبقى)</span>' : '<span class="text-danger">*</span>' ?></label>
              <input type="password" name="password" id="usr_password" class="form-control" <?= ($edit && ($edit['id']??0))?'':'required' ?> placeholder="8+ أحرف، كبير وصغير ورقم ورمز">
            </div>
          </div>

          <!-- ═══ الصلاحيات ═══ -->
          <div id="usr_perm_wrap" class="mt-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
              <div class="fw-bold"><i class="fas fa-shield-halved me-1 text-primary"></i>صلاحيات هذا المستخدم</div>
              <div class="d-flex gap-1 flex-wrap">
                <button type="button" class="btn btn-outline-success btn-sm" onclick="usrPermAll(true)">تحديد الكل</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="usrPermAll(false)">مسح الكل</button>
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="usrToggleCol('view',true)">اطلاع على كل الأقسام</button>
              </div>
            </div>
            <div class="table-responsive" style="max-height:360px;overflow:auto;border:1px solid var(--mk-border,#e2e8f0);border-radius:10px">
              <table class="um-matrix">
                <thead>
                  <tr>
                    <th>القسم</th>
                    <?php foreach ($ACTIONS as $a=>$al): ?>
                    <th><?= $al ?><br><input type="checkbox" onclick="usrToggleCol('<?= $a ?>',this.checked)"></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($SECTIONS as $sec=>$label): ?>
                <tr>
                  <td><?= $label ?></td>
                  <?php foreach ($ACTIONS as $a=>$al):
                    $ck = in_array($a, (array)($formPerms[$sec] ?? []), true); ?>
                  <td><input type="checkbox" class="usr-perm" data-sec="<?= $sec ?>" data-act="<?= $a ?>" name="perm[<?= $sec ?>][]" value="<?= $a ?>" <?= $ck?'checked':'' ?>></td>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="mt-3 p-3 rounded" id="usr_cs" style="background:var(--mk-bg,#f8fafc);border:1px solid var(--mk-border,#e2e8f0)">
              <div class="fw-semibold mb-1"><i class="fas fa-gavel me-1 text-primary"></i>القضايا المُسندة لهذا المستخدم</div>
              <div class="text-muted mb-2" style="font-size:11.5px">يرى ويعمل فقط في القضايا المحدَّدة هنا (وما يتبعها من جلسات ومهام وفواتير):</div>
              <div style="max-height:170px;overflow:auto;border:1px solid var(--mk-border,#e2e8f0);border-radius:8px;padding:8px;background:#fff">
                <?php if (!$officeCases): ?><div class="text-muted" style="font-size:12px">لا توجد قضايا بعد</div><?php endif; ?>
                <?php foreach ($officeCases as $c): ?>
                <label class="d-block py-1" style="font-size:12.5px">
                  <input type="checkbox" name="cases[]" value="<?= (int)$c['id'] ?>" <?= isset($formAssigned[(int)$c['id']])?'checked':'' ?>>
                  <?= e($c['case_number'] . ' — ' . mb_substr($c['title'], 0, 55)) ?>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div id="usr_owner_note" class="alert alert-info mt-3 mb-0 py-2" style="display:none;font-size:12.5px">
            <i class="fas fa-crown me-1"></i>مالك المكتب يملك كل الصلاحيات تلقائياً ولا يمكن تقييده.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ المستخدم والصلاحيات</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function usrRoleChange(){
  var owner = document.getElementById('usr_role').value === 'office_owner';
  // المصفوفة تبقى ظاهرة دائماً — للمالك تكون كلها مؤشّرة ومقفلة
  document.getElementById('usr_owner_note').style.display = owner ? 'block' : 'none';
  document.querySelectorAll('.usr-perm').forEach(function(c){
    if (owner) { c.checked = true; c.disabled = true; }
    else { c.disabled = false; }
  });
  document.querySelectorAll('#usr_perm_wrap .btn').forEach(function(b){ b.disabled = owner; });
  document.getElementById('usr_cs').style.display = owner ? 'none' : 'block';
  document.getElementById('usr_perm_wrap').style.opacity = owner ? '.55' : '1';
}
function usrPermAll(on){ document.querySelectorAll('.usr-perm:not([disabled])').forEach(function(c){ c.checked = !!on; }); }
function usrToggleCol(act,on){ document.querySelectorAll('.usr-perm[data-act="'+act+'"]:not([disabled])').forEach(function(c){ c.checked = !!on; }); }
document.addEventListener('DOMContentLoaded', usrRoleChange);
</script>

<?php if ($edit): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ new bootstrap.Modal(document.getElementById('userModal')).show(); });</script>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
