<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireAdmin();

$page_title = 'لوحة الإحصائيات العامة';

// إحصائيات المكاتب
$total_offices   = $conn->query("SELECT COUNT(*) c FROM offices")->fetch_assoc()['c'];
$active_offices  = $conn->query("SELECT COUNT(*) c FROM offices WHERE status='active'")->fetch_assoc()['c'];
$trial_offices   = $conn->query("SELECT COUNT(*) c FROM offices WHERE status='trial'")->fetch_assoc()['c'];
$expired_offices = $conn->query("SELECT COUNT(*) c FROM offices WHERE status='expired'")->fetch_assoc()['c'];
$suspended_offices = $conn->query("SELECT COUNT(*) c FROM offices WHERE status='suspended'")->fetch_assoc()['c'];

// إيرادات الشهر الحالي
$month_revenue = $conn->query("SELECT IFNULL(SUM(amount),0) s FROM platform_revenue WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetch_assoc()['s'];
$total_revenue = $conn->query("SELECT IFNULL(SUM(amount),0) s FROM platform_revenue")->fetch_assoc()['s'];

// آخر 6 أشهر إيرادات
$monthly_labels = []; $monthly_values = [];
for ($i = 5; $i >= 0; $i--) {
    $label = date('M Y', strtotime("-$i months"));
    $val   = $conn->query("SELECT IFNULL(SUM(amount),0) s FROM platform_revenue WHERE YEAR(payment_date)=YEAR(DATE_SUB(NOW(),INTERVAL $i MONTH)) AND MONTH(payment_date)=MONTH(DATE_SUB(NOW(),INTERVAL $i MONTH))")->fetch_assoc()['s'];
    $monthly_labels[] = $label;
    $monthly_values[] = (float)$val;
}

// توزيع الباقات
$pkg_dist = [];
$pkg_res = $conn->query("SELECT p.name, COUNT(o.id) cnt FROM packages p LEFT JOIN offices o ON o.package_id=p.id GROUP BY p.id,p.name ORDER BY p.id");
while ($pk = $pkg_res->fetch_assoc()) $pkg_dist[] = $pk;

// المكاتب الجديدة هذا الشهر
$new_this_month = $conn->query("SELECT COUNT(*) c FROM offices WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetch_assoc()['c'];

// آخر المكاتب
$offices = $conn->query("SELECT o.*, p.name pkg_name FROM offices o LEFT JOIN packages p ON o.package_id=p.id ORDER BY o.created_at DESC LIMIT 6");

// آخر إيرادات
$revenues = $conn->query("SELECT pr.*, o.name office_name, p.name pkg_name FROM platform_revenue pr LEFT JOIN offices o ON pr.office_id=o.id LEFT JOIN packages p ON pr.package_id=p.id ORDER BY pr.created_at DESC LIMIT 5");

include '../includes/admin_header.php';
?>

<!-- بطاقات الإحصائيات -->
<div class="row g-3 mb-4">
  <?php
  $stat_cards = [
    ['إجمالي المكاتب', $total_offices, 'building', 'primary', null],
    ['مكاتب نشطة', $active_offices, 'check-circle', 'success', null],
    ['جديدة هذا الشهر', $new_this_month, 'star', 'info', null],
    ['إيرادات الشهر', number_format($month_revenue).' <small>ر.س</small>', 'coins', 'warning', null],
  ];
  foreach($stat_cards as [$lbl,$val,$ico,$col,$_]):
  ?>
  <div class="col-6 col-lg-3">
    <div class="card h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle d-flex align-items-center justify-content-center bg-<?= $col ?> bg-opacity-10 text-<?= $col ?>" style="width:54px;height:54px;font-size:22px;flex-shrink:0">
          <i class="fas fa-<?= $ico ?>"></i>
        </div>
        <div>
          <div class="text-muted" style="font-size:12px"><?= $lbl ?></div>
          <div class="fw-bold fs-4"><?= $val ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- الرسوم البيانية -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <span><i class="fas fa-chart-line me-2 text-primary"></i>إيرادات آخر 6 أشهر</span>
        <span class="badge bg-success"><?= number_format($total_revenue) ?> ر.س إجمالي</span>
      </div>
      <div class="card-body">
        <div id="revenueChart" style="min-height:220px"></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fas fa-layer-group me-2 text-info"></i>توزيع الباقات</span>
        <span class="badge bg-secondary-subtle text-secondary"><?= array_sum(array_column($pkg_dist,'cnt')) ?> مكتب</span>
      </div>
      <div class="card-body p-0">
        <?php
        $total_offices = max(1, array_sum(array_column($pkg_dist, 'cnt')));
        $pkg_colors_list = ['#0c1b36','#d97706','#2563eb','#7c3aed','#059669'];
        $has_data = $total_offices > 1 || ($total_offices === 1 && (int)($pkg_dist[0]['cnt'] ?? 0) > 0);
        ?>
        <?php if (!$pkg_dist): ?>
        <div class="text-center py-5 text-muted">
          <i class="fas fa-box-open fa-2x mb-2 d-block text-secondary"></i>
          لا توجد باقات مُعرَّفة
        </div>
        <?php else: ?>
        <div id="pkgChart" style="height:140px;width:100%"></div>
        <div class="px-3 pb-3">
          <?php foreach ($pkg_dist as $i => $pk):
            $clr = $pkg_colors_list[$i % count($pkg_colors_list)];
            $pct = $total_offices > 0 ? round($pk['cnt'] / $total_offices * 100) : 0;
          ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <div style="width:10px;height:10px;border-radius:50%;background:<?= $clr ?>;flex-shrink:0"></div>
            <div style="flex:1;font-size:13px;color:#374151"><?= e($pk['name']) ?></div>
            <div class="text-end" style="min-width:60px">
              <span class="fw-bold" style="font-size:13px;color:<?= $clr ?>"><?= $pk['cnt'] ?></span>
              <span style="font-size:11px;color:#9ca3af"> (<?= $pct ?>%)</span>
            </div>
          </div>
          <div class="progress mb-2" style="height:5px">
            <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $clr ?>"></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- حالة المكاتب - شريط سريع -->
<div class="card mb-4">
  <div class="card-body py-3">
    <div class="row g-3 text-center">
      <?php
      $quick = [
        ['نشطة', $active_offices, '#22c55e'],
        ['تجريبية', $trial_offices, '#f59e0b'],
        ['منتهية', $expired_offices, '#ef4444'],
        ['موقوفة', $suspended_offices, '#6b7280'],
      ];
      foreach ($quick as [$lbl,$cnt,$clr]):
        $pct = $total_offices ? round($cnt/$total_offices*100) : 0;
      ?>
      <div class="col-6 col-md-3">
        <div class="fw-bold fs-5" style="color:<?= $clr ?>"><?= $cnt ?></div>
        <div class="text-muted" style="font-size:12px"><?= $lbl ?></div>
        <div class="progress mt-1" style="height:4px">
          <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $clr ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- جدول المكاتب -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <span><i class="fas fa-building me-2 text-primary"></i>آخر المكاتب المسجلة</span>
        <a href="offices.php" class="btn btn-sm btn-outline-primary">عرض الكل</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th>المكتب</th><th>المدينة</th><th>الباقة</th><th>الحالة</th><th>الانتهاء</th>
            </tr></thead>
            <tbody>
            <?php while($o = $offices->fetch_assoc()): ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center fw-bold" style="width:32px;height:32px;font-size:12px;flex-shrink:0">
                    <?= mb_substr($o['name'],0,1) ?>
                  </div>
                  <div>
                    <div class="fw-semibold" style="font-size:13px"><?= e($o['name']) ?></div>
                    <small class="text-muted"><?= e($o['city']) ?></small>
                  </div>
                </div>
              </td>
              <td><?= e($o['city']) ?></td>
              <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= e($o['pkg_name']) ?></span></td>
              <td><?= statusBadge($o['status']) ?></td>
              <td><?= $o['subscription_end'] ? date('Y/m/d', strtotime($o['subscription_end'])) : '—' ?></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- آخر الإيرادات -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <span><i class="fas fa-coins me-2 text-warning"></i>آخر الإيرادات</span>
        <a href="revenue.php" class="btn btn-sm btn-outline-warning">عرض الكل</a>
      </div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
        <?php while($r = $revenues->fetch_assoc()): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center px-3 py-2">
            <div>
              <div class="fw-semibold" style="font-size:13px"><?= e($r['office_name']) ?></div>
              <div class="text-muted" style="font-size:11px"><?= e($r['pkg_name']) ?> • <?= $r['payment_date'] ?></div>
            </div>
            <span class="badge bg-success"><?= number_format($r['amount']) ?> ر.س</span>
          </li>
        <?php endwhile; ?>
        </ul>
      </div>
      <div class="card-footer text-center">
        <strong>الإجمالي: <?= number_format($total_revenue) ?> ر.س</strong>
      </div>
    </div>
  </div>
</div>

<!-- ApexCharts -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
// رسم إيرادات الأشهر
new ApexCharts(document.querySelector('#revenueChart'), {
  chart: { type: 'area', height: 220, toolbar: {show:false}, fontFamily: 'Tajawal,sans-serif' },
  series: [{ name: 'الإيرادات (ر.س)', data: <?= json_encode($monthly_values) ?> }],
  xaxis: { categories: <?= json_encode($monthly_labels) ?> },
  colors: ['#e8c040'],
  fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05 } },
  stroke: { curve: 'smooth', width: 2 },
  dataLabels: { enabled: false },
  grid: { borderColor: '#f0f0f0' },
  yaxis: { labels: { formatter: v => v.toLocaleString('ar') } },
  tooltip: { y: { formatter: v => v.toLocaleString('ar') + ' ر.س' } }
}).render();

// رسم توزيع الباقات
<?php if ($pkg_dist): ?>
var _pkgSeries = <?= json_encode(array_map(fn($p) => (int)$p['cnt'], $pkg_dist)) ?>;
var _pkgEl = document.querySelector('#pkgChart');
if (_pkgEl && _pkgSeries.some(v => v > 0)) {
  new ApexCharts(_pkgEl, {
    chart: { type: 'donut', height: 140, fontFamily: 'Tajawal,sans-serif', toolbar:{show:false}, sparkline:{enabled:false} },
    series: _pkgSeries,
    labels: <?= json_encode(array_column($pkg_dist, 'name')) ?>,
    colors: ['#0c1b36','#d97706','#2563eb','#7c3aed','#059669'],
    legend: { show: false },
    plotOptions: { pie: { donut: { size: '60%', labels: { show: true, total: { show: true, label: 'المكاتب', fontSize: '11px', fontFamily: 'Tajawal,sans-serif', color: '#6b7280', formatter: function(w) { return w.globals.seriesTotals.reduce((a,b)=>a+b,0); } } } } } },
    dataLabels: { enabled: false },
    stroke: { width: 2 },
    tooltip: { y: { formatter: v => v + ' مكتب' } }
  }).render();
} else if (_pkgEl) {
  _pkgEl.style.display = 'none';
}
<?php endif; ?>
</script>

<?php include '../includes/admin_footer.php'; ?>
