/* ============================================================
   hijri.js — منتقي تاريخ هجري/ميلادي (قوائم منسدلة)
   ------------------------------------------------------------
   يحوّل كل <input type="date"|"datetime-local"> إلى صفّ من
   القوائم المنسدلة: اسم اليوم (تلقائي) + اليوم + الشهر + السنة،
   بدل التقويم المنبثق (date picker) القديم — بلا أي نقر على
   خلية تقويم، وبالتالي بلا أي التباس بين اليوم المختار والمحفوظ.
   القيمة المخزَّنة والمُرسَلة للسيرفر تبقى ميلادية
   (YYYY-MM-DD أو YYYY-MM-DDTHH:MM) — لا يتغيّر الحفظ.
   window.MEHKAM_CAL: 'hijri' يعرض قوائم هجرية، 'gregorian' قوائم
   ميلادية، 'both' هجري مع زر تبديل للميلادي (والعكس).
   ============================================================ */
(function () {
  'use strict';

  var PREF = window.MEHKAM_CAL || 'both';
  var START_HIJRI = (PREF === 'hijri' || PREF === 'both');

  var H_MONTHS = ['محرم', 'صفر', 'ربيع الأول', 'ربيع الآخر', 'جمادى الأولى',
    'جمادى الآخرة', 'رجب', 'شعبان', 'رمضان', 'شوال', 'ذو القعدة', 'ذو الحجة'];
  var G_MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
    'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
  var WD = ['أحد', 'إثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'];

  var f = Math.floor;

  function gregToJD(gy, gm, gd) {
    // ملاحظة: هذا الحد يجب أن يُقطَع نحو الصفر لا أن يُقرَّب للأسفل (Math.floor) —
    // بما أن gm بين 1 و12 فإن gm-14 سالب دائماً تقريباً، وMath.floor كان يُنتج قيمة
    // أخفض بمقدار 1 عن المطلوب فيفكّك تطابق gregToJD/jdToGreg (كان يسبب فرق يوم أو
    // يومين بين التاريخ المُختار والتاريخ المحفوظ فعلياً).
    var a = ((gm - 14) / 12) | 0; // قطع نحو الصفر، وليس floor
    return f((1461 * (gy + 4800 + a)) / 4)
      + f((367 * (gm - 2 - 12 * a)) / 12)
      - f((3 * f((gy + 4900 + a) / 100)) / 4) + gd - 32075;
  }
  function jdToGreg(jd) {
    var l = jd + 68569;
    var n = f((4 * l) / 146097);
    l = l - f((146097 * n + 3) / 4);
    var i = f((4000 * (l + 1)) / 1461001);
    l = l - f((1461 * i) / 4) + 31;
    var j = f((80 * l) / 2447);
    var d = l - f((2447 * j) / 80);
    var ll = f(j / 11);
    var m = j + 2 - 12 * ll;
    var y = 100 * (n - 49) + i + ll;
    return [y, m, d];
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }

  // خوارزمية كويتية (احتياطية إن لم يتوفّر Intl)
  function _kuwaiti(gy, gm, gd) {
    var jd = gregToJD(gy, gm, gd);
    var l = jd - 1948440 + 10632, n = f((l - 1) / 10631);
    l = l - 10631 * n + 354;
    var j = f((10985 - l) / 5316) * f((50 * l) / 17719) + f(l / 5670) * f((43 * l) / 15238);
    l = l - f((30 - j) / 15) * f((17719 * j) / 50) - f(j / 16) * f((15238 * j) / 43) + 29;
    return [30 * n + j - 30, f((24 * l) / 709), l - f((709 * f((24 * l) / 709)) / 24)];
  }

  // منسّق أم القرى من المتصفّح (دقيق في الاتجاهين عبر البحث)
  var _hfmt = null;
  try {
    _hfmt = new Intl.DateTimeFormat('en-US-u-ca-islamic-umalqura-nu-latn',
      { year: 'numeric', month: 'numeric', day: 'numeric', timeZone: 'UTC' });
    // تحقّق أنه فعلاً تقويم هجري
    var _t = _hfmt.formatToParts(new Date(Date.UTC(2024, 6, 7, 12)));
    var _y = 0; _t.forEach(function (p) { if (p.type === 'year') _y = parseInt(p.value, 10); });
    if (!(_y > 1400 && _y < 1500)) _hfmt = null;
  } catch (e) { _hfmt = null; }

  /** ميلادي → هجري [hy, hm, hd] */
  function gregToHijri(gy, gm, gd) {
    if (_hfmt) {
      var parts = _hfmt.formatToParts(new Date(Date.UTC(gy, gm - 1, gd, 12)));
      var o = {};
      parts.forEach(function (p) { if (p.type !== 'literal') o[p.type] = parseInt(p.value, 10); });
      if (o.year && o.month && o.day) return [o.year, o.month, o.day];
    }
    return _kuwaiti(gy, gm, gd);
  }

  /** هجري → ميلادي [gy, gm, gd] — تقدير ثم ضبط بالبحث (±5 أيام) */
  function hijriToGreg(hy, hm, hd) {
    var est = f((11 * hy + 3) / 30) + 354 * hy + 30 * hm - f((hm - 1) / 2) + hd + 1948440 - 385;
    var g = jdToGreg(est);
    for (var off = -5; off <= 5; off++) {
      var dd = new Date(Date.UTC(g[0], g[1] - 1, g[2] + off, 12));
      var h = gregToHijri(dd.getUTCFullYear(), dd.getUTCMonth() + 1, dd.getUTCDate());
      if (h[0] === hy && h[1] === hm && h[2] === hd) {
        return [dd.getUTCFullYear(), dd.getUTCMonth() + 1, dd.getUTCDate()];
      }
    }
    return g;
  }

  /** عدد أيام الشهر الهجري */
  function hijriMonthLen(hy, hm) {
    var a = hijriToGreg(hy, hm, 1);
    var nm = hm === 12 ? [hy + 1, 1] : [hy, hm + 1];
    var b = hijriToGreg(nm[0], nm[1], 1);
    return gregToJD(b[0], b[1], b[2]) - gregToJD(a[0], a[1], a[2]);
  }

  /** حدود سنوات (١٩٠٠–٢٢٠٠م، تطابق ما كان يقبله المنتقي القديم) محوَّلة للهجري عند الحاجة */
  function yearBounds(hijri) {
    if (!hijri) return [1900, 2200];
    return [gregToHijri(1900, 1, 1)[0], gregToHijri(2200, 12, 31)[0]];
  }

  /* ── CSS ── */
  if (!document.getElementById('hcal-css')) {
    var st = document.createElement('style');
    st.id = 'hcal-css';
    st.textContent = [
      '.hcal-field{direction:rtl}',
      '.hcal-row{display:flex;flex-wrap:wrap;align-items:center;gap:6px}',
      '.hcal-row select{min-width:0}',
      '.hcal-row select.hcal-wd{flex:0 0 auto;width:84px;color:#64748b;background-color:#f1f5f9}',
      '.hcal-row select.hcal-day{flex:0 0 auto;width:68px}',
      '.hcal-row select.hcal-month{flex:1 1 130px}',
      '.hcal-row select.hcal-year{flex:0 0 auto;width:88px}',
      '.hcal-row input[type=time]{flex:0 0 auto;width:100px}',
      '.hcal-row .hcal-btn{flex:0 0 auto;padding-inline:8px;line-height:1.5}',
      '.hcal-alt{font-size:11.5px;color:#94a3b8;margin-top:3px;min-height:14px}',
      // وضع مبسّط لحقول الفلترة/البحث: يوم+شهر+سنة فقط، بلا اسم يوم ولا أزرار ولا سطر بديل
      '.hcal-field.hcal-compact .hcal-wd{display:none!important}',
      '.hcal-field.hcal-compact .hcal-btn{display:none!important}',
      '.hcal-field.hcal-compact .hcal-alt{display:none!important}',
      '.hcal-field.hcal-compact .hcal-row{gap:5px;flex-wrap:nowrap}',
      '.hcal-field.hcal-compact .hcal-day{width:58px}',
      '.hcal-field.hcal-compact .hcal-year{width:78px}'
    ].join('');
    document.head.appendChild(st);
  }

  function sizeSuffix(cls) {
    return / form-control-sm| form-select-sm/.test(' ' + cls) ? ' form-select-sm' : '';
  }

  function enhance(orig) {
    if (orig.dataset.hcalDone || orig.type === 'hidden') return;
    // حقول فلترة/بحث بالتاريخ (من-إلى): نفس منتقي هجري/ميلادي لكن بصفّ مبسّط —
    // بدون اسم اليوم ولا أزرار اليوم/المسح/التبديل ولا سطر التقويم البديل
    var compact = orig.classList.contains('mk-plain-date');
    orig.dataset.hcalDone = '1';
    var isDT = orig.type === 'datetime-local';
    var required = orig.hasAttribute('required');
    var initVal = orig.value;
    var szCls = sizeSuffix(orig.className);

    // الحقل الأصلي يصبح مخفياً ويحمل القيمة الميلادية للسيرفر
    var hidden = orig;
    hidden.type = 'hidden';
    hidden.removeAttribute('required');

    var wrap = document.createElement('div');
    wrap.className = 'hcal-field' + (compact ? ' hcal-compact' : '');
    hidden.parentNode.insertBefore(wrap, hidden);
    wrap.appendChild(hidden);

    var row = document.createElement('div');
    row.className = 'hcal-row';
    wrap.appendChild(row);

    function mkSelect(cls) {
      var s = document.createElement('select');
      s.className = 'form-select' + szCls + ' ' + cls;
      if (required) s.dataset.req = '1';
      return s;
    }
    var selWd = mkSelect('hcal-wd'); selWd.disabled = true; delete selWd.dataset.req;
    var selDay = mkSelect('hcal-day');
    var selMonth = mkSelect('hcal-month');
    var selYear = mkSelect('hcal-year');
    row.appendChild(selWd); row.appendChild(selDay); row.appendChild(selMonth); row.appendChild(selYear);

    var timeInp = null;
    if (isDT) {
      timeInp = document.createElement('input');
      timeInp.type = 'time';
      timeInp.className = 'form-control' + szCls;
      row.appendChild(timeInp);
    }

    var todayBtn = document.createElement('button');
    todayBtn.type = 'button'; todayBtn.className = 'btn btn-outline-secondary hcal-btn' + (szCls ? ' btn-sm' : '');
    todayBtn.textContent = 'اليوم';
    var clrBtn = document.createElement('button');
    clrBtn.type = 'button'; clrBtn.className = 'btn btn-outline-secondary hcal-btn' + (szCls ? ' btn-sm' : '');
    clrBtn.title = 'مسح'; clrBtn.textContent = '✕';
    var swapBtn = document.createElement('button');
    swapBtn.type = 'button'; swapBtn.className = 'btn btn-outline-secondary hcal-btn' + (szCls ? ' btn-sm' : '');
    row.appendChild(todayBtn); row.appendChild(clrBtn); row.appendChild(swapBtn);

    var altCap = document.createElement('div');
    altCap.className = 'hcal-alt';
    wrap.appendChild(altCap);

    var state = { hijri: START_HIJRI, y: null, m: null, d: null, time: '09:00' };

    // قائمة اسم اليوم: تُعرَض دوماً كقائمة منسدلة لكنها معطّلة ومُزامَنة تلقائياً —
    // اسم اليوم نتيجة حسابية للتاريخ (يوم+شهر+سنة)، فلا معنى لاختياره يدوياً
    // بمعزل عن بقية الحقول (سيُنتج تناقضاً: يوم باسم لا يطابق تاريخه الفعلي).
    (function fillWd() {
      var ph = document.createElement('option'); ph.value = ''; ph.textContent = '—'; selWd.appendChild(ph);
      WD.forEach(function (w, i) {
        var o = document.createElement('option'); o.value = i; o.textContent = w; selWd.appendChild(o);
      });
    })();

    function monthNames() { return state.hijri ? H_MONTHS : G_MONTHS; }

    function fillMonthSelect() {
      var keep = selMonth.value;
      selMonth.innerHTML = '';
      var ph = document.createElement('option'); ph.value = ''; ph.textContent = 'الشهر'; selMonth.appendChild(ph);
      monthNames().forEach(function (name, i) {
        var o = document.createElement('option'); o.value = i + 1; o.textContent = name; selMonth.appendChild(o);
      });
      if (keep) selMonth.value = keep;
    }

    function fillYearSelect() {
      var keep = selYear.value;
      selYear.innerHTML = '';
      var ph = document.createElement('option'); ph.value = ''; ph.textContent = 'السنة'; selYear.appendChild(ph);
      var b = yearBounds(state.hijri);
      for (var y = b[0]; y <= b[1]; y++) {
        var o = document.createElement('option'); o.value = y; o.textContent = y; selYear.appendChild(o);
      }
      if (keep) selYear.value = keep;
    }

    function monthLen(y, m) {
      if (!y || !m) return 30;
      return state.hijri ? hijriMonthLen(y, m) : new Date(y, m, 0).getDate();
    }

    function fillDaySelect(keepDay) {
      var len = monthLen(state.y, state.m) || 30;
      selDay.innerHTML = '';
      var ph = document.createElement('option'); ph.value = ''; ph.textContent = 'اليوم'; selDay.appendChild(ph);
      for (var d = 1; d <= len; d++) {
        var o = document.createElement('option'); o.value = d; o.textContent = d; selDay.appendChild(o);
      }
      if (keepDay && keepDay <= len) selDay.value = keepDay;
    }

    function partsToGreg() {
      if (!(state.y && state.m && state.d)) return null;
      return state.hijri ? hijriToGreg(state.y, state.m, state.d) : [state.y, state.m, state.d];
    }

    function updateWd() {
      var g = partsToGreg();
      selWd.value = g ? String((gregToJD(g[0], g[1], g[2]) + 1) % 7) : '';
    }

    function updateAlt() {
      var g = partsToGreg();
      if (!g) { altCap.textContent = ''; return; }
      if (state.hijri) {
        altCap.textContent = pad(g[2]) + '/' + pad(g[1]) + '/' + g[0] + ' م';
      } else {
        var h = gregToHijri(g[0], g[1], g[2]);
        altCap.textContent = h[2] + ' ' + H_MONTHS[h[1] - 1] + ' ' + h[0] + ' هـ';
      }
    }

    function commit() {
      var g = partsToGreg();
      if (g) {
        var gs = g[0] + '-' + pad(g[1]) + '-' + pad(g[2]);
        hidden.value = isDT ? (gs + 'T' + state.time) : gs;
      } else {
        hidden.value = '';
      }
      clearErr();
      updateWd();
      updateAlt();
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function clearErr() {
      selDay.style.borderColor = selMonth.style.borderColor = selYear.style.borderColor = '';
    }

    function renderAll() {
      fillMonthSelect();
      fillYearSelect();
      selMonth.value = state.m || '';
      selYear.value = state.y || '';
      fillDaySelect(state.d);
      selDay.value = state.d || '';
      if (isDT) timeInp.value = state.time;
      swapBtn.textContent = state.hijri ? 'م' : 'هـ';
      swapBtn.title = state.hijri ? 'التبديل لعرض ميلادي' : 'التبديل لعرض هجري';
      updateWd();
      updateAlt();
    }

    /** يقرأ التاريخ الميلادي المخزَّن في الحقل المخفي ويملأ حالة القوائم منه */
    function loadFromHidden() {
      var v = (hidden.value || '').trim();
      if (!v) { state.y = state.m = state.d = null; return; }
      var dp = isDT ? (v.split('T')[0] || '') : v;
      if (isDT && v.split('T')[1]) state.time = v.split('T')[1].slice(0, 5);
      var mm = dp.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
      if (!mm) { state.y = state.m = state.d = null; return; }
      var gy = +mm[1], gm = +mm[2], gd = +mm[3];
      if (state.hijri) {
        var h = gregToHijri(gy, gm, gd);
        state.y = h[0]; state.m = h[1]; state.d = h[2];
      } else {
        state.y = gy; state.m = gm; state.d = gd;
      }
    }

    selYear.addEventListener('change', function () {
      state.y = selYear.value ? +selYear.value : null;
      fillDaySelect(state.d);
      state.d = selDay.value ? +selDay.value : null;
      commit();
    });
    selMonth.addEventListener('change', function () {
      state.m = selMonth.value ? +selMonth.value : null;
      fillDaySelect(state.d);
      state.d = selDay.value ? +selDay.value : null;
      commit();
    });
    selDay.addEventListener('change', function () {
      state.d = selDay.value ? +selDay.value : null;
      commit();
    });
    if (isDT) {
      timeInp.addEventListener('change', function () {
        state.time = timeInp.value || '09:00';
        commit();
      });
    }
    todayBtn.addEventListener('click', function () {
      var n = new Date();
      if (state.hijri) {
        var h = gregToHijri(n.getFullYear(), n.getMonth() + 1, n.getDate());
        state.y = h[0]; state.m = h[1]; state.d = h[2];
      } else {
        state.y = n.getFullYear(); state.m = n.getMonth() + 1; state.d = n.getDate();
      }
      renderAll();
      commit();
    });
    clrBtn.addEventListener('click', function () {
      state.y = state.m = state.d = null;
      renderAll();
      commit();
    });
    swapBtn.addEventListener('click', function () {
      var g = partsToGreg();
      state.hijri = !state.hijri;
      if (g) {
        if (state.hijri) { var h = gregToHijri(g[0], g[1], g[2]); state.y = h[0]; state.m = h[1]; state.d = h[2]; }
        else { state.y = g[0]; state.m = g[1]; state.d = g[2]; }
      }
      renderAll();
    });

    hidden.value = initVal;
    loadFromHidden();
    renderAll();
  }

  function scan(root) {
    (root || document).querySelectorAll('input[type="date"],input[type="datetime-local"]').forEach(enhance);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(); });
  } else { scan(); }
  document.addEventListener('shown.bs.modal', function (e) { scan(e.target); });

  // تحقّق الحقول المطلوبة (لأن الحقل الحقيقي صار مخفياً)
  document.addEventListener('submit', function (e) {
    var bad = null;
    e.target.querySelectorAll('.hcal-field').forEach(function (w) {
      var need = w.querySelector('select[data-req]');
      var hid = w.querySelector('input[type="hidden"]');
      if (need && hid && !hid.value) {
        if (!bad) bad = w.querySelector('select.hcal-day');
        ['.hcal-day', '.hcal-month', '.hcal-year'].forEach(function (sel) {
          var el = w.querySelector(sel);
          if (el) el.style.borderColor = '#ef4444';
        });
      }
    });
    if (bad) { e.preventDefault(); bad.scrollIntoView({ block: 'center' }); bad.focus(); }
  }, true);
})();
