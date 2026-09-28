<?php
require_once '../config/db.php';
require_once '../includes/content_helper.php';
$_site_name_p=sc($conn,'site_name','OLFS');
$page_title=$_site_name_p.' — نظام إدارة مكاتب المحاماة الأذكى';
$page_desc=sc($conn,'site_name','OLFS').' — منصة رائدة لإدارة مكاتب المحاماة في المملكة العربية السعودية';
$pkgs=[];
$res=$conn->query("SELECT * FROM packages WHERE is_active=1 ORDER BY price_monthly ASC");
if($res) while($p=$res->fetch_assoc()) $pkgs[]=$p;
$oc=(int)($conn->query("SELECT COUNT(*) c FROM offices WHERE status='active'")->fetch_assoc()['c']??47);
$cc=(int)($conn->query("SELECT COUNT(*) c FROM cases")->fetch_assoc()['c']??1800);
include 'includes/header.php';
?>

<!-- ═══ HERO ═══ -->
<section class="lx-hero">
  <div class="lx-hero-grid"></div>
  <div class="container lx-hero-content">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <div class="lx-pill"><span class="lx-pill-dot"></span><?=e(sc($conn,'hero_pill','الأكثر استخداماً في مكاتب المحاماة السعودية'))?></div>
        <h1 class="lx-hero-title">
          <?=nl2br(e(sc($conn,'hero_title','أدِر مكتبك القانوني')))?>
          <span class="gt"> بمعايير دولية</span>
        </h1>
        <p class="lx-hero-desc"><?=e(sc($conn,'hero_text','منصة متكاملة لإدارة القضايا والعملاء والشؤون المالية — مصممة خصيصاً للمحامين في المملكة.'))?></p>
        <div class="d-flex flex-wrap gap-3 mb-5">
          <a href="pricing.php" class="lx-btn-cta"><i class="fas fa-rocket"></i><?=e(sc($conn,'btn_register','ابدأ مجاناً — 14 يوم'))?></a>
        </div>
        <!--
        <div class="lx-hero-stats">
          <div><div class="lx-stat-v"><span>+</span><?=$oc+200?></div><div class="lx-stat-l">مكتب نشط</div></div>
          <div><div class="lx-stat-v"><span>+</span><?=number_format($cc+10000)?></div><div class="lx-stat-l">قضية مُدارة</div></div>
          <div><div class="lx-stat-v">98<span>%</span></div><div class="lx-stat-l">رضا العملاء</div></div>
        </div>
        -->
      </div>
      <div class="col-lg-6 d-none d-lg-block">
        <div class="lx-mk-wrap">
          <div class="lx-mk">
            <div class="lx-mk-bar">
              <div class="lx-dot r"></div><div class="lx-dot y"></div><div class="lx-dot g"></div>
              <div class="lx-mk-url">https://olfs.site/office/dashboard</div>
            </div>
            <div class="lx-mk-body">
              <div class="lx-mk-ttl">لوحة التحكم — مكتب الشمري للمحاماة</div>
              <div class="lx-mk-cards">
                <div class="lx-mk-card hi"><div class="n">47</div><div class="l">قضية نشطة</div></div>
                <div class="lx-mk-card"><div class="n">23</div><div class="l">جلسة قادمة</div></div>
                <div class="lx-mk-card"><div class="n">98%</div><div class="l">إنجاز</div></div>
                <div class="lx-mk-card"><div class="n">48K</div><div class="l">إيرادات</div></div>
              </div>
              <div class="lx-mk-cols">
                <div class="lx-mk-col">
                  <div class="lx-mk-col-h">أحدث القضايا</div>
                  <?php foreach([['نزاع عمالي — مصنع الإنتاج','محكمة العمل • 15 أبريل','b-y','عاجلة'],['نزاع تجاري — شركة الخليج','المحكمة التجارية • 20 أبريل','b-g','نشطة'],['قضية عقارية — أبراج المدينة','محكمة الأحوال • 28 أبريل','b-b','جديدة']] as [$n,$d,$c,$s]): ?>
                  <div class="lx-mk-row">
                    <div><div class="lx-mk-rn"><?=$n?></div><div class="lx-mk-rd"><?=$d?></div></div>
                    <span class="lx-mk-b <?=$c?>"><?=$s?></span>
                  </div>
                  <?php endforeach; ?>
                </div>
                <div class="lx-mk-col">
                  <div class="lx-mk-col-h">الإيرادات الشهرية</div>
                  <div class="lx-mk-bars">
                    <?php foreach([['ي',45],['ف',62],['م',51],['أ',79],['م',65],['ي',90]] as [$m,$p]): ?>
                    <div class="lx-mk-brow"><span><?=$m?></span><div class="lx-mk-track"><div class="lx-mk-fill" style="width:<?=$p?>%"></div></div></div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="lx-float f1"><i class="fas fa-check-circle"></i>تم رفع الحكم</div>
          <div class="lx-float f2"><i class="fas fa-bell"></i>جلسة غداً 10 ص</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══ TRUST ═══ -->
<!--
<div class="lx-trust">
  <div class="container">
    <div class="lx-trust-inner">
      <span class="lx-trust-item"><i class="fas fa-building"></i>+200 مكتب نشط</span>
      <span class="lx-trust-sep"></span>
      <span class="lx-trust-item"><i class="fas fa-map-marker-alt"></i>الرياض · جدة · الدمام · مكة</span>
      <span class="lx-trust-sep"></span>
      <span class="lx-trust-item"><i class="fas fa-star"></i>98% رضا العملاء</span>
      <span class="lx-trust-sep"></span>
      <span class="lx-trust-item"><i class="fas fa-gavel"></i>+10,000 قضية مُدارة</span>
    </div>
  </div>
</div>
-->

<!-- ═══ STATS ═══ -->
<section class="lx-stats-band">
  <div class="container position-relative" style="z-index:1">
    <div class="row g-4 text-center">
      <div class="col-6 col-md-3"><div class="lx-stats-val"><span data-count="<?=$oc+80?>">0</span></div><div class="lx-stats-lbl">مكتب يثق بنا</div></div>
      <div class="col-6 col-md-3"><div class="lx-stats-val"><span data-count="<?=$cc+400?>">0</span></div><div class="lx-stats-lbl">قضية مُدارة</div></div>
      <div class="col-6 col-md-3"><div class="lx-stats-val"><span data-count="98">0</span>%</div><div class="lx-stats-lbl">رضا العملاء</div></div>
      <div class="col-6 col-md-3"><div class="lx-stats-val"><span data-count="5">0</span>★</div><div class="lx-stats-lbl">تقييم المنصة</div></div>
    </div>
  </div>
</section>

<!-- ═══ FEATURES ═══ -->
<section class="lx-sec-mid">
  <div class="container">
    <div class="text-center reveal">
      <div class="lx-eye" style="justify-content:center">المزايا الرئيسية</div>
      <h2 class="lx-h"><?=e(sc($conn,'feat_title','كل ما يحتاجه مكتبك'))?> <span class="gt">في مكان واحد</span></h2>
      <p class="lx-p mx-auto">أدوات متكاملة تغطي كل جوانب إدارة مكتب المحاماة بأعلى معايير الكفاءة</p>
    </div>
    <div class="lx-feat-grid reveal">
      <?php
      $feats=[
        ['gavel',sc($conn,'feat_title_0','إدارة القضايا'),sc($conn,'feat_desc_0','تتبع شامل لجميع قضاياك مع حالة كل قضية وتواريخ الجلسات القادمة')],
        ['wallet',sc($conn,'feat_title_2','الشؤون المالية'),sc($conn,'feat_desc_2','تتبع الأتعاب والمدفوعات وأصدر تقارير مالية دقيقة بلحظة واحدة')],
        ['file-signature',sc($conn,'feat_title_3','العقود والوكالات'),sc($conn,'feat_desc_3','إنشاء وإدارة العقود مع تنبيهات انتهاء الصلاحية تلقائياً')],
        ['address-book',sc($conn,'feat_title_1','ملفات العملاء'),sc($conn,'feat_desc_1','سجل شامل لكل عميل مع كامل قضاياه ومراسلاته وعقوده')],
        ['tasks',sc($conn,'feat_title_4','المهام والمواعيد'),sc($conn,'feat_desc_4','نظام متكامل لإدارة المهام مع تذكيرات ذكية تلقائية للجلسات')],
        ['robot',sc($conn,'feat_title_5','المساعد الذكي AI'),sc($conn,'feat_desc_5','مساعد قانوني بالذكاء الاصطناعي متخصص في الأنظمة السعودية')],
      ];
      foreach($feats as [$icon,$title,$desc]):
      ?>
      <div class="lx-feat-cell">
        <div class="lx-feat-icon"><i class="fas fa-<?=$icon?>"></i></div>
        <h3 class="lx-feat-title"><?=e($title)?></h3>
        <p class="lx-feat-desc"><?=e($desc)?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="text-center mt-5 reveal">
      <a href="features.php" class="lx-btn-cta"><i class="fas fa-th-large"></i>عرض جميع المزايا</a>
    </div>
  </div>
</section>

<!-- ═══ HOW ═══ -->
<section class="lx-sec">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-5 reveal">
        <div class="lx-eye">كيف يعمل</div>
        <h2 class="lx-h">ابدأ في دقائق <span class="gt">بدون تعقيد</span></h2>
        <p class="lx-p">نظام سهل الإعداد لا يحتاج خبرة تقنية. سجّل واستخدم فوراً.</p>
      </div>
      <div class="col-lg-7">
        <?php foreach([
          ['1',sc($conn,'how1_title','سجّل مكتبك مجاناً'),sc($conn,'how1_desc','أدخل بيانات مكتبك وابدأ النسخة التجريبية 14 يوماً بدون بطاقة ائتمان')],
          ['2',sc($conn,'how2_title','أضف قضاياك وعملاءك'),sc($conn,'how2_desc','استورد بياناتك الحالية أو أضفها يدوياً بواجهة عربية كاملة')],
          ['3',sc($conn,'how3_title','أدر ونظّم وتتبع'),sc($conn,'how3_desc','استخدم لوحة التحكم الذكية وتلقّ تنبيهات تلقائية للجلسات')],
          ['4',sc($conn,'how4_title','اشترك واستمر'),sc($conn,'how4_desc','اختر الباقة المناسبة لمكتبك واستمر بكامل الميزات')],
        ] as [$n,$t,$d]): ?>
        <div class="lx-step reveal">
          <div class="lx-step-num"><?=$n?></div>
          <div><div class="lx-step-title"><?=$t?></div><div class="lx-step-desc"><?=$d?></div></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ═══ PRICING ═══ -->
<section class="lx-sec-mid">
  <div class="container">
    <div class="text-center mb-5 reveal">
      <div class="lx-eye" style="justify-content:center">الأسعار</div>
      <h2 class="lx-h">باقات تناسب كل <span class="gt">مكتب</span></h2>
      <p class="lx-p mx-auto">جرّب 14 يوماً مجاناً. لا حاجة لبطاقة ائتمان.</p>
    </div>

    <?php if (!empty($pkgs)): ?>
    <!-- Billing Toggle -->
    <div class="text-center mb-5 reveal">
      <div class="lx-billing">
        <div class="lx-billing-opt on" id="home-lbl-mo" onclick="homeToggleBilling()">شهري</div>
        <div class="lx-billing-opt" id="home-lbl-yr" onclick="homeToggleBilling()">
          سنوي <span class="lx-save">وفّر 17%</span>
        </div>
      </div>
    </div>

    <!-- Packages Grid -->
    <div class="row g-4 justify-content-center">
      <?php
      $btn_classes = ['lx-pbtn-out', 'lx-pbtn-gold', 'lx-pbtn-navy'];
      $pkg_bgs     = ['#334155', 'linear-gradient(135deg,#b8860b,#e8c040)', '#0f2040'];
      $pkg_icons   = ['seedling', 'star', 'building'];
      $pkg_icclr   = ['#fff', '#080c14', '#fff'];

      foreach ($pkgs as $i => $p):
        $featured = ($i == 1);
        $features = array_filter(array_map('trim', explode(',', $p['features'] ?? '')));
        $mo       = (int) $p['price_monthly'];
        $yr       = (int) round($p['price_yearly'] / 12);
        $btn_cls  = $btn_classes[$i] ?? 'lx-pbtn-out';
        $pkg_bg   = $pkg_bgs[$i]    ?? '#334155';
        $pkg_icon = $pkg_icons[$i]  ?? 'box';
        $pkg_iclr = $pkg_icclr[$i]  ?? '#fff';
      ?>
      <div class="col-md-6 col-lg-4 reveal">
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
            <div class="lx-pprice">
              <span class="lx-pamount lpa" data-m="<?= $mo ?>" data-y="<?= $yr ?>"><?= $mo ?></span>
              <span class="lx-pcur">ر.س</span>
              <span class="lx-pper">/ شهر</span>
            </div>
            <div class="lx-pyearly">
              أو <?= number_format($p['price_yearly']) ?> ر.س سنوياً
              <span class="save">وفّر 17%</span>
            </div>
          </div>
          <div class="lx-psep"></div>
          <div class="lx-pbody">
            <ul class="lx-pfeats list-unstyled" style="margin-bottom:15px">
              <li>
                <div class="lx-pcheck">✓</div>
                <?= ((int)$p['max_users']==0||(int)$p['max_users']>=999) ? 'مستخدمون <strong>غير محدودين</strong>' : 'حتى <strong>'.(int)$p['max_users'].'</strong> مستخدمين' ?>
              </li>
              <li>
                <div class="lx-pcheck">✓</div>
                <?= ((int)$p['max_cases']==0||(int)$p['max_cases']>=999) ? 'قضايا <strong>غير محدودة</strong>' : 'حتى <strong>'.(int)$p['max_cases'].'</strong> قضية' ?>
              </li>
              <?php foreach ($features as $f): ?>
              <li><div class="lx-pcheck">✓</div><?= e($f) ?></li>
              <?php endforeach; ?>
            </ul>
            <a href="register.php?pkg=<?= $p['id'] ?>" class="lx-pbtn <?= $btn_cls ?>">ابدأ الآن</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="text-center mt-4 reveal">
      <a href="pricing.php" class="lx-btn-out d-inline-flex"><i class="fas fa-list-ul"></i>مقارنة تفصيلية للباقات</a>
    </div>
    <?php endif; ?>
  </div>
</section>

<script>
var _homeBillingYearly = false;
function homeToggleBilling() {
  _homeBillingYearly = !_homeBillingYearly;
  document.getElementById('home-lbl-mo').classList.toggle('on', !_homeBillingYearly);
  document.getElementById('home-lbl-yr').classList.toggle('on',  _homeBillingYearly);
  document.querySelectorAll('.lpa').forEach(function(el) {
    el.textContent = _homeBillingYearly ? el.dataset.y : el.dataset.m;
  });
}
</script>

<!-- ═══ TESTIMONIALS ═══ -->
<section class="lx-sec">
  <div class="container">
    <div class="text-center mb-5 reveal">
      <div class="lx-eye" style="justify-content:center">آراء العملاء</div>
      <h2 class="lx-h">ماذا يقول عملاؤنا عن <span class="gt">
          <?= e(sc($conn,'site_name','OLFS')) ?></span></h2>
    </div>
    <div class="row g-4">
      <?php foreach([
        [sc($conn,'test1_av','أ'),sc($conn,'test1_name','أحمد الشمري'),sc($conn,'test1_role','محامي — الرياض'),sc($conn,'test1_text','غيّرت المنصة طريقة إدارة مكتبي تماماً. لم أعد أقلق بشأن مواعيد الجلسات أو تتبع الأتعاب.')],
        [sc($conn,'test2_av','ف'),sc($conn,'test2_name','فهد النجدي'),sc($conn,'test2_role','مستشار قانوني — جدة'),sc($conn,'test2_text','التقارير المالية وحدها تستحق الاشتراك! أصبحت أعرف وضع مكتبي المالي بدقة تامة في ثوانٍ.')],
        [sc($conn,'test3_av','م'),sc($conn,'test3_name','منى العمري'),sc($conn,'test3_role','محامية — الدمام'),sc($conn,'test3_text','المكتبة القانونية المدمجة رائعة. أجد ما أحتاجه من أنظمة ونماذج في لحظات.')],
      ] as [$av,$nm,$rl,$tx]): ?>
      <div class="col-md-4 reveal">
        <div class="lx-tcard">
          <div class="lx-tcard-stars">★★★★★</div>
          <p class="lx-tcard-text">"<?=$tx?>"</p>
          <div class="d-flex align-items-center gap-3">
            <div class="lx-tcard-av"><?=$av?></div>
            <div><div class="lx-tcard-name"><?=$nm?></div><div class="lx-tcard-role"><?=$rl?></div></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ═══ CTA ═══ -->
<section class="lx-cta">
  <div class="container text-center position-relative" style="z-index:1">
    <div class="reveal">
      <div class="lx-eye" style="justify-content:center">ابدأ اليوم</div>
      <h2 class="lx-h">جاهز لتطوير مكتبك؟<br><span class="gt">ابدأ مجاناً الآن</span></h2>
      <p class="lx-p mx-auto mb-4">14 يوماً تجريبياً. لا حاجة لبطاقة ائتمان. إلغاء في أي وقت.</p>
      <div class="d-flex flex-wrap gap-3 justify-content-center">
        <a href="pricing.php" class="lx-btn-cta"><i class="fas fa-rocket"></i>ابدأ التجربة المجانية</a>
        <a href="contact.php" class="lx-btn-out"><i class="fas fa-headset"></i>تحدث مع فريقنا</a>
      </div>
    </div>
  </div>
</section>

<?php include 'includes/footer.php'; ?>
