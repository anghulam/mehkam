<?php
/**
 * public/includes/pricing_ui.php — عرض الباقات وجدول المقارنة (الرئيسية + صفحة الأسعار)
 *
 *   require_once 'includes/pricing_ui.php';
 *   $cat = pkg_public_catalog($conn);          // من includes/module_helper.php
 *   pricing_ui_styles();                       // مرة واحدة في الصفحة
 *   pricing_ui_cards($cat);                    // بطاقات الباقات
 *   pricing_ui_compare($cat, ['collapsed' => true]);   // جدول المقارنة الكامل
 *
 * الجدول يُبنى من بيانات الباقات الفعلية (حدود + ميزات أساسية + كل الموديولات المسجّلة)،
 * فأي تفعيل/إيقاف يجريه الأدمن في «الباقات وتخصيص المزايا» ينعكس هنا مباشرة، وأي موديول جديد يظهر تلقائياً.
 */

function pricing_ui_limit_text($v, $unit = '') {
    return pkg_is_unlimited($v) ? 'غير محدود' : number_format((int)$v) . ($unit !== '' ? ' ' . $unit : '');
}

function pricing_ui_storage_text($mb) {
    $mb = (int)$mb;
    if ($mb <= 0) return 'غير متوفر';
    return $mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.') . ' جيجابايت' : $mb . ' ميجابايت';
}

function pricing_ui_styles() {
    static $done = false;
    if ($done) return;
    $done = true;
?>
<style>
/* ═══ بطاقات الباقات ═══ */
.pk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(285px,1fr));gap:22px;align-items:stretch}
.pk-card{position:relative;display:flex;flex-direction:column;background:linear-gradient(180deg,var(--g3) 0%,var(--g2) 100%);border:1px solid var(--b2);border-radius:22px;padding:30px 26px 26px;transition:transform .3s,box-shadow .3s,border-color .3s;overflow:hidden}
.pk-card::before{content:'';position:absolute;inset:0 0 auto 0;height:3px;background:linear-gradient(90deg,transparent,var(--gold3),transparent);opacity:.0;transition:opacity .3s}
.pk-card:hover{transform:translateY(-6px);box-shadow:var(--sh2);border-color:var(--b1)}
.pk-card:hover::before{opacity:.7}
.pk-card.feat{border-color:var(--gold3);box-shadow:0 0 0 4px var(--b3),var(--sh2);background:linear-gradient(180deg,#151b2c 0%,var(--g2) 100%)}
.pk-card.feat::before{opacity:1}
.pk-ribbon{position:absolute;top:16px;left:16px;background:linear-gradient(135deg,var(--gold),var(--gold4));color:var(--g);font-size:11px;font-weight:800;padding:4px 12px;border-radius:50px;box-shadow:var(--sh-gold)}
.pk-head{display:flex;align-items:center;gap:14px;margin-bottom:20px}
.pk-ico{width:50px;height:50px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:21px;flex-shrink:0}
.pk-name{font-size:21px;font-weight:900;color:var(--white);line-height:1.2}
.pk-sub{font-size:12px;color:var(--t3)}
.pk-price{display:flex;align-items:baseline;gap:6px;margin-bottom:4px;flex-wrap:wrap}
.pk-amount{font-size:46px;font-weight:900;color:var(--white);line-height:1;letter-spacing:-2px}
.pk-card.feat .pk-amount{color:var(--gold4)}
.pk-cur{font-size:15px;font-weight:700;color:var(--t3)}
.pk-per{font-size:13px;color:var(--t3)}
.pk-custom{font-size:26px;font-weight:900;color:var(--white)}
.pk-note{font-size:12px;color:var(--t3);min-height:18px;margin-bottom:18px}
.pk-limits{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px}
.pk-lim{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:12px;padding:10px 12px;display:flex;align-items:center;gap:10px;min-width:0}
.pk-lim i{width:30px;height:30px;border-radius:9px;background:var(--b2);color:var(--gold4);display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.pk-lim b{display:block;font-size:13.5px;font-weight:800;color:var(--white);line-height:1.2}
.pk-lim b.inf{color:var(--gold4)}
.pk-lim span{display:block;font-size:11px;color:var(--t3)}
.pk-meter{margin-bottom:14px}
.pk-meter-top{display:flex;justify-content:space-between;font-size:12px;color:var(--t2);margin-bottom:6px}
.pk-meter-top b{color:var(--gold4);font-weight:800}
.pk-bar{height:6px;border-radius:50px;background:rgba(255,255,255,.08);overflow:hidden}
.pk-bar i{display:block;height:100%;border-radius:50px;background:linear-gradient(90deg,var(--gold),var(--gold4))}
.pk-bar.mod i{background:linear-gradient(90deg,#7c3aed,#a78bfa)}
.pk-feats{list-style:none;margin:6px 0 20px;padding:0;flex:1}
.pk-feats li{display:flex;align-items:center;gap:10px;font-size:13.5px;color:var(--t2);padding:6px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.pk-feats li:last-child{border:none}
.pk-feats .ck{width:18px;height:18px;border-radius:50%;background:var(--b2);border:1px solid var(--b1);color:var(--gold3);display:flex;align-items:center;justify-content:center;font-size:9px;flex-shrink:0}
.pk-feats li.off{color:var(--t4)}
.pk-feats li.off .ck{background:transparent;border-color:rgba(255,255,255,.1);color:var(--t4)}
.pk-feats li.more a{color:var(--gold4);font-weight:700;font-size:13px}
.pk-cta{display:block;text-align:center;padding:14px;border-radius:12px;font-size:15px;font-weight:800;transition:all .25s;font-family:'Tajawal',sans-serif}
.pk-cta.gold{background:linear-gradient(135deg,var(--gold),var(--gold4));color:var(--g);box-shadow:var(--sh-gold)}
.pk-cta.gold:hover{box-shadow:var(--sh-gold2);transform:translateY(-1px);color:var(--g)}
.pk-cta.line{border:1px solid var(--b1);color:var(--t1);background:transparent}
.pk-cta.line:hover{border-color:var(--gold3);color:var(--gold4);background:var(--b2)}
.pk-cta-note{text-align:center;font-size:12px;color:var(--t3);margin:10px 0 0}

/* ═══ جدول المقارنة ═══ */
.pk-cmp-head{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:16px}
.pk-cmp-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.pk-chip{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.05);border:1px solid var(--b1);color:var(--t2);font-size:13px;font-weight:600;padding:8px 16px;border-radius:50px;cursor:pointer;user-select:none;font-family:'Tajawal',sans-serif;transition:all .2s}
.pk-chip:hover{color:var(--gold4);border-color:var(--gold3)}
.pk-chip.on{background:var(--b2);color:var(--gold4);border-color:var(--gold3)}
.pk-cmp-wrap{overflow-x:auto;border:1px solid var(--b2);border-radius:20px;background:var(--g2);-webkit-overflow-scrolling:touch}
.pk-cmp{width:100%;min-width:680px;border-collapse:separate;border-spacing:0}
.pk-cmp th,.pk-cmp td{padding:13px 16px;font-size:13.5px;border-bottom:1px solid rgba(255,255,255,.05);text-align:center;vertical-align:middle}
.pk-cmp thead th{position:sticky;top:0;z-index:3;background:#10172a;border-bottom:1px solid var(--b1);padding:18px 14px}
.pk-cmp thead th:first-child,.pk-cmp tbody th{text-align:right}
.pk-cmp .pk-col-name{font-size:16px;font-weight:900;color:var(--white)}
.pk-cmp .pk-col-price{font-size:13px;color:var(--t3);margin-top:2px}
.pk-cmp .pk-col-price b{color:var(--gold4);font-size:15px}
.pk-cmp th.feat,.pk-cmp td.feat{background:rgba(184,134,11,.06)}
.pk-cmp thead th.feat{background:#1a1f2b;border-top:3px solid var(--gold3)}
.pk-cmp tbody th{font-weight:600;color:var(--t1);background:var(--g2);position:sticky;right:0;z-index:2;min-width:230px;max-width:300px}
.pk-cmp thead th:first-child{position:sticky;right:0;z-index:4;min-width:230px}
.pk-cmp tbody th small{display:block;font-size:11px;font-weight:400;color:var(--t3);line-height:1.5;margin-top:2px}
.pk-cmp tbody th i.ri{width:26px;height:26px;border-radius:8px;background:var(--b2);color:var(--gold4);display:inline-flex;align-items:center;justify-content:center;font-size:11px;margin-left:10px;vertical-align:middle}
.pk-cmp .pk-sec th{background:#0b1020;color:var(--gold4);font-size:12px;font-weight:800;letter-spacing:.8px;padding:11px 16px;text-transform:uppercase;border-bottom:1px solid var(--b2)}
.pk-cmp .pk-sec td{background:#0b1020;border-bottom:1px solid var(--b2)}
.pk-cmp .pk-cat{cursor:pointer}
.pk-cmp .pk-cat th{background:#0f1526;color:var(--t1);font-weight:800;font-size:13px}
.pk-cmp .pk-cat td{background:#0f1526;color:var(--t2);font-size:12.5px;font-weight:700}
.pk-cmp .pk-cat th i.tg{font-size:11px;color:var(--gold3);margin-left:8px;transition:transform .25s}
.pk-cmp .pk-cat.closed th i.tg{transform:rotate(90deg)}
.pk-cmp .pk-cat:hover th,.pk-cmp .pk-cat:hover td{background:#141b30}
.pk-cmp tr.hide{display:none}
.pk-yes{display:inline-flex;width:26px;height:26px;border-radius:50%;background:rgba(34,197,94,.14);color:#4ade80;align-items:center;justify-content:center;font-size:12px}
.pk-no{display:inline-flex;width:26px;height:26px;align-items:center;justify-content:center;color:var(--t4);font-size:15px}
.pk-val{font-weight:800;color:var(--white)}
.pk-val.inf{color:var(--gold4)}
.pk-val.na{color:var(--t4);font-weight:600}
.pk-cmp tfoot td{padding:20px 14px;background:#10172a;border-bottom:none}
.pk-cmp-cta{display:inline-block;padding:11px 22px;border-radius:11px;font-size:14px;font-weight:800;font-family:'Tajawal',sans-serif}
.pk-collapse{display:none}
.pk-collapse.open{display:block}
@media(max-width:576px){
  .pk-amount{font-size:40px}
  .pk-card{padding:26px 20px 22px}
  .pk-cmp th,.pk-cmp td{padding:11px 10px;font-size:12.5px}
  .pk-cmp tbody th,.pk-cmp thead th:first-child{min-width:170px}
}
</style>
<?php
}

/** بطاقات الباقات */
function pricing_ui_cards(array $cat, array $opts = []) {
    $icons  = ['seedling', 'star', 'building', 'crown'];
    $bgs    = ['#334155', 'linear-gradient(135deg,#b8860b,#e8c040)', '#0f2040', '#4c1d95'];
    $iclr   = ['#fff', '#080c14', '#fff', '#fff'];
    $coreN  = count($cat['core']);
    $modN   = (int)$cat['module_total'];
    $maxFeat = (int)($opts['max_feats'] ?? 6);
    $cmpHref = $opts['compare_href'] ?? '#compare';
    ?>
<div class="pk-grid">
<?php foreach ($cat['packages'] as $i => $p):
    $custom   = $p['price'] <= 0;
    $coreOn   = count($p['core_on']);
    $modOn    = count($p['mod_on']);
    $shown    = array_slice($p['core_on'], 0, $maxFeat);
    $restCore = $coreOn - count($shown);
    ?>
  <article class="pk-card <?= $p['featured'] ? 'feat' : '' ?>">
    <?php if ($p['featured']): ?><span class="pk-ribbon">⭐ الأكثر اختياراً</span><?php endif; ?>
    <div class="pk-head">
      <div class="pk-ico" style="background:<?= $bgs[$i % 4] ?>"><i class="fas fa-<?= $icons[$i % 4] ?>" style="color:<?= $iclr[$i % 4] ?>"></i></div>
      <div>
        <div class="pk-name"><?= e($p['name']) ?></div>
        <div class="pk-sub"><?= $coreOn ?> ميزة أساسية<?= $modOn ? ' · ' . $modOn . ' موديول' : '' ?></div>
      </div>
    </div>

    <?php if ($custom): ?>
    <div class="pk-price"><span class="pk-custom">بالتفاهم</span></div>
    <div class="pk-note">سعر مخصص حسب احتياج مكتبك</div>
    <?php else: ?>
    <div class="pk-price">
      <span class="pk-amount"><?= number_format($p['price']) ?></span>
      <span class="pk-cur">ر.س</span>
      <span class="pk-per">/ سنة</span>
    </div>
    <div class="pk-note">تجربة مجانية 14 يوماً · بدون بطاقة ائتمان</div>
    <?php endif; ?>

    <div class="pk-limits">
      <?php foreach ([
          ['users',   'المستخدمون', 'users',        pricing_ui_limit_text($p['users']),   pkg_is_unlimited($p['users'])],
          ['cases',   'القضايا',    'gavel',        pricing_ui_limit_text($p['cases']),   pkg_is_unlimited($p['cases'])],
          ['clients', 'العملاء',    'address-book', pricing_ui_limit_text($p['clients']), pkg_is_unlimited($p['clients'])],
          ['storage', 'التخزين',    'hard-drive',   pricing_ui_storage_text($p['storage']), $p['storage'] > 0 && $p['storage'] >= 102400],
      ] as [$_k, $lbl, $ico, $txt, $inf]): ?>
      <div class="pk-lim"><i class="fas fa-<?= $ico ?>"></i><div><b class="<?= $inf ? 'inf' : '' ?>"><?= e($txt) ?></b><span><?= $lbl ?></span></div></div>
      <?php endforeach; ?>
    </div>

    <div class="pk-meter">
      <div class="pk-meter-top"><span>الميزات الأساسية</span><b><?= $coreOn ?>/<?= $coreN ?></b></div>
      <div class="pk-bar"><i style="width:<?= $coreN ? round($coreOn / $coreN * 100) : 0 ?>%"></i></div>
    </div>
    <?php if ($modN): ?>
    <div class="pk-meter">
      <div class="pk-meter-top"><span>الموديولات الإضافية</span><b><?= $modOn ?>/<?= $modN ?></b></div>
      <div class="pk-bar mod"><i style="width:<?= round($modOn / $modN * 100) ?>%"></i></div>
    </div>
    <?php endif; ?>

    <ul class="pk-feats">
      <?php foreach ($shown as $fk): ?>
      <li><span class="ck"><i class="fas fa-check"></i></span><?= e($cat['core'][$fk][0]) ?></li>
      <?php endforeach; ?>
      <?php if (!$shown): ?><li class="off"><span class="ck"><i class="fas fa-minus"></i></span>الأساسيات فقط</li><?php endif; ?>
      <?php if ($restCore > 0 || $modOn > 0): ?>
      <li class="more"><span class="ck"><i class="fas fa-plus"></i></span>
        <a href="<?= e($cmpHref) ?>"><?= $restCore > 0 ? '+ ' . $restCore . ' ميزة' : '' ?><?= $restCore > 0 && $modOn > 0 ? ' و' : '' ?><?= $modOn > 0 ? $modOn . ' موديول إضافي' : '' ?> — عرض التفاصيل</a></li>
      <?php endif; ?>
    </ul>

    <?php if ($custom): ?>
    <a href="register.php?package=<?= $p['id'] ?>" class="pk-cta line"><i class="fas fa-headset me-1"></i>اطلب عرض سعر</a>
    <?php else: ?>
    <a href="register.php?package=<?= $p['id'] ?>&billing=yearly" class="pk-cta <?= $p['featured'] ? 'gold' : 'line' ?>">ابدأ التجربة المجانية</a>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<?php
}

/** جدول المقارنة الكامل (حدود + ميزات أساسية + كل الموديولات مجمَّعة بتصنيفها) */
function pricing_ui_compare(array $cat, array $opts = []) {
    $collapsed = !empty($opts['collapsed']);   // على الرئيسية: يبدأ مطويّاً خلف زر
    $id        = $opts['id'] ?? 'compare';
    $pk        = $cat['packages'];
    if (!$pk) return;
    $n = count($pk);
    $yes = '<span class="pk-yes" title="مضمّن"><i class="fas fa-check"></i></span>';
    $no  = '<span class="pk-no" title="غير متاح">—</span>';

    // صف قيمة: تُرمَّز كل قيم الصف لمعرفة هل الباقات متطابقة (لزر «إظهار الفروقات فقط»)
    $row = function ($label, $icon, $hint, array $cells, array $sigs) use ($pk, $n) {
        $same = count(array_unique($sigs)) === 1;
        echo '<tr class="pk-row" data-same="' . ($same ? '1' : '0') . '"><th scope="row"><i class="fas fa-' . e($icon ?: 'circle') . ' ri"></i>' . e($label)
           . ($hint !== '' ? '<small>' . e($hint) . '</small>' : '') . '</th>';
        foreach ($cells as $i => $c) echo '<td class="' . ($pk[$i]['featured'] ? 'feat' : '') . '">' . $c . '</td>';
        echo '</tr>';
    };
    $feat = fn($i) => $pk[$i]['featured'] ? 'feat' : '';
    ?>
<div id="<?= e($id) ?>-box" class="<?= $collapsed ? 'pk-collapse' : 'pk-collapse open' ?>">
  <div class="pk-cmp-head">
    <div>
      <div class="lx-eye" style="margin-bottom:6px">مقارنة تفصيلية</div>
      <h3 class="lx-h" style="font-size:26px;margin:0">كل ما في كل باقة — <span class="gt">بما فيها الموديولات</span></h3>
    </div>
    <div class="pk-cmp-tools">
      <button type="button" class="pk-chip" id="<?= e($id) ?>-diff"><i class="fas fa-code-compare"></i>الفروقات فقط</button>
      <button type="button" class="pk-chip" id="<?= e($id) ?>-fold"><i class="fas fa-layer-group"></i>طيّ الموديولات</button>
    </div>
  </div>

  <div class="pk-cmp-wrap">
    <table class="pk-cmp" id="<?= e($id) ?>-tbl">
      <thead>
        <tr>
          <th>الميزة</th>
          <?php foreach ($pk as $p): ?>
          <th class="<?= $p['featured'] ? 'feat' : '' ?>">
            <div class="pk-col-name"><?= e($p['name']) ?></div>
            <div class="pk-col-price"><?= $p['price'] > 0 ? '<b>' . number_format($p['price']) . '</b> ر.س / سنة' : 'بالتفاهم' ?></div>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <tr class="pk-sec"><th colspan="<?= $n + 1 ?>">السعة والحدود</th></tr>
        <?php
        $limitRows = [
            ['المستخدمون', 'users', 'عدد حسابات الموظفين', 'users', 'unl'],
            ['القضايا', 'gavel', 'الحد الأقصى للقضايا', 'cases', 'unl'],
            ['العملاء', 'address-book', 'الحد الأقصى للعملاء', 'clients', 'unl'],
            ['مساحة التخزين', 'hard-drive', 'للمرفقات والأرشيف', 'storage', 'sto'],
        ];
        foreach ($limitRows as [$lbl, $ico, $hint, $key, $kind]) {
            $cells = []; $sigs = [];
            foreach ($pk as $p) {
                if ($kind === 'unl') {
                    $inf = pkg_is_unlimited($p[$key]);
                    $cells[] = '<span class="pk-val ' . ($inf ? 'inf' : '') . '">' . e(pricing_ui_limit_text($p[$key])) . '</span>';
                    $sigs[]  = $inf ? 'inf' : (int)$p[$key];
                } else {
                    $na = $p[$key] <= 0;
                    $cells[] = '<span class="pk-val ' . ($na ? 'na' : '') . '">' . e(pricing_ui_storage_text($p[$key])) . '</span>';
                    $sigs[]  = (int)$p[$key];
                }
            }
            $row($lbl, $ico, $hint, $cells, $sigs);
        }
        ?>

        <tr class="pk-sec"><th colspan="<?= $n + 1 ?>">الميزات الأساسية (<?= count($cat['core']) ?>)</th></tr>
        <?php foreach ($cat['core'] as $fk => [$lbl, $ico, $hint]) {
            $cells = []; $sigs = [];
            foreach ($pk as $p) { $on = in_array($fk, $p['core_on'], true); $cells[] = $on ? $yes : $no; $sigs[] = $on ? 1 : 0; }
            $row($lbl, $ico, $hint, $cells, $sigs);
        } ?>

        <?php if ($cat['groups']): ?>
        <tr class="pk-sec"><th colspan="<?= $n + 1 ?>"><i class="fas fa-puzzle-piece" style="margin-left:8px"></i>الموديولات الإضافية (<?= (int)$cat['module_total'] ?>)</th></tr>
        <?php $gi = 0; foreach ($cat['groups'] as $catName => $mods): $gi++;
            $perPkg = [];
            foreach ($pk as $p) { $c = 0; foreach ($mods as $m) if (in_array($m['module_key'], $p['mod_on'], true)) $c++; $perPkg[] = $c; }
        ?>
        <tr class="pk-cat" data-cat="<?= $gi ?>">
          <th scope="row"><i class="fas fa-chevron-left tg"></i><?= e($catName) ?> <small style="display:inline;color:var(--t3);font-weight:400">(<?= count($mods) ?>)</small></th>
          <?php foreach ($perPkg as $i => $c): ?><td class="<?= $feat($i) ?>"><?= $c ?>/<?= count($mods) ?></td><?php endforeach; ?>
        </tr>
        <?php foreach ($mods as $m) {
            $cells = []; $sigs = [];
            foreach ($pk as $p) { $on = in_array($m['module_key'], $p['mod_on'], true); $cells[] = $on ? $yes : $no; $sigs[] = $on ? 1 : 0; }
            ob_start(); $row($m['name'], $m['icon'] ?: 'puzzle-piece', (string)($m['description'] ?? ''), $cells, $sigs);
            echo str_replace('<tr class="pk-row"', '<tr class="pk-row pk-mod" data-cat="' . $gi . '"', ob_get_clean());
        } endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td></td>
          <?php foreach ($pk as $p): ?>
          <td class="<?= $p['featured'] ? 'feat' : '' ?>">
            <?php if ($p['price'] > 0): ?>
            <a href="register.php?package=<?= $p['id'] ?>&billing=yearly" class="pk-cmp-cta <?= $p['featured'] ? 'lx-pbtn-gold' : 'lx-pbtn-out' ?>">ابدأ الآن</a>
            <?php else: ?>
            <a href="register.php?package=<?= $p['id'] ?>" class="pk-cmp-cta lx-pbtn-out">اطلب عرض سعر</a>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<script>
(function () {
  var id = <?= json_encode($id) ?>;
  var tbl = document.getElementById(id + '-tbl');
  if (!tbl) return;
  var diffBtn = document.getElementById(id + '-diff');
  var foldBtn = document.getElementById(id + '-fold');
  var diffOnly = false;

  function refresh() {
    // الصفوف المطويّة (موديولات) والفروقات فقط
    var closed = {};
    tbl.querySelectorAll('tr.pk-cat').forEach(function (c) { closed[c.dataset.cat] = c.classList.contains('closed'); });
    tbl.querySelectorAll('tr.pk-row').forEach(function (r) {
      var hide = (diffOnly && r.dataset.same === '1') || (r.classList.contains('pk-mod') && closed[r.dataset.cat]);
      r.classList.toggle('hide', hide);
    });
    // إخفاء ترويسة تصنيف لا يظهر تحتها شيء عند «الفروقات فقط»
    tbl.querySelectorAll('tr.pk-cat').forEach(function (c) {
      var any = false;
      tbl.querySelectorAll('tr.pk-mod[data-cat="' + c.dataset.cat + '"]').forEach(function (r) { if (r.dataset.same !== '1') any = true; });
      c.classList.toggle('hide', diffOnly && !any);
    });
  }
  tbl.querySelectorAll('tr.pk-cat').forEach(function (c) {
    c.addEventListener('click', function () { c.classList.toggle('closed'); refresh(); });
  });
  diffBtn.addEventListener('click', function () { diffOnly = !diffOnly; diffBtn.classList.toggle('on', diffOnly); refresh(); });
  var allClosed = false;
  foldBtn.addEventListener('click', function () {
    allClosed = !allClosed;
    tbl.querySelectorAll('tr.pk-cat').forEach(function (c) { c.classList.toggle('closed', allClosed); });
    foldBtn.innerHTML = '<i class="fas fa-layer-group"></i>' + (allClosed ? 'عرض الموديولات' : 'طيّ الموديولات');
    foldBtn.classList.toggle('on', allClosed);
    refresh();
  });
  refresh();
})();
</script>
<?php
}
