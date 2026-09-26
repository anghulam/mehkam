<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('referral_network','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'referral_network')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'شبكة الإحالات بين المكاتب';
$uid = (int)($_SESSION['user_id'] ?? 0);
$_canAdd = can('referral_network','add'); $_canEdit = can('referral_network','edit'); $_canDel = can('referral_network','delete');
$_canApprove = can('referral_network','approve');

$conn->query("CREATE TABLE IF NOT EXISTS referral_network (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    direction ENUM('incoming','outgoing') NOT NULL DEFAULT 'incoming',
    partner_firm_name VARCHAR(200) NOT NULL,
    partner_contact VARCHAR(200) DEFAULT NULL,
    case_id INT DEFAULT NULL,
    client_name VARCHAR(200) DEFAULT NULL,
    fee_percentage DECIMAL(5,2) DEFAULT 0,
    fee_amount DECIMAL(10,2) DEFAULT 0,
    status ENUM('pending','active','closed') DEFAULT 'pending',
    notes TEXT,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office (office_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── حذف ── */
if (isset($_GET['delete']) && $_canDel) {
    $conn->query("DELETE FROM referral_network WHERE id=".(int)$_GET['delete']." AND office_id=$oid");
    header("Location: referral_network.php?msg=deleted"); exit;
}

/* ── حفظ ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'referral') {
    $rid = (int)($_POST['id'] ?? 0);
    requirePerm('referral_network', $rid ? 'edit' : 'add', 'referral_network.php?msg=denied');
    if ($rid && !$_canApprove) {
        $own = $conn->query("SELECT id FROM referral_network WHERE id=$rid AND office_id=$oid AND created_by=$uid")->num_rows;
        if (!$own) { header("Location: referral_network.php?msg=denied"); exit; }
    }
    $direction = ($_POST['direction'] ?? '') === 'outgoing' ? 'outgoing' : 'incoming';
    $firm = $conn->real_escape_string(trim($_POST['partner_firm_name'] ?? ''));
    $contact = $conn->real_escape_string(trim($_POST['partner_contact'] ?? ''));
    $case_id = !empty($_POST['case_id']) ? (int)$_POST['case_id'] : 'NULL';
    $client_name = $conn->real_escape_string(trim($_POST['client_name'] ?? ''));
    $fee_pct = max(0, min(100, (float)($_POST['fee_percentage'] ?? 0)));
    $fee_amt = max(0, (float)($_POST['fee_amount'] ?? 0));
    $status = in_array($_POST['status'] ?? '', ['pending','active','closed'], true) ? $_POST['status'] : 'pending';
    $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    if ($rid) {
        $conn->query("UPDATE referral_network SET direction='$direction',partner_firm_name='$firm',partner_contact='$contact',case_id=$case_id,client_name='$client_name',fee_percentage=$fee_pct,fee_amount=$fee_amt,status='$status',notes='$notes' WHERE id=$rid AND office_id=$oid");
    } else {
        $conn->query("INSERT INTO referral_network (office_id,direction,partner_firm_name,partner_contact,case_id,client_name,fee_percentage,fee_amount,status,notes,created_by)
            VALUES ($oid,'$direction','$firm','$contact',$case_id,'$client_name',$fee_pct,$fee_amt,'$status','$notes',".($uid ?: 'NULL').")");
    }
    header("Location: referral_network.php?msg=saved"); exit;
}

/* ── بيانات — موظف عادي يشوف فقط ما أضافه بنفسه؛ المدير يشوف الكل ── */
$_ownScope = !$_canApprove ? " AND r.created_by=$uid" : "";
$referrals = [];
$rr = $conn->query("SELECT r.*, c.case_number, c.title case_title FROM referral_network r
    LEFT JOIN cases c ON r.case_id=c.id WHERE r.office_id=$oid$_ownScope ORDER BY r.id DESC");
if ($rr) while ($r = $rr->fetch_assoc()) $referrals[] = $r;

$cases_arr = [];
$ccr = $conn->query("SELECT id, case_number, title FROM cases WHERE office_id=$oid" . caseScope('cases') . " ORDER BY id DESC LIMIT 300");
if ($ccr) while ($r = $ccr->fetch_assoc()) $cases_arr[$r['id']] = $r['case_number'].' — '.mb_substr($r['title'],0,40);

$total_incoming_fees = 0; $total_outgoing_fees = 0;
foreach ($referrals as $r) {
    if ($r['direction']==='incoming') $total_incoming_fees += (float)$r['fee_amount'];
    else $total_outgoing_fees += (float)$r['fee_amount'];
}

$_statusMap = ['pending'=>['قيد التفاوض','warning'],'active'=>['نشطة','primary'],'closed'=>['مُغلقة','secondary']];

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-people-arrows"></i> شبكة الإحالات بين المكاتب</div>
<div class="mk-page-sub mb-3">سجّل القضايا المُحالة من ولمكاتب أخرى مع نسبة الأتعاب المتفق عليها</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i>تم <?= $_GET['msg']==='deleted'?'الحذف':'الحفظ' ?> بنجاح
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#f0fdf4;color:#16a34a"><i class="fas fa-arrow-down"></i></div>
      <div><div class="fw-bold"><?= number_format($total_incoming_fees,0) ?> ر.س</div><div class="text-muted" style="font-size:11px">أتعاب إحالات واردة</div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:42px;height:42px;background:#fef3c7;color:#d97706"><i class="fas fa-arrow-up"></i></div>
      <div><div class="fw-bold"><?= number_format($total_outgoing_fees,0) ?> ر.س</div><div class="text-muted" style="font-size:11px">أتعاب إحالات صادرة</div></div>
    </div></div>
  </div>
</div>

<div class="d-flex justify-content-end mb-3">
  <?php if ($_canAdd): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#refModal" onclick="refNew()"><i class="fas fa-plus me-1"></i>إحالة جديدة</button>
  <?php endif; ?>
</div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>الاتجاه</th><th>المكتب الشريك</th><th>القضية/العميل</th><th>نسبة الأتعاب</th><th>مبلغ الأتعاب</th><th>الحالة</th><th>إجراءات</th></tr></thead>
<tbody>
<?php if (!$referrals): ?><tr><td colspan="7" class="text-center text-muted py-4">لا توجد إحالات مسجّلة بعد</td></tr>
<?php else: foreach ($referrals as $r): $sm = $_statusMap[$r['status']] ?? ['—','secondary']; ?>
<tr>
  <td><?= $r['direction']==='incoming' ? '<span class="badge bg-success bg-opacity-10 text-success"><i class="fas fa-arrow-down me-1"></i>واردة</span>' : '<span class="badge bg-warning bg-opacity-10 text-warning"><i class="fas fa-arrow-up me-1"></i>صادرة</span>' ?></td>
  <td class="fw-semibold"><?= e($r['partner_firm_name']) ?><?php if ($r['partner_contact']): ?><br><small class="text-muted"><?= e($r['partner_contact']) ?></small><?php endif; ?></td>
  <td style="font-size:12px"><?= $r['case_number'] ? e($r['case_number']).' — '.e(mb_substr($r['case_title'],0,30)) : e($r['client_name'] ?: '—') ?></td>
  <td><?= number_format((float)$r['fee_percentage'],1) ?>%</td>
  <td class="fw-bold"><?= number_format((float)$r['fee_amount'],2) ?> ر.س</td>
  <td><span class="badge bg-<?= $sm[1] ?> bg-opacity-10 text-<?= $sm[1] ?>"><?= $sm[0] ?></span></td>
  <td class="d-flex gap-1">
    <?php if ($_canEdit): ?><button class="btn btn-sm btn-outline-primary" onclick='refEdit(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button><?php endif; ?>
    <?php if ($_canDel): ?><a href="referral_network.php?delete=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذه الإحالة؟')"><i class="fas fa-trash"></i></a><?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div></div></div>

<!-- Modal -->
<div class="modal fade" id="refModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="refTitle"><i class="fas fa-people-arrows me-2"></i>إحالة جديدة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><input type="hidden" name="form_type" value="referral"><input type="hidden" name="id" id="ref_id">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label fw-semibold">الاتجاه</label>
        <select name="direction" id="ref_direction" class="form-select">
          <option value="incoming">واردة (قضية أحالها لنا مكتب آخر)</option>
          <option value="outgoing">صادرة (قضية أحلناها لمكتب آخر)</option>
        </select>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label fw-semibold">المكتب الشريك *</label><input type="text" name="partner_firm_name" id="ref_firm" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">جهة الاتصال</label><input type="text" name="partner_contact" id="ref_contact" class="form-control"></div>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">القضية المرتبطة (اختياري)</label>
        <select name="case_id" id="ref_case" class="form-select"><option value="">— بدون —</option>
          <?php foreach ($cases_arr as $cid=>$cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">اسم العميل (إن لم تكن القضية مسجَّلة بعد)</label><input type="text" name="client_name" id="ref_client" class="form-control"></div>
      <div class="row g-3 mb-3">
        <div class="col-md-4"><label class="form-label fw-semibold">نسبة الأتعاب %</label><input type="number" name="fee_percentage" id="ref_pct" class="form-control" min="0" max="100" step="0.1" value="0"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">مبلغ الأتعاب (ر.س)</label><input type="number" name="fee_amount" id="ref_amt" class="form-control" min="0" step="0.01" value="0"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">الحالة</label>
          <select name="status" id="ref_status" class="form-select">
            <option value="pending">قيد التفاوض</option><option value="active">نشطة</option><option value="closed">مُغلقة</option>
          </select>
        </div>
      </div>
      <div class="mb-1"><label class="form-label fw-semibold">ملاحظات</label><textarea name="notes" id="ref_notes" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ</button></div>
  </form>
</div></div></div>

<script>
function refNew(){document.getElementById('refTitle').innerHTML='<i class="fas fa-people-arrows me-2"></i>إحالة جديدة';document.getElementById('ref_id').value='';document.getElementById('ref_direction').value='incoming';document.getElementById('ref_firm').value='';document.getElementById('ref_contact').value='';document.getElementById('ref_case').value='';document.getElementById('ref_client').value='';document.getElementById('ref_pct').value='0';document.getElementById('ref_amt').value='0';document.getElementById('ref_status').value='pending';document.getElementById('ref_notes').value='';}
function refEdit(r){document.getElementById('refTitle').innerHTML='<i class="fas fa-edit me-2"></i>تعديل إحالة';document.getElementById('ref_id').value=r.id;document.getElementById('ref_direction').value=r.direction;document.getElementById('ref_firm').value=r.partner_firm_name;document.getElementById('ref_contact').value=r.partner_contact||'';document.getElementById('ref_case').value=r.case_id||'';document.getElementById('ref_client').value=r.client_name||'';document.getElementById('ref_pct').value=r.fee_percentage;document.getElementById('ref_amt').value=r.fee_amount;document.getElementById('ref_status').value=r.status;document.getElementById('ref_notes').value=r.notes||'';new bootstrap.Modal(document.getElementById('refModal')).show();}
</script>

<?php include '../includes/office_footer.php'; ?>
