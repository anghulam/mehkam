<?php
/**
 * includes/print_letterhead.php
 * طبقة «الليتر هيد» لصفحات الطباعة (فواتير / عقود / تقارير).
 *
 *  الاستخدام:
 *    داخل <head> بعد <style> الصفحة:   <?= lh_head($settings) ?>
 *    على <body>:                       <body class="<?= lh_class($settings) ?>">
 *    بعد <body> مباشرة:                <?= lh_body($settings) ?>
 *
 *  الطباعة / PDF:
 *   - @page margin يفسح مكان الترويسة/التذييل على كل صفحة.
 *   - .lh-band.top / .lh-band.bot (position:fixed) تعرض شريط الليتر هيد العلوي
 *     والسفلي، ويتكرّران تلقائياً على كل صفحة، والمتصفّح يوزّع المحتوى على
 *     عدة صفحات A4 عند بلوغ الهامش السفلي.
 *   - فعّل «رسومات الخلفية / Background graphics» في نافذة الطباعة.
 *
 *  الشاشة: معاينة على ورقة واحدة متصلة (الترويسة أعلى، التذييل أسفل).
 *
 *  office_settings: letterhead_top / letterhead_bottom / letterhead_side (مم).
 */

if (!function_exists('lh_active')):

function lh_active($s) {
    if (empty($s['letterhead_enabled']) || empty($s['letterhead_path'])) return false;
    return is_file(__DIR__ . '/../' . ltrim($s['letterhead_path'], '/'));
}

function lh_class($s) {
    return lh_active($s) ? 'has-letterhead' : '';
}

function _lh_url($s) {
    $rel = ltrim($s['letterhead_path'], '/');
    $v = @filemtime(__DIR__ . '/../' . $rel) ?: 1;
    return '../' . htmlspecialchars($rel, ENT_QUOTES) . '?v=' . $v;
}

function _lh_dims($s) {
    return [
        max(0, min(150, (int) ($s['letterhead_top'] ?? 42))),
        max(0, min(150, (int) ($s['letterhead_bottom'] ?? 26))),
        max(0, min(70,  (int) ($s['letterhead_side'] ?? 18))),
    ];
}

function lh_head($s) {
    if (!lh_active($s)) return '';
    [$T, $B, $Sd] = _lh_dims($s);
    $url = _lh_url($s);
    return <<<CSS
<style>
  @page { size: A4 portrait; margin: {$T}mm {$Sd}mm {$B}mm {$Sd}mm; }

  body.has-letterhead { background:#e9edf2 !important; }

  /* ── الشاشة: ورقة واحدة، الليتر هيد كخلفية (ترويسة أعلى + تذييل أسفل) ── */
  body.has-letterhead .page,
  body.has-letterhead .report-wrap {
    box-sizing:border-box !important; width:210mm !important; margin:20px auto !important;
    min-height:297mm !important; border-radius:2px;
    box-shadow:0 6px 28px rgba(15,23,42,.16) !important;
    background-color:#fff !important;
    background-image:url("$url"), url("$url") !important;
    background-repeat:no-repeat, no-repeat !important;
    background-position:top center, bottom center !important;
    background-size:210mm auto, 210mm auto !important;
    padding:{$T}mm {$Sd}mm {$B}mm {$Sd}mm !important;
  }

  /* الشعار/الترويسة المدمجة تُخفى لأن الليتر هيد يحتويها */
  body.has-letterhead .c-head,
  body.has-letterhead .zr-head,
  body.has-letterhead .lh-hide { display:none !important; }
  body.has-letterhead .inv-head { border-bottom:none !important; padding-bottom:4px !important; }

  /* وحدات لا تُقطَع بين صفحتين */
  body.has-letterhead .c-clause,
  body.has-letterhead .c-sign,
  body.has-letterhead .party-box,
  body.has-letterhead .inv-meta-box,
  body.has-letterhead table tr { break-inside:avoid; page-break-inside:avoid; }

  /* أشرطة الليتر هيد للطباعة فقط */
  .lh-band { display:none; }

  @media print {
    body.has-letterhead { background:#fff !important; }
    body.has-letterhead .page,
    body.has-letterhead .report-wrap {
      width:auto !important; margin:0 !important; min-height:0 !important;
      box-shadow:none !important; border-radius:0 !important;
      background:#fff !important; padding:0 !important;
    }
    body.has-letterhead .lh-band {
      display:block !important; position:fixed !important; left:0 !important; right:0 !important;
      z-index:9; pointer-events:none;
      background-image:url("$url") !important; background-repeat:no-repeat !important;
      background-size:100% auto !important;
      -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important;
    }
    body.has-letterhead .lh-band.top { top:0 !important;    height:{$T}mm !important; background-position:center top !important; }
    body.has-letterhead .lh-band.bot { bottom:0 !important; height:{$B}mm !important; background-position:center bottom !important; }
  }
</style>
CSS;
}

function lh_body($s) {
    if (!lh_active($s)) return '';
    return '<div class="lh-band top"></div><div class="lh-band bot"></div>';
}

/* أُبقيت فارغة — التوافق مع استدعاءات <?= lh_foot() ?> */
function lh_foot($s) { return ''; }

endif;
