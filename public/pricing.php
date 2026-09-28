<?php
require_once '../config/db.php';
require_once '../includes/content_helper.php';

$page_title = 'الأسعار والباقات | '.sc($conn,'site_name','OLFS');
$page_desc  = 'باقات '.sc($conn,'site_name','OLFS').' لإدارة مكاتب المحاماة — تجربة مجانية 14 يوماً';

// إصلاح تعارض قديم: باقة كانت تُظهر "حتى X مستخدمين" وبنفس الوقت نص ثابت "مستخدمون غير محدودون"
try {
    $conn->query("UPDATE packages SET
        max_users = 999,
        features  = TRIM(BOTH ',' FROM REPLACE(REPLACE(features,',مستخدمون غير محدودون',''),'مستخدمون غير محدودون',''))
        WHERE features LIKE '%مستخدمون غير محدودون%'");
} catch (\Throwable $e) {}

$pkgs = [];
$res  = $conn->query("SELECT * FROM packages WHERE is_active=1 ORDER BY price_yearly ASC");
if ($res) while ($p = $res->fetch_assoc()) $pkgs[] = $p;

// أسعار الميزات للباقة المخصصة
$fp = []; $fp_base_u = 3; $fp_base_c = 50; $fp_base_p = 0;
try {
    $fp_res = $conn->query("SELECT * FROM feature_prices ORDER BY sort_order");
    if ($fp_res) while ($r = $fp_res->fetch_assoc()) $fp[$r['feature_key']] = $r;
    $r1 = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='custom_base_users' LIMIT 1");
    if ($r1 && $v = $r1->fetch_assoc()) $fp_base_u = max(1,(int)$v['setting_value']);
    $r2 = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='custom_base_cases' LIMIT 1");
    if ($r2 && $v = $r2->fetch_assoc()) $fp_base_c = max(1,(int)$v['setting_value']);
    $r3 = $conn->query("SELECT setting_value FROM site_content WHERE setting_key='custom_base_price' LIMIT 1");
    if ($r3 && $v = $r3->fetch_assoc()) $fp_base_p = max(0,(float)$v['setting_value']);
} catch(\Exception $e) {}
$feat_keys = ['has_finance','has_invoices','has_contracts','has_poa','has_correspondence','has_library','has_archive','has_ai','has_reports','has_api','has_precedents','has_digital_services'];
require_once '../includes/module_helper.php';
custom_pkg_inject_modules($conn, $fp, $feat_keys);
$cap_keys  = ['per_user','per_100_cases'];
$custom_pkg_enabled = sc($conn,'custom_package_enabled','1') === '1';

include 'includes/header.php';
?>

<!-- ═══ PAGE HERO ═══ -->
<section class="lx-phero">
  <div class="container lx-phero-in">
    <div class="text-center">
      <div class="lx-eye" style="justify-content:center">شفافية كاملة</div>
      <h1 class="lx-h">أسعار واضحة <span class="gt">بدون مفاجآت</span></h1>
      <p class="lx-p mx-auto">ابدأ بالتجربة المجانية 14 يوماً. لا حاجة لبطاقة ائتمان. ألغِ في أي وقت.</p>
    </div>
  </div>
</section>

<!-- ═══ PRICING SECTION ═══ -->
<section class="lx-sec-mid">
  <div class="container">

    <?php if (empty($pkgs)): ?>
    <!-- No packages in DB — show placeholder -->
    <div class="text-center" style="padding:60px 0">
      <div style="font-size:48px;margin-bottom:20px">📦</div>
      <h3 style="color:var(--white);margin-bottom:10px">لا توجد باقات متاحة حالياً</h3>
      <p style="color:var(--t3)">يرجى التواصل معنا للحصول على عرض سعر مخصص</p>
      <a href="contact.php" class="lx-btn-cta mt-3 d-inline-flex"><i class="fas fa-headset"></i>تواصل معنا</a>
    </div>
    <?php else: ?>

    <!-- Packages Grid -->
    <div class="row g-4 justify-content-center mb-5">
      <?php
      $btn_classes = ['lx-pbtn-out', 'lx-pbtn-gold', 'lx-pbtn-navy'];
      $pkg_bgs     = ['#334155', 'linear-gradient(135deg,#b8860b,#e8c040)', '#0f2040'];
      $pkg_icons   = ['seedling', 'star', 'building'];
      $pkg_icclr   = ['#fff', '#080c14', '#fff'];

      foreach ($pkgs as $i => $p):
        $featured   = ($i == 1);
        $features   = array_filter(array_map('trim', explode(',', $p['features'] ?? '')));
        $yr         = (int) $p['price_yearly'];
        $btn_cls    = $btn_classes[$i] ?? 'lx-pbtn-out';
        $pkg_bg     = $pkg_bgs[$i]    ?? '#334155';
        $pkg_icon   = $pkg_icons[$i]  ?? 'box';
        $pkg_iclr   = $pkg_icclr[$i]  ?? '#fff';
      ?>
      <div class="col-md-6 col-lg-4">
        <div class="lx-pcard <?= $featured ? 'feat' : '' ?> h-100">

          <div class="lx-phead">
            <div class="lx-pbadge">
              <?= $featured ? '⭐ الأكثر شيوعاً' : e($p['name']) ?>
            </div>
            <div class="lx-picon-row">
              <div class="lx-picon" style="background:<?= $pkg_bg ?>">
                <i class="fas fa-<?= $pkg_icon ?>" style="color:<?= $pkg_iclr ?>"></i>
              </div>
              <div class="lx-pname"><?= e($p['name']) ?></div>
            </div>
            <?php if ($yr == 0): ?>
            <div class="lx-pprice">
              <span class="lx-pamount" style="font-size:22px">بالتفاهم</span>
            </div>
            <div class="lx-pyearly">سعر مخصص حسب الاحتياج</div>
            <?php else: ?>
            <div class="lx-pprice">
              <span class="lx-pamount"><?= number_format($yr) ?></span>
              <span class="lx-pcur">ر.س</span>
              <span class="lx-pper">/ سنة</span>
            </div>
            <?php endif; ?>
          </div>

          <div class="lx-psep"></div>

          <div class="lx-pbody">
            <ul class="lx-pfeats list-unstyled" style="margin-bottom: 15px;">
              <li>
                <div class="lx-pcheck">✓</div>
                <?= ((int)$p['max_users'] == 0 || (int)$p['max_users'] >= 999) ? 'مستخدمون <strong>غير محدودين</strong>' : 'حتى <strong>'.(int)$p['max_users'].'</strong> مستخدمين' ?>
              </li>
              <li>
                <div class="lx-pcheck">✓</div>
                <?= ((int)$p['max_cases'] == 0 || (int)$p['max_cases'] >= 999) ? 'قضايا <strong>غير محدودة</strong>' : 'حتى <strong>'.(int)$p['max_cases'].'</strong> قضية' ?>
              </li>
              <?php foreach ($features as $feat): ?>
              <li>
                <div class="lx-pcheck">✓</div>
                <?= e($feat) ?>
              </li>
              <?php endforeach; ?>
              <?php if ($yr > 0): ?>
              <li><div class="lx-pcheck">✓</div>تجربة مجانية 14 يوم</li>
              <?php endif; ?>
            </ul>

            <?php if ($yr == 0): ?>
            <a href="register.php?package=<?= (int)$p['id'] ?>" class="lx-pbtn <?= $btn_cls ?>">
              <i class="fas fa-headset me-1"></i>اطلب عرض سعر
            </a>
            <p class="text-center mt-3 mb-0" style="font-size:12px;color:var(--t3)">
              سيتواصل معك فريقنا لتحديد السعر
            </p>
            <?php else: ?>
            <a href="register.php?package=<?= (int)$p['id'] ?>&billing=yearly" class="lx-pbtn <?= $btn_cls ?>">
              ابدأ التجربة المجانية
            </a>
            <p class="text-center mt-3 mb-0" style="font-size:12px;color:var(--t3)">
              14 يوم مجاني · بدون بطاقة ائتمان
            </p>
            <?php endif; ?>
          </div>

        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Comparison note -->
    <div class="text-center mb-5" style="color:var(--t3);font-size:14px">
      <i class="fas fa-shield-alt me-2" style="color:var(--gold3)"></i>
      جميع الباقات تشمل: SSL آمن · دعم فني · تحديثات تلقائية · نسخ احتياطي يومي
    </div>

    <?php endif; ?>

    <?php if ($custom_pkg_enabled): ?>
    <!-- ══════ الباقة المخصصة ══════ -->
    <div class="text-center mb-4 mt-2 reveal">
      <div class="lx-eye" style="justify-content:center">هل لديك احتياجات خاصة؟</div>
      <h3 class="lx-h" style="font-size:28px">باقة <span class="gt">مخصصة بالكامل</span></h3>
      <p class="lx-p mx-auto">اختر تحديداً ما تحتاجه — وادفع فقط للميزات التي تستخدمها</p>
    </div>

    <div class="reveal" id="custom-pkg-section">
      <div style="background:linear-gradient(135deg,rgba(124,58,237,.08),rgba(168,85,247,.05));border:2px solid rgba(124,58,237,.25);border-radius:20px;overflow:hidden">

        <!-- رأس الباني -->
        <div style="background:linear-gradient(135deg,#7c3aed,#a855f7);padding:20px 28px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
          <div>
            <div style="font-size:18px;font-weight:800;color:#fff"><i class="fas fa-puzzle-piece me-2"></i>ابنِ باقتك الخاصة</div>
            <div style="font-size:13px;color:rgba(255,255,255,.75);margin-top:4px">اختر الميزات والسعة — السعر يتحدث فوراً</div>
          </div>
          <div style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:12px;padding:12px 20px;text-align:center;min-width:140px">
            <div style="font-size:11px;color:rgba(255,255,255,.8);margin-bottom:2px">السعر السنوي المقدر</div>
            <div style="font-size:30px;font-weight:900;color:#fff;line-height:1" id="pub_price_display">0</div>
            <div style="font-size:11px;color:rgba(255,255,255,.7)">ر.س / سنة</div>
          </div>
        </div>

        <div style="padding:24px 28px">
          <div class="row g-4">

            <!-- اختيار الميزات -->
            <div class="col-lg-7">
              <div style="font-size:14px;font-weight:700;color:#4c1d95;margin-bottom:14px">
                <i class="fas fa-check-square me-2"></i>الميزات الوظيفية
              </div>
              <div class="row g-2">
              <?php $mod_head_done = false; foreach ($feat_keys as $fk):
                if (!isset($fp[$fk])) continue;
                $f = $fp[$fk];
                $pm = (float)$f['price_yearly'];
                if (!$mod_head_done && strpos($fk, 'mod_') === 0):
                  $mod_head_done = true;
              ?>
              <div class="col-12"><div style="font-size:13px;font-weight:700;color:#4c1d95;margin:10px 0 2px"><i class="fas fa-puzzle-piece me-2"></i>موديولات إضافية</div></div>
              <?php endif; ?>
              <div class="col-sm-6">
                <label class="pub-feat-lbl" data-py="<?= $pm ?>" data-key="<?= $fk ?>"
                       style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;border:1.5px solid #e5e7eb;cursor:pointer;transition:.15s;background:#fff">
                  <input type="checkbox" class="pub-feat-chk" data-key="<?= $fk ?>"
                         style="width:16px;height:16px;accent-color:#7c3aed;flex-shrink:0;cursor:pointer"
                         onchange="pubCalc()">
                  <i class="fas fa-<?= e($f['feature_icon']) ?>" style="color:#7c3aed;font-size:14px;width:16px;text-align:center;flex-shrink:0"></i>
                  <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:600;color:#1f2937"><?= e($f['feature_label']) ?></div>
                    <?php if ($pm > 0): ?>
                    <div style="font-size:11px;color:#7c3aed;font-weight:600">+<?= number_format($pm,0) ?> ر.س/سنة</div>
                    <?php else: ?>
                    <div style="font-size:11px;color:#6b7280">مشمول</div>
                    <?php endif; ?>
                  </div>
                </label>
              </div>
              <?php endforeach; ?>
              </div>
            </div>

            <!-- الطاقة والسعر -->
            <div class="col-lg-5">
              <div style="font-size:14px;font-weight:700;color:#4c1d95;margin-bottom:14px">
                <i class="fas fa-sliders-h me-2"></i>السعة والطاقة
              </div>

              <div style="background:#f8f5ff;border-radius:12px;padding:16px;margin-bottom:16px">
                <!-- المستخدمون -->
                <div style="margin-bottom:14px">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                    <label style="font-size:13px;font-weight:600;color:#374151"><i class="fas fa-users me-1 text-primary"></i>المستخدمون</label>
                    <span style="font-size:12px;color:#7c3aed;font-weight:600" id="pub_users_lbl"><?= $fp_base_u ?> مستخدم</span>
                  </div>
                  <input type="range" id="pub_users" min="<?= $fp_base_u ?>" max="<?= $fp_base_u + 47 ?>" value="<?= $fp_base_u ?>"
                         style="width:100%;accent-color:#7c3aed" oninput="pubCalc()">
                  <div style="display:flex;justify-content:space-between;font-size:10px;color:#9ca3af">
                    <span><?= $fp_base_u ?></span><span><?= $fp_base_u + 47 ?></span>
                  </div>
                  <?php if (!empty($fp['per_user']) && (float)$fp['per_user']['price_yearly'] > 0): ?>
                  <div style="font-size:11px;color:#7c3aed;margin-top:2px">فوق <?= $fp_base_u ?>: +<?= number_format((float)$fp['per_user']['price_yearly'],0) ?> ر.س/مستخدم/سنة</div>
                  <?php endif; ?>
                </div>

                <!-- القضايا -->
                <div style="margin-bottom:14px">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                    <label style="font-size:13px;font-weight:600;color:#374151"><i class="fas fa-gavel me-1 text-success"></i>القضايا</label>
                    <span style="font-size:12px;color:#7c3aed;font-weight:600" id="pub_cases_lbl"><?= $fp_base_c ?> قضية</span>
                  </div>
                  <input type="range" id="pub_cases" min="<?= $fp_base_c ?>" max="<?= $fp_base_c + 950 ?>" step="50" value="<?= $fp_base_c ?>"
                         style="width:100%;accent-color:#7c3aed" oninput="pubCalc()">
                  <div style="display:flex;justify-content:space-between;font-size:10px;color:#9ca3af">
                    <span><?= $fp_base_c ?></span><span><?= $fp_base_c + 950 ?></span>
                  </div>
                  <?php if (!empty($fp['per_100_cases']) && (float)$fp['per_100_cases']['price_yearly'] > 0): ?>
                  <div style="font-size:11px;color:#7c3aed;margin-top:2px">فوق <?= $fp_base_c ?>: +<?= number_format((float)$fp['per_100_cases']['price_yearly'],0) ?> ر.س/100 قضية/سنة</div>
                  <?php endif; ?>
                </div>

              </div>

              <!-- ملخص التسعير -->
              <div style="background:#fff;border:1.5px solid #ddd6fe;border-radius:12px;padding:14px" id="pub_breakdown">
                <div style="font-size:12px;font-weight:700;color:#5b21b6;margin-bottom:8px">تفصيل السعر</div>
                <div id="pub_bd_items" style="font-size:12px;color:#374151"></div>
                <div style="border-top:1px solid #ede9fe;margin-top:8px;padding-top:8px;display:flex;justify-content:space-between;align-items:center">
                  <span style="font-size:13px;font-weight:700;color:#1f2937">الإجمالي / سنة</span>
                  <span style="font-size:18px;font-weight:900;color:#7c3aed" id="pub_total_lbl">0 ر.س</span>
                </div>
              </div>

              <!-- زر التسجيل -->
              <a href="#" id="pub_register_btn" onclick="goRegisterCustom(event)"
                 style="display:block;margin-top:14px;padding:14px;text-align:center;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;border-radius:12px;font-size:15px;font-weight:700;text-decoration:none;transition:.2s;opacity:.5;pointer-events:none"
                 id="pub_reg_btn">
                <i class="fas fa-rocket me-2"></i>ابدأ بالباقة المخصصة
              </a>
              <p style="text-align:center;font-size:11px;color:#9ca3af;margin-top:8px">
                * السعر النهائي يُؤكَّد من قِبَل الإدارة بعد مراجعة طلبك
              </p>
            </div>

          </div>
        </div>
      </div>
    </div>

    <script>
    var _pub_fp = <?= json_encode(array_map('floatval', array_column($fp, 'price_yearly', 'feature_key'))) ?>;
    var _pub_fp_lbl = <?= json_encode(array_column($fp, 'feature_label', 'feature_key')) ?>;
    var _pub_base_u = <?= $fp_base_u ?>, _pub_base_c = <?= $fp_base_c ?>, _pub_base_p = <?= $fp_base_p ?>;

    function pubCalc() {
      var total = _pub_base_p;
      var bd = [];
      if (_pub_base_p > 0) bd.push({l:'الأساس', v:_pub_base_p});

      // features
      document.querySelectorAll('.pub-feat-chk').forEach(function(chk) {
        var lbl = chk.closest('.pub-feat-lbl');
        if (chk.checked) {
          var py = parseFloat(lbl.dataset.py || '0') || 0;
          total += py;
          if (py > 0) bd.push({l: _pub_fp_lbl[chk.dataset.key] || chk.dataset.key, v: py});
          lbl.style.borderColor = '#7c3aed';
          lbl.style.background  = '#f5f3ff';
        } else {
          lbl.style.borderColor = '#e5e7eb';
          lbl.style.background  = '#fff';
        }
      });

      // users
      var users = parseInt(document.getElementById('pub_users').value);
      document.getElementById('pub_users_lbl').textContent = users + ' مستخدم';
      var extra_u = Math.max(0, users - _pub_base_u);
      if (extra_u > 0 && _pub_fp['per_user']) {
        var uc = extra_u * _pub_fp['per_user'];
        total += uc; bd.push({l: extra_u + ' مستخدم إضافي', v: uc});
      }

      // cases
      var cases = parseInt(document.getElementById('pub_cases').value);
      document.getElementById('pub_cases_lbl').textContent = cases + ' قضية';
      var extra_c100 = Math.ceil(Math.max(0, cases - _pub_base_c) / 100);
      if (extra_c100 > 0 && _pub_fp['per_100_cases']) {
        var cc = extra_c100 * _pub_fp['per_100_cases'];
        total += cc; bd.push({l: (extra_c100 * 100) + ' قضية إضافية', v: cc});
      }

      total = Math.max(0, Math.round(total * 100) / 100);

      var priceDisplay = document.getElementById('pub_price_display');
      var totalDisplay = document.getElementById('pub_total_lbl');
      priceDisplay.textContent = total.toLocaleString('ar-SA',{maximumFractionDigits:0});
      totalDisplay.textContent = total.toLocaleString('ar-SA',{maximumFractionDigits:0}) + ' ر.س';

      // تفصيل
      var bdEl = document.getElementById('pub_bd_items');
      if (bd.length) {
        bdEl.innerHTML = bd.map(function(b){
          return '<div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:#6b7280">'+b.l+'</span><span style="font-weight:600">'+b.v.toLocaleString('ar-SA',{maximumFractionDigits:0})+' ر.س</span></div>';
        }).join('');
      } else {
        bdEl.innerHTML = '<div style="color:#9ca3af;text-align:center;padding:4px">اختر ميزة أو زِد السعة لرؤية التفصيل</div>';
      }

      // enable/disable button
      var btn = document.getElementById('pub_register_btn');
      var hasAny = document.querySelectorAll('.pub-feat-chk:checked').length > 0 || users > _pub_base_u || cases > _pub_base_c;
      btn.style.opacity = hasAny ? '1' : '.5';
      btn.style.pointerEvents = hasAny ? '' : 'none';
    }

    function goRegisterCustom(e) {
      e.preventDefault();
      var feats = [];
      document.querySelectorAll('.pub-feat-chk:checked').forEach(function(c){ feats.push(c.dataset.key); });
      var users   = document.getElementById('pub_users').value;
      var cases   = document.getElementById('pub_cases').value;
      var price   = document.getElementById('pub_total_lbl').textContent.replace(/[^0-9.]/g,'');
      var url = 'register.php?custom=1&features='+encodeURIComponent(feats.join(','))+'&users='+users+'&cases='+cases+'&storage=0&price='+price+'&billing=yearly';
      window.location.href = url;
    }

    // init with DOM ready
    document.addEventListener('DOMContentLoaded', pubCalc);
    // Also call immediately
    pubCalc();
    </script>

    <div style="height:40px"></div>
    <?php endif; // custom_pkg_enabled ?>

    <!-- FAQ -->
    <div class="row justify-content-center">
      <div class="col-lg-7">
        <h3 class="lx-h text-center mb-4" style="font-size:26px">أسئلة <span class="gt">شائعة</span></h3>
        <?php
        $faqs = [
          ['هل يوجد نسخة تجريبية مجانية؟',
           'نعم، جميع الباقات تشمل 14 يوماً تجريبية مجانية بدون بطاقة ائتمان.'],
          ['هل يمكنني الترقية بين الباقات؟',
           'نعم، يمكنك الترقية أو التخفيض في أي وقت من لوحة التحكم بدون قيود.'],
          ['هل بياناتي آمنة ومحمية؟',
           'نعم، نستخدم أعلى معايير التشفير SSL وخوادم آمنة مع نسخ احتياطي يومي.'],
          ['هل يمكنني الإلغاء في أي وقت؟',
           'نعم، لا يوجد التزام. يمكنك الإلغاء في أي وقت بدون رسوم إضافية.'],
          ['هل يدعم النظام اللغة العربية كاملاً؟',
           'نعم، النظام مبني بالكامل باللغة العربية RTL ويدعم جميع المتطلبات القانونية السعودية.'],
          ['ما طرق الدفع المتاحة؟',
           'نقبل البطاقات الائتمانية (Visa, Mastercard) والتحويل البنكي ومدى.'],
        ];
        foreach ($faqs as [$q, $a]):
        ?>
        <div class="lx-faq">
          <div class="lx-faq-q">
            <?= e($q) ?>
            <i class="fas fa-chevron-down"></i>
          </div>
          <div class="lx-faq-a"><?= e($a) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</section>

<!-- ═══ CTA ═══ -->
<section class="lx-cta">
  <div class="container text-center position-relative" style="z-index:1">
    <div class="reveal">
      <div class="lx-eye" style="justify-content:center">ابدأ اليوم</div>
      <h2 class="lx-h">مستعد للبدء؟<br><span class="gt">انضم لمئات المكاتب</span></h2>
      <p class="lx-p mx-auto mb-4">انضم لمئات مكاتب المحاماة التي تثق بـ <?= e(sc($conn,'site_name','LawSaaS')) ?></p>
      <div class="d-flex flex-wrap gap-3 justify-content-center">
        <?php if (!empty($pkgs)): ?>
        <a href="register.php?package=<?= (int)($pkgs[1]['id'] ?? $pkgs[0]['id'] ?? 1) ?>"
           class="lx-btn-cta">
          <i class="fas fa-rocket"></i>ابدأ مجاناً الآن
        </a>
        <?php endif; ?>
        <a href="contact.php" class="lx-btn-out">
          <i class="fas fa-headset"></i>تحدث مع فريقنا
        </a>
      </div>
    </div>
  </div>
</section>

<?php include 'includes/footer.php'; ?>
