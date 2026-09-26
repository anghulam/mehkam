<?php
if(!isset($conn)) require_once dirname(__DIR__,2).'/config/db.php';
if(!function_exists('sc')) require_once dirname(__DIR__,2).'/includes/content_helper.php';
if(!function_exists('e')){function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}}
$_sn=sc($conn,'site_name','OLFS');
$_sd=sc($conn,'site_desc','نظام إدارة المحاماة');
$fe=sc($conn,'contact_email','info@lawsaas.com');
$fp=sc($conn,'contact_phone','920012345');
$fa=sc($conn,'contact_address','الرياض، المملكة العربية السعودية');
$_ftw=sc($conn,'twitter','');
$_fli=sc($conn,'linkedin','');
$_fwa=sc($conn,'whatsapp','');
?>
<footer class="lx-footer">
  <div class="container">
    <div class="lx-footer-grid">
      <div>
        <a href="home.php" class="lx-footer-logo">
          <?php $__slogo = function_exists('site_logo') ? site_logo($conn) : ''; ?>
          <img src="<?= $__slogo ? e($__slogo) : '../assets/img/logo-x.png' ?>" style="width:50px;">
          <div><span class="lx-footer-logo-name"><?= e($_sn) ?></span><span class="lx-footer-logo-sub"><?= e($_sd) ?></span></div>
        </a>
        <p class="lx-footer-desc">المنصة الأمثل لإدارة مكاتب المحاماة في المملكة العربية السعودية — احترافية وسهولة في مكان واحد.</p>
        <div class="lx-footer-social">
          <?php if ($_ftw): ?><a href="<?=e($_ftw)?>" target="_blank" rel="noopener" title="تويتر"><i class="fab fa-twitter"></i></a><?php endif; ?>
          <?php if ($_fli): ?><a href="<?=e($_fli)?>" target="_blank" rel="noopener" title="لينكدإن"><i class="fab fa-linkedin-in"></i></a><?php endif; ?>
          <?php if ($_fwa): ?><a href="https://wa.me/<?=e(preg_replace('/\D/','',$_fwa))?>" target="_blank" rel="noopener" title="واتساب"><i class="fab fa-whatsapp"></i></a><?php endif; ?>
          <a href="mailto:<?=e($fe)?>" title="بريد"><i class="fas fa-envelope"></i></a>
        </div>
      </div>
      <div class="lx-footer-col">
        <h6>المنصة</h6>
        <ul class="lx-footer-links">
          <li><a href="features.php">المزايا</a></li>
          <li><a href="pricing.php">الأسعار</a></li>
          <li><a href="about.php">من نحن</a></li>
          <li><a href="contact.php">تواصل معنا</a></li>
          <li><a href="register.php">سجّل الآن</a></li>
        </ul>
      </div>
      <div class="lx-footer-col">
        <h6>قانوني</h6>
        <ul class="lx-footer-links">
          <li><a href="privacy.php">سياسة الخصوصية</a></li>
          <li><a href="terms.php">شروط الاستخدام</a></li>
          <li><a href="refund.php">سياسة الاسترداد</a></li>
          <li><a href="cookies.php">سياسة الكوكيز</a></li>
        </ul>
      </div>
      <div class="lx-footer-col">
        <h6>تواصل معنا</h6>
        <ul class="lx-footer-contact">
          <li><i class="fas fa-map-marker-alt"></i><span><?=e($fa)?></span></li>
          <li><i class="fas fa-phone"></i><a href="tel:<?=preg_replace('/\D/','',$fp)?>"><?=e($fp)?></a></li>
          <li><i class="fas fa-envelope"></i><a href="mailto:<?=e($fe)?>"><?=e($fe)?></a></li>
          <li><i class="fas fa-clock"></i><span>الأحد – الخميس: 9 ص – 6 م</span></li>
        </ul>
      </div>
    </div>
    <div class="lx-footer-bottom">
      <span>© <?=date('Y')?> <?= e($_sn) ?>. جميع الحقوق محفوظة.</span>
      <div class="lx-footer-badges">
        <span><i class="fas fa-shield-alt"></i> SSL مشفّر</span>
        <span><i class="fas fa-lock"></i> بيانات آمنة</span>
      </div>
    </div>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';
(function(){
  var nav=document.getElementById('mainNav');
  if(nav){
    function tick(){nav.classList.toggle('stuck',window.scrollY>50);}
    window.addEventListener('scroll',tick,{passive:true});tick();
  }
  var rv=document.querySelectorAll('.reveal');
  if('IntersectionObserver' in window&&rv.length){
    var io=new IntersectionObserver(function(e){
      e.forEach(function(x){if(x.isIntersecting){x.target.classList.add('visible');io.unobserve(x.target);}});
    },{threshold:.1,rootMargin:'0px 0px -40px 0px'});
    rv.forEach(function(el){io.observe(el);});
  }else{rv.forEach(function(el){el.classList.add('visible');});}
  var ctrs=document.querySelectorAll('[data-count]'),band=document.querySelector('.lx-stats-band');
  if(ctrs.length&&band){
    var done=false;
    new IntersectionObserver(function(e){
      if(e[0].isIntersecting&&!done){
        done=true;
        ctrs.forEach(function(el){
          var t=+el.getAttribute('data-count'),s=Math.ceil(t/60),c=0;
          var timer=setInterval(function(){c=Math.min(c+s,t);el.textContent=c.toLocaleString();if(c>=t)clearInterval(timer);},22);
        });
      }
    },{threshold:.5}).observe(band);
  }
  document.querySelectorAll('.lx-faq-q').forEach(function(b){
    b.addEventListener('click',function(){
      var item=this.closest('.lx-faq'),open=item.classList.contains('open');
      document.querySelectorAll('.lx-faq.open').forEach(function(i){i.classList.remove('open');});
      if(!open)item.classList.add('open');
    });
  });
})();
var _yr=false;
function toggleBilling(){
  _yr=!_yr;
  var mo=document.getElementById('lbl-mo'),yr=document.getElementById('lbl-yr');
  if(mo)mo.classList.toggle('on',!_yr);if(yr)yr.classList.toggle('on',_yr);
  document.querySelectorAll('.lpa').forEach(function(el){el.textContent=_yr?el.getAttribute('data-y'):el.getAttribute('data-m');});
  // تحديث روابط التسجيل بدورة الدفع
  document.querySelectorAll('.lpa-reg').forEach(function(a){
    var pkg=a.getAttribute('data-pkg');
    a.href='register.php?package='+pkg+'&billing='+(_yr?'yearly':'monthly');
  });
}
</script>
</body></html>
