<?php
require_once '../config/db.php';
require_once '../includes/content_helper.php';
$_sn_a=sc($conn,'site_name','OLFS');
$page_title='من نحن — '.$_sn_a;
$oc=(int)($conn->query("SELECT COUNT(*) c FROM offices")->fetch_assoc()['c']??200);
$cc=(int)($conn->query("SELECT COUNT(*) c FROM cases")->fetch_assoc()['c']??10000);
include 'includes/header.php';
?>
<section class="lx-phero">
  <div class="container lx-phero-in">
    <div class="lx-eye">من نحن</div>
    <h1 class="lx-h" style="max-width:580px">نؤمن بأن التقنية تُمكّن <span class="gt">العدالة</span></h1>
    <p class="lx-p">فريق من المطورين والمحامين بنى <?php echo $_sn_a; ?> لحل المشكلات الحقيقية في إدارة مكاتب المحاماة</p>
  </div>
</section>
<section class="lx-sec-mid">
  <div class="container">
    <div class="row g-5 align-items-center">
      <div class="col-lg-6 reveal">
        <div class="lx-eye">قصتنا</div>
        <h2 class="lx-h">لماذا أسّسنا <span class="gt"><?= e(sc($conn,'site_name','OLFS')) ?></span>؟</h2>
        <p style="font-size:16px;color:var(--t2);line-height:1.95;margin-bottom:16px"><?= nl2br(e(sc($conn,'about_story_1','في عام 2022، لاحظنا أن غالبية مكاتب المحاماة لا تزال تعتمد على الأوراق وجداول Excel — ما يُضيّع وقتاً ثميناً ويُعرّض المعلومات للضياع.'))) ?></p>
        <p style="font-size:16px;color:var(--t2);line-height:1.95;margin-bottom:0"><?= nl2br(e(sc($conn,'about_story_2','قررنا بناء حل رقمي متكامل، مصمم خصيصاً للبيئة القانونية السعودية، يجمع بين سهولة الاستخدام والميزات الاحترافية.'))) ?></p>
      </div>
      <div class="col-lg-6 reveal">
        <div class="row g-3">
          <?php foreach([
            [sc($conn,'val1_icon','shield-alt'),sc($conn,'val1_title','الأمان أولاً'),sc($conn,'val1_desc','بياناتك محمية بأعلى معايير التشفير')],
            [sc($conn,'val2_icon','heart'),sc($conn,'val2_title','نهتم بعملائنا'),sc($conn,'val2_desc','فريق دعم حقيقي يستمع ويساعد دائماً')],
            [sc($conn,'val3_icon','rocket'),sc($conn,'val3_title','نتطور باستمرار'),sc($conn,'val3_desc','تحديثات شهرية بناءً على احتياجاتك')],
            [sc($conn,'val4_icon','handshake'),sc($conn,'val4_title','شفافية كاملة'),sc($conn,'val4_desc','لا رسوم خفية ولا مفاجآت أبداً')],
          ] as [$ic,$ti,$de]): ?>
          <div class="col-6">
            <div class="lx-vcard">
              <div class="lx-vcard-icon"><i class="fas fa-<?=$ic?>"></i></div>
              <div><div style="font-size:15px;font-weight:800;color:var(--white);margin-bottom:4px"><?=$ti?></div><div style="font-size:13px;color:var(--t3);line-height:1.6"><?=$de?></div></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>
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
    <!--
<section class="lx-sec">
  <div class="container">
    <div class="text-center mb-5 reveal">
      <div class="lx-eye" style="justify-content:center">فريقنا</div>
      <h2 class="lx-h">الأشخاص وراء <span class="gt"><?= e(sc($conn,'site_name','LawSaaS')) ?></span></h2>
    </div>
    <div class="row g-4 justify-content-center">
      <?php foreach([
        ['م','محمد العتيبي','المؤسس والرئيس التنفيذي','محامٍ بخبرة 10 سنوات، مؤمن بأن التقنية تُحسّن مسار العدالة'],
        ['ع','عبدالله الحارثي','مدير التطوير','مهندس برمجيات متخصص في حلول SaaS للقطاع القانوني'],
        ['س','سارة القحطاني','مدير تجربة المستخدم','مصممة متخصصة في واجهات تطبيقات المؤسسات'],
        ['ف','فيصل الدوسري','مدير نجاح العملاء','يضمن أن كل مكتب يحقق أقصى استفادة من المنصة'],
      ] as [$av,$nm,$rl,$bi]): ?>
      <div class="col-6 col-lg-3 reveal">
        <div class="lx-team">
          <div class="lx-team-av"><?=$av?></div>
          <div class="lx-team-name"><?=$nm?></div>
          <div class="lx-team-role"><?=$rl?></div>
          <p style="font-size:12px;color:var(--t3);margin:0"><?=$bi?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
      -->
<section class="lx-cta">
  <div class="container text-center position-relative" style="z-index:1">
    <div class="reveal">
      <h2 class="lx-h">انضم لعائلة <?= e(sc($conn,'site_name','LawSaaS')) ?></h2>
      <p class="lx-p mx-auto mb-4">كن جزءاً من مجتمع المحامين الذين يعملون بذكاء</p>
      <div class="d-flex flex-wrap gap-3 justify-content-center">
        <a href="pricing.php" class="lx-btn-cta"><i class="fas fa-rocket"></i>ابدأ مجاناً</a>
        <a href="contact.php" class="lx-btn-out"><i class="fas fa-envelope"></i>تواصل معنا</a>
      </div>
    </div>
  </div>
</section>
<?php include 'includes/footer.php'; ?>
