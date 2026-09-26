<?php
$page_title = 'الملف الشخصي';
require_once __DIR__ . '/../includes/affiliate_header.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = $conn->real_escape_string(trim($_POST['full_name'] ?? ''));
    $email = $conn->real_escape_string(trim($_POST['email'] ?? ''));
    $phone = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
    $bank_name = $conn->real_escape_string(trim($_POST['bank_name'] ?? ''));
    $bank_iban = $conn->real_escape_string(trim($_POST['bank_iban'] ?? ''));

    if ($name === '' || $email === '') {
        $msg = ['type' => 'danger', 'text' => 'الاسم والبريد الإلكتروني مطلوبان'];
    } else {
        $sql = "UPDATE affiliates SET full_name='$name', email='$email', phone='$phone', bank_name='$bank_name', bank_iban='$bank_iban'";

        $pwd_changed = false;
        if (!empty($_POST['new_password'])) {
            if (!password_verify($_POST['current_password'] ?? '', $_aff['password'])) {
                $msg = ['type' => 'danger', 'text' => 'كلمة المرور الحالية غير صحيحة'];
            } elseif (strlen($_POST['new_password']) < 6) {
                $msg = ['type' => 'danger', 'text' => 'كلمة المرور الجديدة قصيرة جداً (6 أحرف على الأقل)'];
            } elseif ($_POST['new_password'] !== ($_POST['new_password2'] ?? '')) {
                $msg = ['type' => 'danger', 'text' => 'كلمتا المرور الجديدتان غير متطابقتين'];
            } else {
                $sql .= ", password='" . password_hash($_POST['new_password'], PASSWORD_BCRYPT) . "'";
                $pwd_changed = true;
            }
        }

        if (!$msg) {
            $conn->query("$sql WHERE id=$_aff_id");
            $_aff = $conn->query("SELECT * FROM affiliates WHERE id=$_aff_id LIMIT 1")->fetch_assoc();
            $msg = ['type' => 'success', 'text' => 'تم حفظ التعديلات' . ($pwd_changed ? ' وتغيير كلمة المرور' : '')];
        }
    }
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg['type'] ?>"><?= e($msg['text']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><i class="fas fa-user me-2 text-primary"></i>البيانات الأساسية</div>
      <div class="card-body">
        <form method="POST">
          <div class="mb-3">
            <label class="form-label fw-semibold">الاسم الكامل</label>
            <input type="text" name="full_name" class="form-control" required value="<?= e($_aff['full_name']) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">اسم المستخدم</label>
            <input type="text" class="form-control" value="@<?= e($_aff['username'] ?? '') ?>" disabled>
            <div class="form-text">لا يمكن تغيير اسم المستخدم — تواصل مع إدارة المنصة إذا احتجت ذلك</div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold">البريد الإلكتروني</label>
              <input type="email" name="email" class="form-control" required value="<?= e($_aff['email']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">الجوال</label>
              <input type="text" name="phone" class="form-control" value="<?= e($_aff['phone']) ?>">
            </div>
          </div>

          <hr class="my-3">
          <div class="fw-semibold mb-2" style="font-size:13px"><i class="fas fa-building-columns me-1 text-secondary"></i>بيانات الحساب البنكي (لاستلام السحوبات)</div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold">اسم البنك</label>
              <input type="text" name="bank_name" class="form-control" value="<?= e($_aff['bank_name']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">آيبان</label>
              <input type="text" name="bank_iban" class="form-control" value="<?= e($_aff['bank_iban']) ?>">
            </div>
          </div>

          <hr class="my-3">
          <div class="fw-semibold mb-2" style="font-size:13px"><i class="fas fa-lock me-1 text-secondary"></i>تغيير كلمة المرور <small class="text-muted fw-normal">(اختياري)</small></div>
          <div class="row g-3 mb-3">
            <div class="col-4">
              <label class="form-label">كلمة المرور الحالية</label>
              <input type="password" name="current_password" class="form-control">
            </div>
            <div class="col-4">
              <label class="form-label">كلمة المرور الجديدة</label>
              <input type="password" name="new_password" class="form-control">
            </div>
            <div class="col-4">
              <label class="form-label">تأكيد كلمة المرور</label>
              <input type="password" name="new_password2" class="form-control">
            </div>
          </div>

          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ التعديلات</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="fas fa-circle-info me-2 text-secondary"></i>ملخص حسابك</div>
      <div class="card-body">
        <div class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">كود الإحالة</span><b><?= e($_aff['ref_code']) ?></b></div>
        <div class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">نسبة العمولة</span><b><?= (float)$_aff['commission_pct'] ?>%</b></div>
        <div class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">رصيد المحفظة</span><b class="text-success"><?= number_format($_aff['wallet_balance'],2) ?> ر.س</b></div>
        <div class="d-flex justify-content-between py-2"><span class="text-muted">إجمالي الأرباح</span><b><?= number_format($_aff['total_earned'],2) ?> ر.س</b></div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
