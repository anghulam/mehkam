<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/affiliate_helper.php';
requireAdmin();
affiliate_migrate($conn);
$page_title = 'الأفلييت';

/* ── إضافة/تعديل أفلييت ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'affiliate') {
    $name     = $conn->real_escape_string(trim($_POST['full_name'] ?? ''));
    $username = $conn->real_escape_string(trim($_POST['username'] ?? ''));
    $email    = $conn->real_escape_string(trim($_POST['email'] ?? ''));
    $phone    = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
    $pct   = max(0, min(100, (float)($_POST['commission_pct'] ?? 20)));
    $bank_name = $conn->real_escape_string(trim($_POST['bank_name'] ?? ''));
    $bank_iban = $conn->real_escape_string(trim($_POST['bank_iban'] ?? ''));
    $active = isset($_POST['is_active']) ? 1 : 0;
    $id = (int)($_POST['id'] ?? 0);

    if ($username === '') { header("Location: affiliates.php?err=no_user" . ($id ? "&edit=$id" : '')); exit; }
    $dupCheck = $conn->query("SELECT id FROM affiliates WHERE username='$username'" . ($id ? " AND id<>$id" : ''));
    if ($dupCheck && $dupCheck->num_rows) { header("Location: affiliates.php?err=dup_user" . ($id ? "&edit=$id" : '')); exit; }

    if ($id) {
        $sql = "UPDATE affiliates SET full_name='$name',username='$username',email='$email',phone='$phone',
            commission_pct=$pct,bank_name='$bank_name',bank_iban='$bank_iban',is_active=$active";
        if (!empty($_POST['password'])) {
            $sql .= ",password='" . password_hash($_POST['password'], PASSWORD_BCRYPT) . "'";
        }
        $conn->query("$sql WHERE id=$id");
    } else {
        if (empty($_POST['password'])) { header("Location: affiliates.php?err=no_pass"); exit; }
        $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $code = affiliate_gen_ref_code($conn);
        $conn->query("INSERT INTO affiliates (full_name,username,email,phone,password,ref_code,commission_pct,bank_name,bank_iban,is_active)
            VALUES ('$name','$username','$email','$phone','$hash','$code',$pct,'$bank_name','$bank_iban',$active)");
    }
    header("Location: affiliates.php?msg=saved"); exit;
}

if (isset($_GET['delete'])) {
    $conn->query("DELETE FROM affiliates WHERE id=".(int)$_GET['delete']);
    header("Location: affiliates.php?msg=deleted"); exit;
}

/* ── البيانات ── */
$affiliates = $conn->query("SELECT * FROM affiliates ORDER BY created_at DESC");
$rows = [];
if ($affiliates) while ($a = $affiliates->fetch_assoc()) $rows[] = $a;

// إحصائيات لكل أفلييت دفعة واحدة
$statsByAff = [];
$rr = $conn->query("SELECT affiliate_id,
    COUNT(*) total,
    SUM(status='converted') converted
    FROM affiliate_referrals GROUP BY affiliate_id");
if ($rr) while ($x = $rr->fetch_assoc()) $statsByAff[$x['affiliate_id']] = $x;
$clicksByAff = [];
$cr = $conn->query("SELECT affiliate_id, COUNT(*) c FROM affiliate_clicks GROUP BY affiliate_id");
if ($cr) while ($x = $cr->fetch_assoc()) $clicksByAff[$x['affiliate_id']] = (int)$x['c'];

$edit = null;
if (isset($_GET['edit'])) $edit = $conn->query("SELECT * FROM affiliates WHERE id=".(int)$_GET['edit'])->fetch_assoc();

$_site = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'mehkam.app');

include '../includes/admin_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i><?= ['saved'=>'تم الحفظ','deleted'=>'تم الحذف'][$_GET['msg']] ?? 'تم' ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($_GET['err'] ?? ''): ?>
<div class="alert alert-danger alert-dismissible fade show">
  <i class="fas fa-times-circle me-2"></i>
  <?= ['no_pass'=>'كلمة المرور مطلوبة عند إضافة أفلييت جديد','no_user'=>'اسم المستخدم مطلوب','dup_user'=>'اسم المستخدم هذا مستخدم بالفعل — اختر اسماً آخر'][$_GET['err']] ?? 'حدث خطأ' ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<style>
.aff-card { border: 1px solid #eef1f6; border-radius: 14px; overflow: hidden; }
.aff-avatar {
  width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
  background: linear-gradient(135deg,#0c1b36,#1a3a6e); color: #e8c040;
  display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px;
}
.aff-meta { font-size: 11.5px; text-align: right; unicode-bidi: isolate; }
.aff-link-row {
  display: flex; align-items: center; gap: 8px; background: #f8fafc;
  border: 1px solid #eef1f6; border-radius: 10px; padding: 8px 10px;
}
.aff-link-row code {
  flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  font-size: 12px; color: #334155; background: none; padding: 0;
}
.aff-num { font-size: 16px; }
.aff-num-lbl { font-size: 10.5px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h5 class="fw-bold mb-1" style="color:#0c1b36">إدارة الأفلييت</h5>
    <small class="text-muted">أضف حسابات أفلييت يدوياً وتابع إحالاتهم وعمولاتهم</small>
  </div>
  <div class="d-flex gap-2">
    <a href="affiliate_withdrawals.php" class="btn btn-outline-dark"><i class="fas fa-money-bill-transfer me-1"></i>طلبات السحب</a>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#affModal"><i class="fas fa-plus me-1"></i>أفلييت جديد</button>
  </div>
</div>

<div class="row g-3">
<?php if (!$rows): ?>
<div class="col-12"><div class="card"><div class="card-body text-center text-muted py-5">لا يوجد أفلييت بعد</div></div></div>
<?php endif; ?>
<?php foreach ($rows as $a):
  $st = $statsByAff[$a['id']] ?? ['total'=>0,'converted'=>0];
  $clicks = $clicksByAff[$a['id']] ?? 0;
  $link = rtrim($_site,'/') . '/public/register.php?ref=' . $a['ref_code'];
?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100 aff-card">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div class="d-flex align-items-center gap-2">
          <div class="aff-avatar"><?= e(mb_substr($a['full_name'],0,1)) ?></div>
          <div>
            <div class="fw-bold"><?= e($a['full_name']) ?></div>
            <div class="text-muted aff-meta" dir="ltr">@<?= e($a['username'] ?? '') ?> &middot; <?= e($a['email']) ?></div>
          </div>
        </div>
        <?= $a['is_active'] ? '<span class="badge bg-success">نشط</span>' : '<span class="badge bg-secondary">معطّل</span>' ?>
      </div>

      <div class="aff-link-row mb-3">
        <code dir="ltr" id="link_<?= $a['id'] ?>"><?= e($link) ?></code>
        <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" onclick="navigator.clipboard.writeText(document.getElementById('link_<?= $a['id'] ?>').textContent)" title="نسخ الرابط"><i class="fas fa-copy"></i></button>
      </div>

      <div class="row g-2 text-center mb-3">
        <div class="col-3"><div class="fw-bold aff-num"><?= $clicks ?></div><div class="text-muted aff-num-lbl">نقرات</div></div>
        <div class="col-3"><div class="fw-bold aff-num"><?= $st['total'] ?></div><div class="text-muted aff-num-lbl">تسجيلات</div></div>
        <div class="col-3"><div class="fw-bold aff-num text-success"><?= $st['converted'] ?></div><div class="text-muted aff-num-lbl">تحويلات</div></div>
        <div class="col-3"><div class="fw-bold aff-num"><?= (float)$a['commission_pct'] ?>%</div><div class="text-muted aff-num-lbl">العمولة</div></div>
      </div>

      <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background:#f0fdf4">
        <span style="font-size:12px;color:#166534">رصيد المحفظة</span>
        <span class="fw-bold text-success"><?= number_format($a['wallet_balance'],2) ?> ر.س</span>
      </div>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
      <a href="affiliates.php?edit=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill"><i class="fas fa-edit me-1"></i>تعديل</a>
      <a href="affiliates.php?delete=<?= $a['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف هذا الأفلييت؟')"><i class="fas fa-trash"></i></a>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- ══ مودال الأفلييت ══ -->
<div class="modal fade" id="affModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#0c1b36,#1a3a6e)">
        <h5 class="modal-title text-white"><i class="fas fa-user-tag me-2 text-warning"></i><?= $edit ? 'تعديل أفلييت' : 'أفلييت جديد' ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="form_type" value="affiliate">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold">الاسم *</label>
              <input type="text" name="full_name" class="form-control" required value="<?= e($edit['full_name'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">اسم المستخدم * <small class="text-muted fw-normal">(للدخول للبوابة)</small></label>
              <input type="text" name="username" class="form-control" required pattern="[A-Za-z0-9_.\-]+" value="<?= e($edit['username'] ?? '') ?>">
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold">البريد الإلكتروني *</label>
              <input type="email" name="email" class="form-control" required value="<?= e($edit['email'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">الجوال</label>
              <input type="text" name="phone" class="form-control" value="<?= e($edit['phone'] ?? '') ?>">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">كلمة المرور <?= $edit ? '<span class="text-muted fw-normal">(فارغة = تبقى)</span>' : '*' ?></label>
            <input type="password" name="password" class="form-control" <?= $edit?'':'required' ?> placeholder="لدخول بوابة الأفلييت">
          </div>
          <div class="row g-3 mb-3">
            <div class="col-4">
              <label class="form-label fw-semibold">نسبة العمولة %</label>
              <input type="number" name="commission_pct" class="form-control" step="0.5" min="0" max="100" value="<?= $edit['commission_pct'] ?? 20 ?>">
            </div>
            <div class="col-4">
              <label class="form-label fw-semibold">اسم البنك</label>
              <input type="text" name="bank_name" class="form-control" value="<?= e($edit['bank_name'] ?? '') ?>">
            </div>
            <div class="col-4">
              <label class="form-label fw-semibold">آيبان</label>
              <input type="text" name="bank_iban" class="form-control" value="<?= e($edit['bank_iban'] ?? '') ?>">
            </div>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" id="aff_active" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="aff_active">حساب نشط (يقدر يسجّل دخول ويستقبل إحالات)</label>
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

<?php if ($edit): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ new bootstrap.Modal(document.getElementById('affModal')).show(); });</script>
<?php endif; ?>

<?php include '../includes/admin_footer.php'; ?>
