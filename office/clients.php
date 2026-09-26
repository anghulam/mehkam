<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/zatca_helper.php';
require_once '../includes/gov_integrations.php';
requireOffice();
if (!can('clients','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'ملفات العملاء';
$oid = (int)$_SESSION['office_id'];
zatca_migrate($conn);
foreach ([
    "ALTER TABLE clients ADD COLUMN unified_number VARCHAR(20) DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN na_building VARCHAR(10) DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN na_street VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN na_district VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN na_city VARCHAR(80) DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN na_postal VARCHAR(10) DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN na_additional VARCHAR(10) DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN na_short VARCHAR(12) DEFAULT NULL",
] as $_c) { try { $conn->query($_c); } catch (\Throwable $e) {} }

/* ── التحقق من السجل التجاري / الرقم الموحّد عبر «وثيق» (AJAX) ── */
if (isset($_GET['cr_lookup'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(wathiq_lookup($conn, $_GET['cr_lookup']), JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['delete'])) {
    $conn->query("DELETE FROM clients WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header("Location: clients.php?msg=deleted"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = $conn->real_escape_string($_POST['full_name']);
    $idn     = $conn->real_escape_string($_POST['id_number']);
    $ph      = $conn->real_escape_string($_POST['phone']);
    $em      = $conn->real_escape_string($_POST['email']);
    $city    = $conn->real_escape_string($_POST['city']);
    $type    = $conn->real_escape_string($_POST['client_type']);
    $notes   = $conn->real_escape_string($_POST['notes']);
    $vat_num = $conn->real_escape_string(trim($_POST['vat_number'] ?? ''));
    $cr_num  = $conn->real_escape_string(trim($_POST['cr_number']  ?? ''));
    $uni_num = $conn->real_escape_string(trim($_POST['unified_number'] ?? ''));

    // العنوان الوطني
    $na = [];
    foreach (['na_building','na_street','na_district','na_city','na_postal','na_additional','na_short'] as $nk) {
        $na[$nk] = $conn->real_escape_string(trim($_POST[$nk] ?? ''));
    }
    $na_set = "na_building='{$na['na_building']}',na_street='{$na['na_street']}',na_district='{$na['na_district']}',na_city='{$na['na_city']}',na_postal='{$na['na_postal']}',na_additional='{$na['na_additional']}',na_short='{$na['na_short']}'";

    if (!empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $conn->query("UPDATE clients SET full_name='$name',id_number='$idn',phone='$ph',email='$em',city='$city',client_type='$type',notes='$notes',vat_number='$vat_num',cr_number='$cr_num',unified_number='$uni_num',$na_set WHERE id=$id AND office_id=$oid");
    } else {
        if (!canAddMore($conn, $oid, 'clients')) {
            header("Location: clients.php?err=limit"); exit;
        }
        $conn->query("INSERT INTO clients (office_id,full_name,id_number,phone,email,city,client_type,notes,vat_number,cr_number,unified_number,na_building,na_street,na_district,na_city,na_postal,na_additional,na_short) VALUES ($oid,'$name','$idn','$ph','$em','$city','$type','$notes','$vat_num','$cr_num','$uni_num','{$na['na_building']}','{$na['na_street']}','{$na['na_district']}','{$na['na_city']}','{$na['na_postal']}','{$na['na_additional']}','{$na['na_short']}')");
    }
    header("Location: clients.php?msg=saved"); exit;
}

$where = "office_id=$oid";
if (!empty($_GET['q'])) {
    $q = $conn->real_escape_string($_GET['q']);
    $where .= " AND (full_name LIKE '%$q%' OR phone LIKE '%$q%' OR id_number LIKE '%$q%')";
}
if (!empty($_GET['type_f'])) {
    $tf = $conn->real_escape_string($_GET['type_f']);
    $where .= " AND client_type='$tf'";
}

$clients = $conn->query("SELECT *, (SELECT COUNT(*) FROM cases WHERE client_id=clients.id) cases_count FROM clients WHERE $where ORDER BY id DESC");
$client_count = $conn->query("SELECT COUNT(*) c FROM clients WHERE office_id=$oid")->fetch_assoc()['c'];
$client_limit = getFeatureLimit($conn, $oid, 'max_clients');

$edit = null;
if (isset($_GET['edit'])) {
    $edit = $conn->query("SELECT * FROM clients WHERE id=".(int)$_GET['edit']." AND office_id=$oid")->fetch_assoc();
}

include '../includes/office_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i><?= $_GET['msg']==='deleted'?'تم الحذف':'تم الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_GET['err']) && $_GET['err']==='limit'): ?>
<div class="alert alert-warning alert-dismissible fade show">
  <i class="fas fa-exclamation-triangle me-2"></i>
  وصلت للحد الأقصى من العملاء في باقتك الحالية.
  <a href="profile.php?tab=upgrade" class="alert-link ms-1">ترقية الباقة</a>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- فلتر -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="GET">
      <div class="col-auto flex-grow-1">
        <input type="text" name="q" class="form-control" placeholder="بحث بالاسم أو الجوال أو رقم الهوية..." value="<?= e($_GET['q'] ?? '') ?>">
      </div>
      <div class="col-auto">
        <select name="type_f" class="form-select">
          <option value="">الكل</option>
          <option value="individual" <?= ($_GET['type_f']??'')==='individual'?'selected':'' ?>>أفراد</option>
          <option value="company" <?= ($_GET['type_f']??'')==='company'?'selected':'' ?>>شركات</option>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary"><i class="fas fa-search me-1"></i>بحث</button>
        <a href="clients.php" class="btn btn-outline-secondary ms-1">إعادة</a>
      </div>
      <div class="col-auto d-flex align-items-center gap-2">
        <?php if ($client_limit > 0): ?>
        <small class="text-muted"><?= $client_count ?>/<?= $client_limit ?></small>
        <?php endif; ?>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#clientModal"
                <?= !canAddMore($conn, $oid, 'clients') ? 'disabled title="وصلت للحد الأقصى"' : '' ?>>
          <i class="fas fa-plus me-1"></i>إضافة عميل
        </button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>#</th><th>اسم العميل</th><th>النوع</th><th>رقم الهوية</th>
          <th>السجل / الموحّد</th>
          <th>الجوال</th><th>المدينة</th><th>القضايا</th><th>إجراءات</th>
        </tr></thead>
        <tbody>
        <?php while($c = $clients->fetch_assoc()): ?>
        <tr>
          <td><?= $c['id'] ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width:34px;height:34px;font-size:13px;font-weight:700;flex-shrink:0">
                <?= mb_substr($c['full_name'],0,1) ?>
              </div>
              <div>
                <a href="client_file.php?id=<?= $c['id'] ?>" class="fw-semibold text-decoration-none"><?= e($c['full_name']) ?></a>
                <br><small class="text-muted"><?= e($c['email']) ?></small>
              </div>
            </div>
          </td>
          <td><?= $c['client_type']==='company' ? "<span class='badge bg-info'>شركة</span>" : "<span class='badge bg-secondary'>فرد</span>" ?></td>
          <td><?= e($c['id_number']) ?></td>
          <td style="font-size:12px" class="font-monospace">
            <?php if (!empty($c['cr_number'])): ?><div title="السجل التجاري"><i class="fas fa-store text-muted"></i> <?= e($c['cr_number']) ?></div><?php endif; ?>
            <?php if (!empty($c['unified_number'])): ?><div title="الرقم الموحّد" class="text-muted"><?= e($c['unified_number']) ?></div><?php endif; ?>
            <?php if (empty($c['cr_number']) && empty($c['unified_number'])): ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td><?= e($c['phone']) ?></td>
          <td><?= e($c['city']) ?></td>
          <td><span class="badge bg-primary"><?= $c['cases_count'] ?></span></td>
          <td>
            <a href="client_file.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-dark" title="ملف الأعمال الكامل"><i class="fas fa-folder-open"></i></a>
            <a href="clients.php?edit=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="تعديل"><i class="fas fa-edit"></i></a>
            <a href="clients.php?delete=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا العميل؟')"><i class="fas fa-trash"></i></a>
          </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="clientModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i><?= $edit ? 'تعديل بيانات العميل' : 'إضافة عميل جديد' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">الاسم الكامل *</label>
              <input type="text" name="full_name" class="form-control" required value="<?= e($edit['full_name'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">نوع العميل</label>
              <select name="client_type" class="form-select">
                <option value="individual" <?= ($edit['client_type']??'')==='individual'?'selected':'' ?>>فرد</option>
                <option value="company" <?= ($edit['client_type']??'')==='company'?'selected':'' ?>>شركة / مؤسسة</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">رقم الهوية / السجل</label>
              <input type="text" name="id_number" class="form-control" value="<?= e($edit['id_number'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">رقم الجوال</label>
              <input type="text" name="phone" class="form-control" value="<?= e($edit['phone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">البريد الإلكتروني</label>
              <input type="email" name="email" class="form-control" value="<?= e($edit['email'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">المدينة</label>
              <input type="text" name="city" class="form-control" value="<?= e($edit['city'] ?? '') ?>">
            </div>
            <!-- بيانات المنشأة / ZATCA -->
            <div class="col-12">
              <div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:10px 14px">
                <div class="fw-bold mb-2" style="font-size:12px;color:#92400e"><i class="fas fa-building me-1"></i>بيانات المنشأة — للسجل التجاري والفواتير الضريبية (B2B)</div>
                <div class="row g-2">
                  <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size:12px">رقم السجل التجاري (CR)</label>
                    <div class="input-group input-group-sm">
                      <input type="text" name="cr_number" id="cli_cr" class="form-control font-monospace"
                             placeholder="1XXXXXXXXX (10 أرقام)"
                             value="<?= e($edit['cr_number'] ?? '') ?>">
                      <button type="button" class="btn btn-outline-secondary" id="cli_cr_btn" title="تحقق عبر وثيق" onclick="crLookup()">
                        <i class="fas fa-magnifying-glass"></i>
                      </button>
                    </div>
                    <div class="form-text" id="cli_cr_res" style="font-size:10px"></div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size:12px">الرقم الموحّد للمنشأة (700)</label>
                    <input type="text" name="unified_number" id="cli_uni" class="form-control form-control-sm font-monospace"
                           placeholder="7XXXXXXXXX"
                           value="<?= e($edit['unified_number'] ?? '') ?>">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size:12px">الرقم الضريبي (VAT)</label>
                    <input type="text" name="vat_number" class="form-control form-control-sm font-monospace"
                           placeholder="3XXXXXXXXXXXXXXXXXXX3 (15 رقماً)"
                           value="<?= e($edit['vat_number'] ?? '') ?>">
                    <div class="form-text" style="font-size:10px">يُعبأ تلقائياً عند إنشاء فاتورة</div>
                  </div>
                </div>
              </div>
            </div>
            <!-- العنوان الوطني -->
            <div class="col-12">
              <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px">
                <div class="fw-bold mb-2" style="font-size:12px;color:#0369a1"><i class="fas fa-location-dot me-1"></i>العنوان الوطني (اختياري — يظهر في الفاتورة الضريبية B2B)</div>
                <div class="row g-2">
                  <div class="col-6 col-md-3">
                    <label class="form-label" style="font-size:11px">رقم المبنى</label>
                    <input type="text" name="na_building" class="form-control form-control-sm font-monospace" maxlength="4" placeholder="1234" value="<?= e($edit['na_building'] ?? '') ?>">
                  </div>
                  <div class="col-6 col-md-5">
                    <label class="form-label" style="font-size:11px">اسم الشارع</label>
                    <input type="text" name="na_street" class="form-control form-control-sm" placeholder="طريق الملك فهد" value="<?= e($edit['na_street'] ?? '') ?>">
                  </div>
                  <div class="col-6 col-md-4">
                    <label class="form-label" style="font-size:11px">الحي</label>
                    <input type="text" name="na_district" class="form-control form-control-sm" placeholder="العليا" value="<?= e($edit['na_district'] ?? '') ?>">
                  </div>
                  <div class="col-6 col-md-3">
                    <label class="form-label" style="font-size:11px">المدينة</label>
                    <input type="text" name="na_city" class="form-control form-control-sm" placeholder="الرياض" value="<?= e($edit['na_city'] ?? '') ?>">
                  </div>
                  <div class="col-6 col-md-3">
                    <label class="form-label" style="font-size:11px">الرمز البريدي</label>
                    <input type="text" name="na_postal" class="form-control form-control-sm font-monospace" maxlength="5" placeholder="12211" value="<?= e($edit['na_postal'] ?? '') ?>">
                  </div>
                  <div class="col-6 col-md-3">
                    <label class="form-label" style="font-size:11px">الرقم الإضافي</label>
                    <input type="text" name="na_additional" class="form-control form-control-sm font-monospace" maxlength="4" placeholder="8888" value="<?= e($edit['na_additional'] ?? '') ?>">
                  </div>
                  <div class="col-6 col-md-3">
                    <label class="form-label" style="font-size:11px">العنوان المختصر</label>
                    <input type="text" name="na_short" class="form-control form-control-sm font-monospace" maxlength="8" placeholder="RRRD1234" value="<?= e($edit['na_short'] ?? '') ?>">
                  </div>
                </div>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">ملاحظات</label>
              <textarea name="notes" class="form-control" rows="2"><?= e($edit['notes'] ?? '') ?></textarea>
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
function crLookup(){
  var inp = document.getElementById('cli_cr');
  var res = document.getElementById('cli_cr_res');
  var num = (inp.value || '').replace(/\D/g,'');
  if (!num) { res.innerHTML = '<span class="text-danger">أدخل رقم السجل</span>'; return; }
  res.innerHTML = '<span class="text-muted">جارٍ التحقق عبر «وثيق»…</span>';
  fetch('clients.php?cr_lookup=' + encodeURIComponent(num))
    .then(r=>r.json())
    .then(d=>{
      if (!d.ok) { res.innerHTML = '<span class="text-danger"><i class="fas fa-circle-info me-1"></i>' + (d.msg||'تعذّر') + '</span>'; return; }
      if (d.name && !document.querySelector('input[name=full_name]').value) document.querySelector('input[name=full_name]').value = d.name;
      if (d.unified_number) document.getElementById('cli_uni').value = d.unified_number;
      if (d.city) { var cf = document.querySelector('input[name=city]'); if (cf && !cf.value) cf.value = d.city; }
      res.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>' + (d.name||'تم') + (d.status ? ' — ' + d.status : '') + '</span>';
    })
    .catch(()=>{ res.innerHTML = '<span class="text-danger">خطأ في الاتصال</span>'; });
}
</script>
<?php if ($edit): ?>
<script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('clientModal')).show();});</script>
<?php endif; ?>

<?php include '../includes/office_footer.php'; ?>
