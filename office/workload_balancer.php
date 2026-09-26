<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('workload_balancer','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'workload_balancer')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'موازن الحمل الوظيفي';

$rows = [];
$ur = $conn->query("SELECT id, full_name, role FROM users WHERE office_id=$oid AND is_active=1 AND role IN ('lawyer','office_owner') ORDER BY full_name");
if ($ur) while ($u = $ur->fetch_assoc()) {
    $uid = (int)$u['id'];
    $cases = (int)$conn->query("SELECT COUNT(DISTINCT ca.case_id) c FROM case_assignments ca JOIN cases c ON c.id=ca.case_id WHERE ca.user_id=$uid AND c.status='active'")->fetch_assoc()['c'];
    $tasks = (int)$conn->query("SELECT COUNT(*) c FROM tasks WHERE office_id=$oid AND assigned_to_id=$uid AND status IN ('pending','in_progress')")->fetch_assoc()['c'];
    $overdue = (int)$conn->query("SELECT COUNT(*) c FROM tasks WHERE office_id=$oid AND assigned_to_id=$uid AND status IN ('pending','in_progress') AND due_date < NOW()")->fetch_assoc()['c'];
    $rows[] = ['name' => $u['full_name'], 'cases' => $cases, 'tasks' => $tasks, 'overdue' => $overdue, 'score' => $cases * 2 + $tasks];
}
usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
$maxScore = max(array_column($rows, 'score')) ?: 1;

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-scale-balanced"></i> موازن الحمل الوظيفي</div>
<div class="mk-page-sub mb-3">توزيع القضايا النشطة والمهام المفتوحة على المحامين — استخدمه قبل إسناد قضية جديدة لتفادي التحميل غير المتوازن</div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
<thead><tr><th>المحامي</th><th>الحمل الوظيفي</th><th>قضايا نشطة</th><th>مهام مفتوحة</th><th>متأخرة</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">لا يوجد محامون نشطون</td></tr>
<?php else: foreach ($rows as $r): $pct = round($r['score'] / $maxScore * 100); $color = $pct > 75 ? 'danger' : ($pct > 45 ? 'warning' : 'success'); ?>
<tr>
  <td class="fw-semibold"><?= e($r['name']) ?></td>
  <td style="min-width:160px"><div class="progress" style="height:10px"><div class="progress-bar bg-<?= $color ?>" style="width:<?= max(4,$pct) ?>%"></div></div></td>
  <td><?= $r['cases'] ?></td>
  <td><?= $r['tasks'] ?></td>
  <td><?= $r['overdue'] > 0 ? '<span class="text-danger fw-bold">'.$r['overdue'].'</span>' : '0' ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div>
<div class="text-muted mt-2" style="font-size:12px"><i class="fas fa-circle-info me-1"></i>الحمل الوظيفي = (عدد القضايا النشطة × 2) + عدد المهام المفتوحة — مؤشر تقريبي وليس دقيقاً بالكامل.</div>

<?php include '../includes/office_footer.php'; ?>
