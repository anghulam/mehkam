<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
require_once '../includes/affiliate_helper.php';
requireAdmin();
affiliate_migrate($conn);
$page_title = 'طلبات سحب الأفلييت';

/* ── اعتماد ── */
if (isset($_GET['approve'])) {
    $id = (int)$_GET['approve'];
    $w = $conn->query("SELECT * FROM affiliate_withdrawals WHERE id=$id AND status='pending'")->fetch_assoc();
    if ($w) {
        $conn->query("UPDATE affiliate_withdrawals SET status='approved', processed_at=NOW() WHERE id=$id");
    }
    header("Location: affiliate_withdrawals.php?msg=approved"); exit;
}

/* ── رفض (يُرجع المبلغ لمحفظة الأفلييت لأنه خُصم وقت تقديم الطلب) ── */
if (isset($_GET['reject'])) {
    $id = (int)$_GET['reject'];
    $w = $conn->query("SELECT * FROM affiliate_withdrawals WHERE id=$id AND status='pending'")->fetch_assoc();
    if ($w) {
        $aff = $conn->query("SELECT * FROM affiliates WHERE id={$w['affiliate_id']}")->fetch_assoc();
        if ($aff) {
            $newBal = (float)$aff['wallet_balance'] + (float)$w['amount'];
            $conn->query("UPDATE affiliates SET wallet_balance=$newBal WHERE id={$aff['id']}");
            $conn->query("INSERT INTO affiliate_wallet_tx (affiliate_id,type,amount,note,balance_after)
                VALUES ({$aff['id']},'adjustment',{$w['amount']},'إرجاع مبلغ طلب سحب مرفوض #$id',$newBal)");
        }
        $conn->query("UPDATE affiliate_withdrawals SET status='rejected', processed_at=NOW() WHERE id=$id");
    }
    header("Location: affiliate_withdrawals.php?msg=rejected"); exit;
}

$requests = $conn->query("
    SELECT w.*, a.full_name, a.email
    FROM affiliate_withdrawals w JOIN affiliates a ON w.affiliate_id = a.id
    ORDER BY FIELD(w.status,'pending','approved','rejected'), w.created_at DESC
");

include '../includes/admin_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="fas fa-check-circle me-2"></i><?= ['approved'=>'تم اعتماد الطلب','rejected'=>'تم رفض الطلب وإرجاع المبلغ للمحفظة'][$_GET['msg']] ?? 'تم' ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h5 class="fw-bold mb-1" style="color:#0c1b36">طلبات سحب الأفلييت</h5>
    <small class="text-muted">راجع طلبات سحب الأرصدة وحوّل المبلغ يدوياً (بنكياً) ثم اعتمد الطلب</small>
  </div>
  <a href="affiliates.php" class="btn btn-outline-dark"><i class="fas fa-user-tag me-1"></i>إدارة الأفلييت</a>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>الأفلييت</th><th>المبلغ</th><th>البنك</th><th>آيبان</th><th>الحالة</th><th>التاريخ</th><th>إجراءات</th></tr></thead>
        <tbody>
        <?php if ($requests && $requests->num_rows): while ($w = $requests->fetch_assoc()): ?>
        <tr>
          <td><div class="fw-semibold"><?= e($w['full_name']) ?></div><div class="text-muted" style="font-size:11px"><?= e($w['email']) ?></div></td>
          <td class="fw-bold"><?= number_format($w['amount'],2) ?> ر.س</td>
          <td><?= e($w['bank_name'] ?: '—') ?></td>
          <td style="font-family:monospace;font-size:12px"><?= e($w['bank_iban'] ?: '—') ?></td>
          <td>
            <?php
            $sb = ['pending'=>['bg-warning-subtle text-warning','معلّق'],'approved'=>['bg-success-subtle text-success','مدفوع'],'rejected'=>['bg-danger-subtle text-danger','مرفوض']];
            $s = $sb[$w['status']] ?? ['bg-secondary-subtle text-secondary', $w['status']];
            ?>
            <span class="badge <?= $s[0] ?>"><?= $s[1] ?></span>
          </td>
          <td style="font-size:12px"><?= date('Y/m/d H:i', strtotime($w['created_at'])) ?></td>
          <td>
            <?php if ($w['status'] === 'pending'): ?>
            <a href="affiliate_withdrawals.php?approve=<?= $w['id'] ?>" class="btn btn-sm btn-success" onclick="return confirm('أكّد أنك حوّلت المبلغ بنكياً — اعتماد الطلب؟')"><i class="fas fa-check"></i></a>
            <a href="affiliate_withdrawals.php?reject=<?= $w['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('رفض الطلب وإرجاع المبلغ لمحفظة الأفلييت؟')"><i class="fas fa-times"></i></a>
            <?php else: ?>
            <span class="text-muted" style="font-size:11px"><?= $w['processed_at'] ? date('Y/m/d', strtotime($w['processed_at'])) : '' ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="7" class="text-center text-muted py-4">لا توجد طلبات سحب بعد</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
