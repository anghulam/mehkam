<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (!can('bi_dashboard','view')) { header('Location: dashboard.php?msg=denied'); exit; }
$oid = (int)$_SESSION['office_id'];
if (!hasModule($conn, $oid, 'bi_dashboard')) { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'لوحة تحليلات تنفيذية';
$_canFin = can('invoices','view') && can('finance','view');

/* ── الإيرادات آخر 12 شهر ── */
$months_labels = $months_values = [];
$_ymIndex = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $months_labels[] = date('n/Y', strtotime($ym.'-01'));
    $_ymIndex[$ym] = count($months_values);
    $months_values[] = 0.0;
}
if ($_canFin) {
    $mr = $conn->query("SELECT DATE_FORMAT(COALESCE(paid_date,issue_date),'%Y-%m') ym, SUM(total) s
        FROM invoices WHERE office_id=$oid AND status='paid' AND direction!='expense'
        AND COALESCE(paid_date,issue_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym");
    if ($mr) while ($x = $mr->fetch_assoc()) if (isset($_ymIndex[$x['ym']])) $months_values[$_ymIndex[$x['ym']]] = (float)$x['s'];
}

/* ── نمو العملاء آخر 12 شهر ── */
$client_growth = array_fill(0, 12, 0);
$cg = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c FROM clients
    WHERE office_id=$oid AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym");
if ($cg) while ($x = $cg->fetch_assoc()) if (isset($_ymIndex[$x['ym']])) $client_growth[$_ymIndex[$x['ym']]] = (int)$x['c'];

/* ── توزيع نتائج القضايا ── */
$case_outcomes = ['won'=>0,'lost'=>0,'settled'=>0,'active'=>0,'closed'=>0,'suspended'=>0];
$cr = $conn->query("SELECT status, COUNT(*) c FROM cases WHERE office_id=$oid GROUP BY status");
if ($cr) while ($x = $cr->fetch_assoc()) if (isset($case_outcomes[$x['status']])) $case_outcomes[$x['status']] = (int)$x['c'];
$closed_total = $case_outcomes['won'] + $case_outcomes['lost'] + $case_outcomes['settled'];
$win_rate = $closed_total > 0 ? round($case_outcomes['won'] / $closed_total * 100) : 0;

/* ── أداء المحامين: عدد القضايا وإجمالي الفواتير المحصَّلة عبر قضاياهم ── */
$lawyer_perf = [];
$lp = $conn->query("SELECT u.id, u.full_name, COUNT(DISTINCT ca.case_id) cases_count
    FROM users u LEFT JOIN case_assignments ca ON ca.user_id=u.id
    WHERE u.office_id=$oid AND u.role IN ('lawyer','office_owner') AND u.is_active=1
    GROUP BY u.id ORDER BY cases_count DESC LIMIT 8");
if ($lp) while ($x = $lp->fetch_assoc()) $lawyer_perf[] = $x;

/* ── مؤشرات عامة ── */
$total_clients = (int)$conn->query("SELECT COUNT(*) c FROM clients WHERE office_id=$oid")->fetch_assoc()['c'];
$active_cases  = (int)$conn->query("SELECT COUNT(*) c FROM cases WHERE office_id=$oid AND status='active'")->fetch_assoc()['c'];
$ytd_revenue = 0;
if ($_canFin) {
    $ytd_revenue = (float)$conn->query("SELECT IFNULL(SUM(total),0) s FROM invoices WHERE office_id=$oid AND status='paid' AND direction!='expense' AND YEAR(COALESCE(paid_date,issue_date))=YEAR(CURDATE())")->fetch_assoc()['s'];
}
$avg_case_value = 0;
if ($_canFin && $closed_total > 0) {
    $avg_case_value = (float)$conn->query("SELECT IFNULL(AVG(fees),0) a FROM cases WHERE office_id=$oid AND status IN ('won','lost','settled')")->fetch_assoc()['a'];
}

include '../includes/office_header.php';
?>

<div class="mk-page-title mb-1"><i class="fas fa-chart-pie"></i> لوحة تحليلات تنفيذية</div>
<div class="mk-page-sub mb-4">نظرة أعمق على أداء المكتب — للمالك والشركاء</div>

<div class="row g-3 mb-3">
  <?php foreach ([
    ['عملاء نشطون', $total_clients, 'users', '#2563eb'],
    ['قضايا نشطة', $active_cases, 'gavel', '#7c3aed'],
    ['نسبة الكسب', $win_rate.'%', 'trophy', '#16a34a'],
    ['إيراد هذا العام', $_canFin ? number_format($ytd_revenue,0).' ر.س' : '—', 'coins', '#d97706'],
  ] as [$lbl,$val,$ic,$col]): ?>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:44px;height:44px;font-size:18px;background:<?= $col ?>1a;color:<?= $col ?>"><i class="fas fa-<?= $ic ?>"></i></div>
      <div><div class="fw-bold" style="font-size:18px"><?= $val ?></div><div class="text-muted" style="font-size:11px"><?= $lbl ?></div></div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-area me-2 text-primary"></i>الإيرادات المحصَّلة — آخر 12 شهر</div>
      <div class="card-body"><div id="biRevChart" style="min-height:250px"></div>
        <?php if (!$_canFin): ?><div class="text-muted text-center py-4" style="font-size:12px">يحتاج صلاحية الفواتير والشؤون المالية</div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-scale-balanced me-2 text-success"></i>توزيع نتائج القضايا</div>
      <div class="card-body"><div id="biCaseChart" style="min-height:250px"></div></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-user-plus me-2 text-info"></i>نمو قاعدة العملاء — آخر 12 شهر</div>
      <div class="card-body"><div id="biClientChart" style="min-height:220px"></div></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-ranking-star me-2 text-warning"></i>أكثر المحامين نشاطاً (بعدد القضايا)</div>
      <div class="card-body p-0">
        <?php if (!$lawyer_perf): ?>
        <div class="text-muted text-center py-4">لا توجد بيانات كافية</div>
        <?php else: $maxC = max(1, max(array_column($lawyer_perf,'cases_count'))); ?>
        <div class="p-3">
          <?php foreach ($lawyer_perf as $lp2): $pct = max(6, round($lp2['cases_count']/$maxC*100)); ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <div style="width:110px;font-size:12px;color:#475569" class="text-truncate"><?= e($lp2['full_name']) ?></div>
            <div class="flex-grow-1" style="background:#f1f5f9;border-radius:6px">
              <div style="height:22px;border-radius:6px;background:#7c3aed;display:flex;align-items:center;padding:0 8px;color:#fff;font-size:11px;font-weight:700;width:<?= $pct ?>%"><?= $lp2['cases_count'] ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
(function initCharts(tries){
  if (typeof ApexCharts === 'undefined') { if ((tries||0) < 20) return setTimeout(function(){ initCharts((tries||0)+1); }, 150); return; }

  var revEl = document.getElementById('biRevChart');
  if (revEl) new ApexCharts(revEl, {
    chart: { type: 'area', height: 250, toolbar: {show:false}, fontFamily: 'Tajawal,sans-serif' },
    series: [{ name: 'إيراد (ر.س)', data: <?= json_encode(array_map('floatval',$months_values)) ?> }],
    xaxis: { categories: <?= json_encode($months_labels) ?> },
    colors: ['#2563eb'],
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05 } },
    stroke: { curve: 'smooth', width: 2 },
    dataLabels: { enabled: false },
    grid: { borderColor: '#f0f0f0' },
  }).render();

  var caseEl = document.getElementById('biCaseChart');
  if (caseEl) new ApexCharts(caseEl, {
    series: <?= json_encode(array_values($case_outcomes)) ?>,
    labels: ['مكسوبة','خاسرة','متسوية','نشطة','مغلقة','موقوفة'],
    chart: { type: 'donut', height: 250, fontFamily: 'Tajawal,sans-serif' },
    colors: ['#16a34a','#dc2626','#0891b2','#2563eb','#64748b','#d97706'],
    legend: { position: 'bottom', fontSize: '12px' },
  }).render();

  var clEl = document.getElementById('biClientChart');
  if (clEl) new ApexCharts(clEl, {
    chart: { type: 'bar', height: 220, toolbar: {show:false}, fontFamily: 'Tajawal,sans-serif' },
    series: [{ name: 'عملاء جدد', data: <?= json_encode(array_map('intval',$client_growth)) ?> }],
    xaxis: { categories: <?= json_encode($months_labels) ?> },
    colors: ['#0891b2'],
    plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
    dataLabels: { enabled: false },
    grid: { borderColor: '#f0f0f0' },
  }).render();
})();
</script>

<?php include '../includes/office_footer.php'; ?>
