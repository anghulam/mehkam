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
}