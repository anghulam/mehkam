<?php
require_once '../includes/functions.php';
require_once '../config/db.php';
requireOffice();
if (currentRole() !== 'office_owner') { header('Location: dashboard.php?msg=denied'); exit; }
$page_title = 'أداء الفريق';
$oid = (int)$_SESSION['office_id'];

if (!hasFeature($conn, $oid, 'has_reports')) {
    header("Location: profile.php?tab=upgrade&feature=reports"); exit;
}

foreach ([
    "ALTER TABLE tasks ADD COLUMN assigned_to_id INT DEFAULT NULL",
    "ALTER TABLE tasks ADD COLUMN completed_at DATETIME DEFAULT NULL",
] as $_d) { try { $conn->query($_d); } catch (\Throwable $e) {} }

$period = in_array($_GET['period'] ?? '', ['day','week','month','all'], true) ? $_GET['period'] : 'month';
$since = [
    'day'   => date('Y-m-d 00:00:00'),
    'week'  => date('Y-m-d 00:00:00', strtotime('-6 days')),
    'month' => date('Y-m-01 00:00:00'),
    'all'   => '2000-01-01 00:00:00',
][$period];
$se = $conn->real_escape_string($since);
$today = date('Y-m-d');

$rows = [];
$ur = $conn->query("SELECT id, full_name, role FROM users WHERE office_id=$oid AND is_active=1 ORDER BY full_name");
if ($ur) while ($u = $ur->fetch_assoc()) {
    $uid = (int)$u['id'];
    $g = function ($sql) use ($conn) { $r = $conn->query($sql); return $r ? (int)($r->fetch_assoc()['x'] ?? 0) : 0; };

    $assigned  = $g("SELECT COUNT(*) x FROM tasks WHERE office_id=$oid AND assigned_to_id=$uid AND created_at>='$se'");
    $done      = $g("SELECT COUNT(*) x FROM tasks WHERE office_id=$oid AND assigned_to_id=$uid AND status='completed' AND completed_at>='$se'");
    $late      = $g("SELECT COUNT(*) x FROM tasks WHERE office_id=$oid AND assigned_to_id=$uid AND status IN('pending','in_progress') AND due_date<'$today'");
    $open      = $g("SELECT COUNT(*) x FROM tasks WHERE office_id=$oid AND assigned_to_id=$uid AND status IN('pending','in_progress')");
    $actions   = $g("SELECT COUNT(*) x FROM activity_log WHERE office_id=$oid AND user_id=$uid AND created_at>='$se'");

    $avgR = $conn->query("SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) a
        FROM tasks WHERE office_id=$oid AND assigned_to_id=$uid AND status='completed' AND completed_at IS NOT NULL AND completed_at>='$se'");
    $avgH = $avgR ? ($avgR->fetch_assoc()['a']) : null;

    $totalForPct = $done + $open;
    $pct = $totalForPct ? round($done / $totalForPct * 100) : null;

    $rows[] = compact('u','assigned','done','late','open','actions','avgH','pct');
}

// إجماليات
$T = ['assigned'=>0,'done'=>0,'open'=>0,'late'=>0,'actions'=>0];
foreach ($rows as $r) foreach ($T as $k=>$v) $T[$k] += $r[$k];
$team_pct = ($T['done'] + $T['open']) ? round($T['done'] / ($T['done'] + $T['open']) * 100) : 100;
$max_actions = 0;
foreach ($rows as $r) $max_actions = max($max_actions, (int)$r['actions']);

// ترتيب: الأعلى إنجازاً أولاً، ثم الأكثر نشاطاً
usort($rows, function ($a, $b) {
    $pa = $a['pct'] === null ? -1 : $a['pct'];
    $pb = $b['pct'] === null ? -1 : $b['pct'];
    if ($pb !== $pa) return $pb <=> $pa;
    return ($b['done'] <=> $a['done']) ?: ($b['actions'] <=> $a['actions']);
});

$roleLabels = ['office_owner'=>'مالك','lawyer'=>'محامٍ','secretary'=>'سكرتير','trainee'=>'متدرّب','accountant'=>'محاسب'];
$periodLabels = ['day'=>'اليوم','week'=>'آخر 7 أيام','month'=>'هذا الشهر','all'=>'كل الفترات'];
function pf_initials($n) { $p = preg_split('/\s+/', trim($n)); return mb_substr($p[0] ?? '', 0, 1) . (isset($p[1]) ? mb_substr($p[1], 0, 1) : ''); }

$C = 326.726;
$ringOff = round($C * (1 - min(100, max(0, $team_pct)) / 100), 1);

include '../includes/office_header.php';
?>

<style>
.perf{margin-bottom:1.4rem}
.perf *{box-sizing:border-box}
.perf-hero{display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;
  background:linear-gradient(135deg,var(--mk-navy) 0%,var(--mk-navy4) 100%);
  border-radius:16px;padding:22px 26px;color:#fff;position:relative;overflow:hidden}
.perf-hero::after{content:"";position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(circle at 90% -30%,rgba(184,134,11,.4),transparent 46%)}
.perf-hero-l{position:relative;z-index:1;min-width:0}
.perf-eyebrow{font-size:12px;letter-spacing:.5px;color:var(--mk-gold4);font-weight:700;margin-bottom:6px}
.perf-hero-l h1{font-size:20px;font-weight:800;margin:0}
.perf-hero-l p{font-size:12.5px;opacity:.6;margin:4px 0 0}
.perf-ring{position:relative;width:100px;height:100px;flex-shrink:0;z-index:1}
.perf-ring svg{width:100%;height:100%;transform:rotate(-90deg)}
.perf-ring circle{fill:none;stroke-width:9;stroke-linecap:round}
.perf-ring .bg{stroke:rgba(255,255,255,.14)}
.perf-ring .fg{stroke:var(--mk-gold4);transition:stroke-dashoffset .9s ease}
.perf-ring-t{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
.perf-ring-t b{font-size:22px;font-weight:800;line-height:1}
.perf-ring-t small{font-size:9px;opacity:.7;margin-top:3px}

.perf-tabs{display:inline-flex;gap:2px;background:var(--mk-bg2);border-radius:10px;padding:3px;margin:16px 0}
.perf-tabs a{padding:6px 16px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--mk-t3);text-decoration:none;transition:.15s}
.perf-tabs a.on{background:var(--mk-card);color:var(--mk-navy);box-shadow:var(--sh-sm)}

.perf-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
.perf-kpi{background:var(--mk-card);border:1px solid var(--mk-border);border-radius:12px;padding:14px 16px;box-shadow:var(--sh-sm);display:flex;align-items:center;gap:12px}
.perf-kpi i{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.perf-kpi.a i{background:rgba(37,99,235,.1);color:var(--c-info)}
.perf-kpi.b i{background:rgba(5,150,105,.1);color:var(--c-success)}
.perf-kpi.c i{background:rgba(220,38,38,.1);color:var(--c-danger)}
.perf-kpi.d i{background:rgba(184,134,11,.12);color:var(--mk-gold2)}
.perf-kpi b{font-size:22px;font-weight:800;color:var(--mk-t1);display:block;line-height:1}
.perf-kpi span{font-size:11.5px;color:var(--mk-t4)}

.perf-card{background:var(--mk-card);border:1px solid var(--mk-border);border-radius:14px;box-shadow:var(--sh-sm);overflow:hidden}
.perf-table{width:100%;border-collapse:collapse;font-size:13px}
.perf-table thead th{background:var(--mk-bg);color:var(--mk-t3);font-size:11.5px;font-weight:700;
  padding:11px 14px;text-align:right;white-space:nowrap;border-bottom:1px solid var(--mk-border)}
.perf-table tbody td{padding:12px 14px;border-bottom:1px solid var(--mk-bg2);vertical-align:middle}
.perf-table tbody tr:last-child td{border-bottom:0}
.perf-table tbody tr:hover{background:var(--mk-bg)}
.perf-user{display:flex;align-items:center;gap:10px;min-width:170px}
.perf-av{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--mk-navy3),var(--mk-navy5));
  color:var(--mk-gold4);font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.perf-rank{width:22px;height:22px;border-radius:50%;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:var(--mk-bg2);color:var(--mk-t3)}
.perf-rank.g1{background:#fef3c7;color:#b45309}.perf-rank.g2{background:#e2e8f0;color:#475569}.perf-rank.g3{background:#fde9d9;color:#c2410c}
.perf-nm{font-weight:600;color:var(--mk-t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.perf-role{font-size:10px;color:var(--mk-t4)}
.perf-bar{display:flex;align-items:center;gap:8px;min-width:150px}
.perf-bar .track{flex:1;height:8px;border-radius:5px;background:var(--mk-bg2);overflow:hidden}
.perf-bar .fill{height:100%;border-radius:5px}
.perf-bar b{font-size:12px;width:34px;text-align:left}
.perf-mini{display:inline-block;height:5px;border-radius:3px;background:var(--mk-gold3);vertical-align:middle}
.perf-num{font-weight:700}
.perf-hint{font-size:11px;color:var(--mk-t4);margin-top:10px;line-height:1.7}
@media(max-width:820px){
  .perf-kpis{grid-template-columns:repeat(2,1fr)}
  .perf-wrap{overflow-x:auto}
}
</style>

<section class="perf">
  <div class="perf-hero">
    <div class="perf-hero-l">
      <div class="perf-eyebrow"><i class="fas fa-chart-line me-1"></i>مؤشر أداء الفريق</div>
      <h1>أداء الفريق</h1>
      <p><?= e($periodLabels[$period]) ?> · <?= count($rows) ?> مستخدم · <?= (int)$T['done'] ?> مهمة منجزة</p>
    </div>
    <div class="perf-ring" title="نسبة إنجاز مهام الفريق">
      <svg viewBox="0 0 120 120">
        <circle class="bg" cx="60" cy="60" r="52"></circle>
        <circle class="fg" cx="60" cy="60" r="52" stroke-dasharray="<?= $C ?>" stroke-dashoffset="<?= $ringOff ?>"></circle>
      </svg>
      <div class="perf-ring-t"><b><?= (int)$team_pct ?>%</b><small>إنجاز الفريق</small></div>
    </div>
  </div>

  <div class="perf-tabs">
    <?php foreach ($periodLabels as $k => $l): ?>
    <a href="?period=<?= $k ?>" class="<?= $period===$k?'on':'' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="perf-kpis">
    <div class="perf-kpi a"><i class="fas fa-inbox"></i><div><b><?= (int)$T['assigned'] ?></b><span>مهام مُسندة</span></div></div>
    <div class="perf-kpi b"><i class="fas fa-circle-check"></i><div><b><?= (int)$T['done'] ?></b><span>منجزة</span></div></div>
    <div class="perf-kpi c"><i class="fas fa-clock-rotate-left"></i><div><b><?= (int)$T['late'] ?></b><span>متأخرة</span></div></div>
    <div class="perf-kpi d"><i class="fas fa-bolt"></i><div><b><?= (int)$T['actions'] ?></b><span>إجراءات مسجّلة</span></div></div>
  </div>

  <div class="perf-card perf-wrap">
    <table class="perf-table">
      <thead><tr>
        <th>المستخدم</th><th>مُسندة</th><th>منجزة</th><th>مفتوحة</th><th>متأخرة</th>
        <th style="min-width:160px">نسبة الإنجاز</th><th>متوسط مدة الإنجاز</th><th>الإجراءات</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): $p = $r['pct'];
        $col = $p === null ? 'var(--mk-t5)' : ($p >= 75 ? 'var(--c-success)' : ($p >= 40 ? 'var(--c-warning)' : 'var(--c-danger)'));
        $rank = $i + 1;
      ?>
      <tr>
        <td>
          <div class="perf-user">
            <span class="perf-rank <?= $rank<=3 && $p!==null ? 'g'.$rank : '' ?>"><?= $rank ?></span>
            <span class="perf-av"><?= e(pf_initials($r['u']['full_name'])) ?></span>
            <span style="min-width:0">
              <span class="perf-nm d-block"><?= e($r['u']['full_name']) ?></span>
              <span class="perf-role"><?= e($roleLabels[$r['u']['role']] ?? $r['u']['role']) ?></span>
            </span>
          </div>
        </td>
        <td class="perf-num"><?= (int)$r['assigned'] ?></td>
        <td class="perf-num" style="color:var(--c-success)"><?= (int)$r['done'] ?></td>
        <td class="perf-num"><?= (int)$r['open'] ?></td>
        <td class="perf-num" style="<?= $r['late']>0?'color:var(--c-danger)':'color:var(--mk-t4)' ?>"><?= (int)$r['late'] ?></td>
        <td>
          <?php if ($p === null): ?>
          <span class="text-muted" style="font-size:12px">لا مهام</span>
          <?php else: ?>
          <div class="perf-bar">
            <span class="track"><span class="fill" style="width:<?= max(3,$p) ?>%;background:<?= $col ?>"></span></span>
            <b style="color:<?= $col ?>"><?= $p ?>%</b>
            <?php if ($p >= 100): ?><i class="fas fa-circle-check" style="color:var(--c-success);font-size:12px"></i>
            <?php elseif ($r['late'] > 0): ?><i class="fas fa-triangle-exclamation" style="color:var(--c-warning);font-size:12px"></i><?php endif; ?>
          </div>
          <?php endif; ?>
        </td>
        <td style="font-size:12.5px"><?= $r['avgH'] !== null ? '<b>'.round($r['avgH']/24,1).'</b> يوم' : '<span class="text-muted">—</span>' ?></td>
        <td>
          <span class="perf-num"><?= (int)$r['actions'] ?></span>
          <?php if ($max_actions > 0 && $r['actions'] > 0): ?>
          <span class="perf-mini" style="width:<?= max(6, round($r['actions'] / $max_actions * 46)) ?>px"></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
      <tr><td colspan="8" class="text-center text-muted py-4">لا مستخدمين نشطين</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <p class="perf-hint">
    <i class="fas fa-circle-info me-1"></i>
    «نسبة الإنجاز» = المهام المنجزة ÷ (المنجزة + المفتوحة). «متوسط مدة الإنجاز» = المدة بين إنشاء المهمة وإكمالها.
    «الإجراءات» من سجل الإجراءات خلال <?= e($periodLabels[$period]) ?>. الترتيب حسب نسبة الإنجاز.
  </p>
</section>

<?php include '../includes/office_footer.php'; ?>
