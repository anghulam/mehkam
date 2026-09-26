<?php
/**
 * محلل نصوص قانونية عربية (بدون خدمات خارجية): رسائل المحكمة/ناجز وصحائف الدعوى.
 * يستخرج: رقم القضية، التاريخ (ميلادي/هجري)، الوقت، المحكمة، نوع القضية، الأطراف، المبلغ.
 * النتائج اقتراحات — يراجعها المستخدم قبل الحفظ دائماً.
 */

function lp_digits($t) {
    return strtr((string)$t, [
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
    ]);
}

/** تحويل هجري → ميلادي (حساب جدولي تقريبي — قد يختلف يوماً عن أم القرى) */
function lp_hijri_to_greg($hy, $hm, $hd) {
    $jd = intdiv(11 * $hy + 3, 30) + 354 * $hy + 30 * $hm - intdiv($hm - 1, 2) + $hd + 1948440 - 385;
    $l = $jd + 68569;
    $n = intdiv(4 * $l, 146097);
    $l = $l - intdiv(146097 * $n + 3, 4);
    $i = intdiv(4000 * ($l + 1), 1461001);
    $l = $l - intdiv(1461 * $i, 4) + 31;
    $j = intdiv(80 * $l, 2447);
    $d = $l - intdiv(2447 * $j, 80);
    $l = intdiv($j, 11);
    $m = $j + 2 - 12 * $l;
    $y = 100 * ($n - 49) + $i + $l;
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/** يعيد ['date'=>'Y-m-d','hijri'=>bool] أو null */
function lp_find_date($t) {
    $t = lp_digits($t);
    $cands = [];
    if (preg_match_all('#(\d{4})\s*[/\-.]\s*(\d{1,2})\s*[/\-.]\s*(\d{1,2})#u', $t, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $cands[] = [(int)$x[1], (int)$x[2], (int)$x[3]];
    }
    if (preg_match_all('#(\d{1,2})\s*[/\-.]\s*(\d{1,2})\s*[/\-.]\s*(\d{4})#u', $t, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $cands[] = [(int)$x[3], (int)$x[2], (int)$x[1]];
    }
    foreach ($cands as [$y, $mo, $d]) {
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) continue;
        if ($y >= 1400 && $y <= 1500) return ['date' => lp_hijri_to_greg($y, $mo, $d), 'hijri' => true];
        if ($y >= 2000 && $y <= 2100 && checkdate($mo, $d, $y)) return ['date' => sprintf('%04d-%02d-%02d', $y, $mo, $d), 'hijri' => false];
    }
    return null;
}

/** يعيد 'HH:MM' (24س) أو '' */
function lp_find_time($t) {
    $t = lp_digits($t);
    if (!preg_match('#(\d{1,2})\s*:\s*(\d{2})\s*(ص|م|صباحاً|صباحا|مساءً|مساء|AM|PM|am|pm)?#u', $t, $m)) return '';
    $h = (int)$m[1]; $mi = (int)$m[2];
    $suffix = $m[3] ?? '';
    if ($suffix !== '') {
        $pm = in_array($suffix, ['م','مساءً','مساء','PM','pm'], true);
        if ($pm && $h < 12) $h += 12;
        if (!$pm && $h == 12) $h = 0;
    }
    if ($h > 23 || $mi > 59) return '';
    return sprintf('%02d:%02d', $h, $mi);
}

function lp_find_case_number($t) {
    $t = lp_digits($t);
    if (preg_match('#(?:رقم\s*(?:القضية|الدعوى|الطلب|القضيه)|(?:القضية|الدعوى|دعوى|قضية)\s*رقم)\s*[:：]?\s*([0-9][0-9/\-]{4,})#u', $t, $m)) return trim($m[1], '-/');
    if (preg_match('#\b([34]\d{9})\b#', $t, $m)) return $m[1]; // أرقام ناجز الشائعة (10 خانات)
    return '';
}

function lp_find_court($t) {
    if (preg_match('#(المحكمة\s+[^\n\r.،,؛:]{3,60}|محكمة\s+[^\n\r.،,؛:]{3,60}|ديوان\s+المظالم[^\n\r.،,؛:]{0,40}|الدائرة\s+[^\n\r.،,؛:]{3,50})#u', $t, $m)) {
        // نقطع عند أول كلمة تدل على بداية جملة جديدة (للمطالبة، بشأن، بتاريخ...)
        return trim(preg_split('#\s+(?=للمطالبة|بشأن|بخصوص|في\s|على\s|ضد\s|لنظر|وذلك|حيث|بتاريخ|الساعة|رقم)#u', $m[1])[0]);
    }
    return '';
}

function lp_guess_case_type($t) {
    $map = [
        'عمالية'       => ['عمالية','عامل','صاحب العمل','مكافأة نهاية','أجور متأخرة','فصل تعسفي','مكتب العمل'],
        'تجارية'       => ['تجارية','شركة','سجل تجاري','شيك','كمبيالة','سند لأمر','إفلاس','تجاري'],
        'أحوال شخصية'  => ['نفقة','حضانة','طلاق','خلع','زواج','زيارة','ولاية','إرث','تركة','أحوال شخصية'],
        'جزائية'       => ['جزائية','جنائية','بلاغ','اتهام','النيابة','تعزير','قذف','سرقة','اعتداء'],
        'إدارية'       => ['إدارية','ديوان المظالم','جهة إدارية','قرار إداري'],
        'عقارية'       => ['عقار','إيجار','إخلاء','صك','أرض','ملكية'],
    ];
    $best = ''; $bestScore = 0;
    foreach ($map as $type => $words) {
        $score = 0;
        foreach ($words as $w) if (mb_strpos($t, $w) !== false) $score++;
        if ($score > $bestScore) { $bestScore = $score; $best = $type; }
    }
    return $best;
}

/** أكبر مبلغ مذكور مع «ريال» */
function lp_find_amount($t) {
    $t = lp_digits($t);
    $best = 0;
    if (preg_match_all('#(\d{1,3}(?:[,٬]\d{3})+|\d+(?:\.\d+)?)\s*(?:ريال|ر\.?\s?س|SAR)#u', $t, $m)) {
        foreach ($m[1] as $v) { $n = (float)str_replace([',', '٬'], '', $v); if ($n > $best) $best = $n; }
    }
    return $best;
}

function lp_find_parties($t) {
    $out = ['plaintiff' => '', 'defendant' => ''];
    if (preg_match('#(?:المدعي|المدعى)(?!\s*عليه)\s*[:：]\s*([^\n\r]{2,80})#u', $t, $m)) $out['plaintiff'] = trim(preg_replace('#\s*(?:هوية|سجل|جوال|رقم).*$#u', '', $m[1]));
    if (preg_match('#(?:المدعى\s*عليه|المدعي\s*عليه)\s*[:：]\s*([^\n\r]{2,80})#u', $t, $m)) $out['defendant'] = trim(preg_replace('#\s*(?:هوية|سجل|جوال|رقم).*$#u', '', $m[1]));
    return $out;
}

/** تحليل رسالة جلسة/إشعار محكمة */
function lp_parse_court_message($t) {
    $d = lp_find_date($t);
    return [
        'case_number' => lp_find_case_number($t),
        'date'        => $d['date'] ?? '',
        'hijri'       => $d['hijri'] ?? false,
        'time'        => lp_find_time($t),
        'court'       => lp_find_court($t),
    ];
}

/** تحليل صحيفة دعوى/حكم (محلي بدون ذكاء اصطناعي) */
function lp_parse_petition($t) {
    $p = lp_find_parties($t);
    $d = lp_find_date($t);
    $summary = trim(mb_substr(preg_replace('#\s+#u', ' ', $t), 0, 600));
    return [
        'case_number' => lp_find_case_number($t),
        'court'       => lp_find_court($t),
        'case_type'   => lp_guess_case_type($t),
        'plaintiff'   => $p['plaintiff'],
        'defendant'   => $p['defendant'],
        'amount'      => lp_find_amount($t),
        'session_date'=> $d['date'] ?? '',
        'session_time'=> lp_find_time($t),
        'hijri'       => $d['hijri'] ?? false,
        'summary'     => $summary,
    ];
}
