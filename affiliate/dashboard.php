<?php
$page_title = 'لوحة التحكم';
require_once __DIR__ . '/../includes/affiliate_header.php';

if (!function_exists('dbVal')) {
    function dbVal($conn, $sql) { $r = $conn->query($sql); if (!$r) return 0; $row = $r->fetch_row(); return $row ? $row[0] : 0; }
}

$_site = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'mehkam.app');
$ref_link = rtrim($_site,'/') . '/public/register.php?ref=' . $_aff['ref_code'];

$clicks = (int)dbVal($conn, "SELECT COUNT(*) FROM affiliate_clicks WHERE affiliate_id=$_aff_id");
$signups = (int)dbVal($conn, "SELECT COUNT(*) FROM affiliate_referrals WHERE affiliate_id=$_aff_id");
$converted = (int)dbVal($conn, "SELECT COUNT(*) FROM affiliate_referrals WHERE affiliate_id=$_aff_id AND status='converted'");
$pending_ref = $signups - $converted;
$conv_rate = $signups > 0 ? round($converted / $signups * 100) : 0;

$referrals = $conn->query("SELECT * FROM affiliate_referrals WHERE affiliate_id=$_aff_id ORDER BY created_at DESC LIMIT 8");

/* ── عمولات آخر 6 أشهر (لرسم بياني) ── */
$months_labels = $months_values = [];
$_ymIndex = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $months_labels[] = date('n/Y', strtotime($ym . '-01'));
    $_ymIndex[$ym] = count($months_values);
    $months_values[] = 0.0;
}
$mr = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, SUM(amount) s
    FROM affiliate_wallet_tx WHERE affiliate_id=$_aff_id AND type='commission'
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym");
if ($mr) while ($x = $mr->fetch_assoc()) {
    if (isset($_ymIndex[$x['ym']])) $months_values[$_ymIndex[$x['ym']]] = (float)$x['s'];
}
?>
<style>
.af-hero{background:linear-gradient(135deg,#0c1b36,#1a3a6e);border-radius:16px;padding:28px;color:#fff;margin-bottom:20px;position:relative;overflow:hidden}
.af-hero::after{content:'';position:absolute;left:-30px;bottom:-40px;width:160px;height:160px;background:radial-gradient(circle,rgba(232,192,64,.18),transparent 70%)}
.af-stat{background:#fff;border:1px solid #eef1f6;border-radius:14px;padding:18px;height:100%;display:flex;align-items:center;gap:14px}
.af-stat-ico{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;flex-shrink:0}
.af-stat-val{font-size:22px;font-weight:900;color:#0c1b36;line-height:1.1}
.af-stat-lbl{font-size:11.5px;color:#64748b;font-weight:600}
.af-link-box{background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px}
.af-link-box code{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;color:#0c1b36}
.af-promo{background:linear-gradient(135deg,#fefce8,#fef9c3);border:1px solid #fde68a;border-radius:14px;padding:20px;height:100%;display:flex;flex-direction:column}
</style>

<div class="af-hero d-flex flex-wrap justify-content-between align-items-center gap-3">
  <div style="position:relative;z-index:1">
    <div style="font-size:13px;opacity:.75">رصيد محفظتك الحالي</div>
    <div style="font-size:34px;font-weight:900"><?= number_format($_aff['wallet_balance'],2) ?> <small style="font-size:16px;opacity:.7">ر.س</small></div>
    <div style="font-size:12px;opacity:.6">إجمالي ما كسبته: <?= number_format($_aff['total_earned'],2) ?> ر.س &nbsp;&middot;&nbsp; نسبة عمولتك: <?= (float)$_aff['commission_pct'] ?>%</div>
  </div>
  <a href="withdraw.php" class="btn fw-bold" style="background:#e8c040;color:#17233d;position:relative;z-index:1"><i class="fas fa-money-bill-transfer me-1"></i>طلب سحب</a>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="af-stat"><div class="af-stat-ico" style="background:#2563eb"><i class="fas fa-mouse-pointer"></i></div>
      <div><div class="af-stat-val"><?= $clicks ?></div><div class="af-stat-lbl">نقرات على رابطك</div></div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="af-stat"><div class="af-stat-ico" style="background:#7c3aed"><i class="fas fa-user-plus"></i></div>
      <div><div class="af-stat-val"><?= $signups ?></div><div class="af-stat-lbl">مكاتب سجّلت</div></div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="af-stat"><div class="af-stat-ico" style="background:#16a34a"><i class="fas fa-circle-check"></i></div>
      <div><div class="af-stat-val"><?= $converted ?></div><div class="af-stat-lbl">اشتراكات مدفوعة</div></div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="af-stat"><div class="af-stat-ico" style="background:#d97706"><i class="fas fa-percent"></i></div>
      <div><div class="af-stat-val"><?= $conv_rate ?>%</div><div class="af-stat-lbl">معدّل التحويل</div></div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header"><i class="fas fa-chart-line me-2 text-primary"></i>أرباحك — آخر 6 أشهر</div>
      <div class="card-body"><div id="afEarnChart" style="min-height:220px"></div></div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-clock-rotate-left me-2 text-secondary"></i>أحدث الإحالات</span>
        <?php if ($pending_ref > 0): ?><span class="badge bg-warning-subtle text-warning"><?= $pending_ref ?> بانتظار الدفع</span><?php endif; ?>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>المكتب</th><th>الحالة</th><th>العمولة</th><th>التاريخ</th></tr></thead>
            <tbody>
            <?php if ($referrals && $referrals->num_rows): while ($r = $referrals->fetch_assoc()): ?>
            <tr>
              <td><?= e($r['office_name'] ?: ('#'.$r['office_id'])) ?></td>
              <td>
                <?php if ($r['status'] === 'converted'): ?>
                <span class="badge bg-success-subtle text-success">اشترك</span>
                <?php else: ?>
                <span class="badge bg-warning-subtle text-warning">بانتظار الدفع</span>
                <?php endif; ?>
              </td>
              <td class="fw-semibold"><?= $r['status']==='converted' ? number_format($r['commission_amount'],2).' ر.س' : '—' ?></td>
              <td style="font-size:12px"><?= date('Y/m/d', strtotime($r['created_at'])) ?></td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="4" class="text-center text-muted py-4">ما فيه إحالات بعد — شارك رابطك وابدأ</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-4 d-flex flex-column">
    <div class="card mb-3">
      <div class="card-header"><i class="fas fa-link me-2 text-primary"></i>رابط الإحالة الخاص بك</div>
      <div class="card-body">
        <div class="af-link-box mb-2">
          <code dir="ltr" id="af_link1"><?= e($ref_link) ?></code>
        </div>
        <button class="btn btn-sm btn-outline-primary w-100 mb-2" onclick="afCopy('af_link1',this)"><i class="fas fa-copy me-1"></i>نسخ الرابط</button>
        <div class="text-muted" style="font-size:12px">أي مكتب يسجّل عن طريقه ويشترك، تحصل على <?= (float)$_aff['commission_pct'] ?>% من أول دفعة له تلقائياً.</div>
        <div class="mt-2" style="font-size:11px;color:#94a3b8">كودك: <b><?= e($_aff['ref_code']) ?></b></div>
      </div>
    </div>

    <div class="af-promo flex-grow-1">
      <div class="fw-bold mb-1" style="color:#78350f"><i class="fas fa-bullhorn me-1"></i>وسّع تسويقك</div>
      <div style="font-size:12.5px;color:#92400e;flex:1">رمز QR، نصوص واتساب وX جاهزة، وقالب بريد إلكتروني — كلها بتنتظرك بصفحة أدوات التسويق.</div>
      <a href="marketing.php" class="btn btn-sm fw-bold mt-2" style="background:#78350f;color:#fff"><i class="fas fa-arrow-left me-1"></i>افتح أدوات التسويق</a>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
function afCopy(id, btn) {
  var txt = document.getElementById(id).textContent.trim();
  navigator.clipboard.writeText(txt).then(function () {
    var old = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check me-1"></i>تم النسخ';
    setTimeout(function () { btn.innerHTML = old; }, 1500);
  });
}
(function initChart(tries){
  if (typeof ApexCharts === 'undefined') {
    if ((tries||0) < 20) return setTimeout(function(){ initChart((tries||0)+1); }, 150);
    return;
  }
  var el = document.getElementById('afEarnChart');
  if (!el) return;
  new ApexCharts(el, {
    chart: { type: 'area', height: 220, toolbar: {show:false}, fontFamily: 'Tajawal,sans-serif' },
    series: [{ name: 'العمولة (ر.س)', data: <?= json_encode(array_map('floatval',$months_values)) ?> }],
    xaxis: { categories: <?= json_encode($months_labels) ?> },
    colors: ['#e8c040'],
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05 } },
    stroke: { curve: 'smooth', width: 2 },
    dataLabels: { enabled: false },
    grid: { borderColor: '#f0f0f0' },
    yaxis: { labels: { formatter: function(v){ return v.toLocaleString('ar'); } } },
    tooltip: { y: { formatter: function(v){ return v.toLocaleString('ar') + ' ر.س'; } } }
  }).render();
})();
</script>

<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
