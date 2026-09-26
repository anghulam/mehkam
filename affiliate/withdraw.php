<?php
$page_title = 'طلب سحب';
require_once __DIR__ . '/../includes/affiliate_header.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)($_POST['amount'] ?? 0);
    $bank_name = $conn->real_escape_string(trim($_POST['bank_name'] ?? ''));
    $bank_iban = $conn->real_escape_string(trim($_POST['bank_iban'] ?? ''));
    $balance = (float)$_aff['wallet_balance'];

    if ($amount <= 0) {
        $msg = ['type' => 'danger', 'text' => 'أدخل مبلغاً صحيحاً'];
    } elseif ($amount > $balance) {
        $msg = ['type' => 'danger', 'text' => 'المبلغ المطلوب أكبر من رصيدك المتاح (' . number_format($balance, 2) . ' ر.س)'];
    } elseif ($bank_iban === '') {
        $msg = ['type' => 'danger', 'text' => 'أدخل رقم الآيبان لاستلام التحويل'];
    } else {
        $pending = $conn->query("SELECT id FROM affiliate_withdrawals WHERE affiliate_id=$_aff_id AND status='pending' LIMIT 1");
        if ($pending && $pending->num_rows) {
            $msg = ['type' => 'warning', 'text' => 'عندك طلب سحب معلّق بالفعل — انتظر معالجته قبل تقديم طلب جديد'];
        } else {
            $conn->query("INSERT INTO affiliate_withdrawals (affiliate_id, amount, bank_name, bank_iban)
                VALUES ($_aff_id, $amount, '$bank_name', '$bank_iban')");
            $newBal = $balance - $amount;
            $conn->query("UPDATE affiliates SET wallet_balance=$newBal WHERE id=$_aff_id");
            $conn->query("INSERT INTO affiliate_wallet_tx (affiliate_id, type, amount, note, balance_after)
                VALUES ($_aff_id, 'withdrawal', -$amount, 'طلب سحب مقدَّم', $newBal)");
            $_aff['wallet_balance'] = $newBal;
            $msg = ['type' => 'success', 'text' => 'تم إرسال طلب السحب — سيصلك المبلغ بعد مراجعة الإدارة'];
        }
    }
}

$history = $conn->query("SELECT * FROM affiliate_withdrawals WHERE affiliate_id=$_aff_id ORDER BY created_at DESC LIMIT 20");
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="fas fa-money-bill-transfer me-2 text-success"></i>طلب سحب رصيد</div>
      <div class="card-body">
        <div class="alert alert-light border py-2 mb-3" style="font-size:13px">
          الرصيد المتاح للسحب: <strong class="text-success"><?= number_format($_aff['wallet_balance'], 2) ?> ر.س</strong>
        </div>
        <form method="POST">
          <div class="mb-3">
            <label class="form-label fw-semibold">المبلغ المطلوب (ر.س)</label>
            <input type="number" name="amount" class="form-control" step="0.01" min="1" max="<?= (float)$_aff['wallet_balance'] ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">اسم البنك</label>
            <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($_aff['bank_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">رقم الآيبان *</label>
            <input type="text" name="bank_iban" class="form-control" required value="<?= htmlspecialchars($_aff['bank_iban'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="SAxxxxxxxxxxxxxxxxxxxxxx">
          </div>
          <button type="submit" class="btn btn-primary w-100" <?= $_aff['wallet_balance'] <= 0 ? 'disabled' : '' ?>>
            <i class="fas fa-paper-plane me-1"></i>إرسال طلب السحب
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="fas fa-clock-rotate-left me-2 text-secondary"></i>سجل طلبات السحب</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>المبلغ</th><th>الحالة</th><th>التاريخ</th></tr></thead>
            <tbody>
            <?php if ($history && $history->num_rows): while ($w = $history->fetch_assoc()): ?>
            <tr>
              <td class="fw-semibold"><?= number_format($w['amount'], 2) ?> ر.س</td>
              <td>
                <?php
                $sb = ['pending'=>['bg-warning-subtle text-warning','قيد المراجعة'],'approved'=>['bg-success-subtle text-success','مدفوع'],'rejected'=>['bg-danger-subtle text-danger','مرفوض']];
                $s = $sb[$w['status']] ?? ['bg-secondary-subtle text-secondary', $w['status']];
                ?>
                <span class="badge <?= $s[0] ?>"><?= $s[1] ?></span>
              </td>
              <td style="font-size:12px"><?= date('Y/m/d', strtotime($w['created_at'])) ?></td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="3" class="text-center text-muted py-4">لا توجد طلبات سحب سابقة</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
