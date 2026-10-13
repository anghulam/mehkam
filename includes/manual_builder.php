<?php
/**
 * includes/manual_builder.php — توليد دليل المستخدم (PDF) بشعار المنصّة ورقم الإصدار.
 *
 *   require_once 'includes/manual_builder.php';
 *   $file = manual_pdf_file($conn);            // مسار PDF مخزَّن مؤقتاً (يُعاد توليده تلقائياً عند أي تغيير)
 *
 * يتجدّد الدليل تلقائياً عندما يتغيّر أي من: رقم الإصدار، الموديولات المسجّلة (اسم/وصف/أيقونة/تفعيل)،
 * شعار المنصة أو اسمها، محتوى صفحات الموديولات، أو ملفات محتوى الدليل نفسها.
 */

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/manual_content.php';
require_once __DIR__ . '/module_helper.php';
require_once __DIR__ . '/pdf.php';

define('MANUAL_NAVY', '#0c1b36');
define('MANUAL_GOLD', '#b8860b');

/* ═════════ مساعدات عامة ═════════ */

function manual_site_name($conn): string {
    if (!function_exists('sc')) require_once __DIR__ . '/content_helper.php';
    $n = trim((string)sc($conn, 'site_name', 'مِحكام'));
    return $n !== '' ? $n : 'مِحكام';
}

/** مسار شعار المنصة المرفوع كملف محلي صالح لـ TCPDF (png/jpg/gif)، أو '' إن لم يتوفر */
function manual_logo_file($conn): string {
    if (!function_exists('site_logo')) require_once __DIR__ . '/content_helper.php';
    $root = dirname(__DIR__);
    $cands = [];
    $lg = site_logo($conn);
    if ($lg !== '') $cands[] = $root . '/' . ltrim($lg, '/');
    $cands[] = $root . '/assets/img/logo-x.png';
    foreach ($cands as $f) {
        if (!is_file($f)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $hasImg = extension_loaded('gd') || extension_loaded('imagick');
        if ($ext === 'png') {
            // PNG بشفافية يحتاج GD/Imagick في TCPDF وإلا يتوقف توليد الملف كله — نتخطاه بأمان إن لم يتوفرا
            $hdr = @file_get_contents($f, false, null, 0, 33);
            $ct  = ($hdr !== false && strlen($hdr) >= 26) ? ord($hdr[25]) : 2;
            if (($ct === 4 || $ct === 6) && !$hasImg) continue;
            return $f;
        }
        if ($ext === 'jpg' || $ext === 'jpeg') return $f;
        if ($ext === 'gif' && $hasImg) return $f;
        // webp → png عبر GD (TCPDF لا يقرأ webp مباشرة)
        if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            $dir = $root . '/uploads/manual'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $out = $dir . '/logo-' . md5($f . filemtime($f)) . '.png';
            if (!is_file($out)) {
                $im = @imagecreatefromwebp($f);
                if ($im) { imagealphablending($im, false); imagesavealpha($im, true); @imagepng($im, $out); imagedestroy($im); }
            }
            if (is_file($out)) return $out;
        }
        // svg وغيره غير مدعوم في ملف PDF → نكمل للشعار الافتراضي
    }
    return '';
}

/** يستخرج من ملف صفحة موديول: التبويبات والأزرار والأعمدة وحقول النماذج — لشرح آلي يتحدّث مع الصفحة نفسها */
function manual_introspect(string $file): ?array {
    $s = @file_get_contents($file);
    if ($s === false) return null;
    $clean = function ($t) {
        $t = preg_replace('/<\?(?:php|=).*?\?>/s', '', $t);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t));
    };
    $ar = fn($c) => $c !== '' && preg_match('/\p{Arabic}/u', $c);
    $o = ['tabs' => [], 'buttons' => [], 'cols' => [], 'fields' => [], 'modals' => []];
    $grab = function ($re, $key, $max = 40) use ($s, $clean, $ar, &$o) {
        if (preg_match_all($re, $s, $m)) foreach ($m[1] as $x) {
            $c = trim(preg_replace('/^[>\s]+|[\s\*:]+$/u', '', $clean($x)));
            if ($ar($c) && mb_strlen($c) < $max) $o[$key][$c] = 1;
        }
    };
    $grab('/class="nav-link[^"]*"[^>]*>(.*?)<\/a>/s', 'tabs');
    $grab('/<(?:button|a)[^>]*class="[^"]*btn (?:btn-primary|btn-success|btn-outline-primary)[^"]*"[^>]*>(.*?)<\/(?:button|a)>/s', 'buttons');
    $grab('/<th[^>]*>(.*?)<\/th>/s', 'cols', 30);
    $grab('/<label[^>]*class="[^"]*form-label[^"]*"[^>]*>(.*?)<\/label>/s', 'fields', 35);
    $grab('/class="modal-title"[^>]*>(.*?)<\/h5>/s', 'modals');
    foreach ($o as $k => $v) $o[$k] = array_keys($v);
    return $o;
}

/** كل عناصر الدليل المتغيّرة (للبصمة): الإصدار + الموديولات + الشعار + اسم المنصة + تواريخ تعديل الملفات */
function manual_signature($conn): string {
    $mods = [];
    try {
        $r = $conn->query("SELECT module_key,name,description,icon,is_active,sort_order FROM modules ORDER BY module_key");
        if ($r) while ($m = $r->fetch_assoc()) $mods[] = $m;
    } catch (\Throwable $e) {}
    $root = dirname(__DIR__);
    $lg = manual_logo_file($conn);
    $mt = [];
    foreach (glob($root . '/office/*.php') ?: [] as $f) $mt[] = basename($f) . filemtime($f);
    foreach (['manual_content.php', 'manual_builder.php', 'version.php'] as $f) $mt[] = $f . filemtime(__DIR__ . '/' . $f);
    return sha1(json_encode([mehkam_version(), mehkam_changelog(), $mods, manual_site_name($conn), $lg, $lg ? filemtime($lg) : 0, $mt]));
}

/** **غامق** → <b> مع الهروب الآمن */
function manual_fmt(string $t): string {
    $t = htmlspecialchars(str_replace('←', ' / ', $t), ENT_QUOTES, 'UTF-8'); // خط amiri لا يحوي رمز السهم
    return preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $t);
}

/* ═════════ قوالب HTML ═════════ */

function manual_h_step_rows(array $steps): string {
    $h = '<table cellpadding="4" cellspacing="0" width="100%">';
    foreach (array_values($steps) as $i => $st) {
        $h .= '<tr nobr="true"><td width="7%" align="center" style="color:' . MANUAL_GOLD . ';font-weight:bold;font-size:11pt">' . ($i + 1) . '</td>'
            . '<td width="93%" style="border-bottom:0.4pt solid #eceff4">' . manual_fmt($st) . '</td></tr>';
    }
    return $h . '</table>';
}

function manual_h_tips(array $tips): string {
    $h = '';
    foreach ($tips as $t) {
        $h .= '<table cellpadding="6" cellspacing="0" width="100%" style="margin-top:3px"><tr><td width="100%" bgcolor="#fdf8e8" style="border:0.5pt solid #ead9a4;color:#6b4e00;font-size:9.5pt"><b>نصيحة:</b> ' . manual_fmt($t) . '</td></tr></table><br>';
    }
    return $h;
}

function manual_h_label(string $t): string {
    return '<div style="color:' . MANUAL_NAVY . ';font-weight:bold;font-size:11pt">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</div>';
}

function manual_h_where(string $menu): string {
    return '<table cellpadding="5" cellspacing="0" width="100%"><tr><td bgcolor="#eef2fb" style="border-right:2.5pt solid ' . MANUAL_NAVY . ';font-size:9.5pt;color:#334155"><b>موضعها في النظام:</b> ' . htmlspecialchars(str_replace('←', ' / ', $menu), ENT_QUOTES, 'UTF-8') . '</td></tr></table>';
}

/** كتلة «ما تجده في الصفحة» من الاستخراج الآلي */
function manual_h_page_elements(?array $i): string {
    if (!$i) return '';
    $rows = [];
    $q = fn($a, $n) => '«' . implode('»، «', array_slice($a, 0, $n)) . '»';
    if ($i['tabs'])    $rows[] = ['التبويبات', $q($i['tabs'], 8)];
    $btn = array_values(array_filter($i['buttons'], fn($b) => !in_array($b, ['حفظ', 'إلغاء', 'بحث', 'تصفية', 'إغلاق', 'عرض الكل', 'تطبيق'], true)));
    if ($btn)          $rows[] = ['الإجراءات', $q($btn, 6)];
    if ($i['cols'])    $rows[] = ['أعمدة الجدول', $q($i['cols'], 9)];
    if ($i['fields'])  $rows[] = ['حقول الإدخال', $q($i['fields'], 10)];
    if (!$rows) return '';
    $h = '<table cellpadding="4" cellspacing="0" width="100%" style="border:0.4pt solid #dde3ec">';
    foreach ($rows as [$k, $v]) {
        $h .= '<tr nobr="true"><td width="19%" bgcolor="#f5f6f8" style="font-size:9pt;color:#5b6472"><b>' . $k . '</b></td><td width="81%" style="font-size:9.5pt">' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    return $h . '</table><br>';
}

/** خطوات آلية لموديول بلا شرح مخصّص */
function manual_generated_steps(array $m, ?array $i): array {
    $steps = ['افتح «' . $m['name'] . '» من القائمة الجانبية ضمن قسم «إضافات» (يظهر بعد تفعيل الموديول لمكتبك).'];
    if (!$i) return $steps;
    if (count($i['tabs']) > 1) $steps[] = 'تنقّل بين التبويبات: «' . implode('»، «', array_slice($i['tabs'], 0, 6)) . '».';
    $btn = array_values(array_filter($i['buttons'], fn($b) => !in_array($b, ['حفظ', 'إلغاء', 'بحث', 'تصفية', 'إغلاق', 'عرض الكل', 'تطبيق'], true)));
    if ($btn) {
        $s = 'لإضافة سجل أو بدء إجراء اضغط «' . $btn[0] . '»';
        if ($i['fields']) $s .= ' ثم عبّئ الحقول (' . implode('، ', array_slice($i['fields'], 0, 6)) . ')';
        $steps[] = $s . ' واحفظ.';
    }
    if ($i['cols']) $steps[] = 'تابع السجلات في الجدول (' . implode('، ', array_slice($i['cols'], 0, 6)) . ') وعدّلها أو احذفها من عمود الإجراءات.';
    return $steps;
}

/* ═════════ فئة PDF ═════════ */

class ManualPDF extends TCPDF {
    public $mLogo = ''; public $mSite = ''; public $mVersion = '';

    public function Header() {
        $w = $this->getPageWidth();
        $this->SetFillColor(12, 27, 54);
        $r = $this->rtl; $this->rtl = false;
        $this->Rect(0, 0, $w, 13, 'F');
        $this->SetFillColor(184, 134, 11);
        $this->Rect(0, 13, $w, 0.8, 'F');
        if ($this->mLogo !== '' && is_file($this->mLogo)) {
            $this->Image($this->mLogo, $w - 28, 2.6, 0, 7.8, '', '', '', false, 300, '', false, false, 0, false, false, false);
        }
        $this->rtl = $r;
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('amiri', '', 9);
        // في وضع RTL الإحداثي X يُقاس من الحافة اليمنى: نبدأ بعد الشعار (34mm) حتى لا يتداخلا
        $this->SetXY($this->mLogo !== '' ? 34 : 15, 3.5);
        $this->Cell($w - ($this->mLogo !== '' ? 34 : 15) - 15, 6, 'دليل المستخدم — ' . $this->mSite, 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer() {
        $w = $this->getPageWidth(); $h = $this->getPageHeight();
        $this->SetDrawColor(221, 227, 236);
        $this->Line(15, $h - 15, $w - 15, $h - 15);
        $this->SetFont('amiri', '', 8.5);
        $this->SetTextColor(107, 114, 128);
        $this->SetXY(15, $h - 13);
        $this->Cell($w - 30, 6, 'الإصدار ' . $this->mVersion . '   •   صفحة ' . $this->getAliasNumPage() . ' من ' . $this->getAliasNbPages(), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

/* ═════════ البناء ═════════ */

/** عدد بنود الفهرس المتوقعة (فصول + وحدات + أقسام موديولات + موديولات) */
function manual_count_entries($conn): int {
    $groups = pkg_modules_grouped($conn);
    $n = 3 /* فصول عامة */ + 1 + count(manual_core_chapters()) + 1 + count($groups) + 3 /* باقات + أسئلة + تحديثات */;
    foreach ($groups as $rows) $n += count($rows);
    return $n;
}

/** صفحات الفهرس: بنود مرتّبة كجدول بصفحة الوجهة وروابط داخلية */
function manual_toc_html(array $entries): string {
    $h = '<table cellpadding="3" cellspacing="0" width="100%">';
    foreach ($entries as $e) {
        [$lvl, $title, $page] = $e;
        $pad  = $lvl * 4;
        $style = $lvl === 0 ? 'font-weight:bold;font-size:12pt;color:' . MANUAL_NAVY : ($lvl === 1 ? 'font-size:10.5pt;color:#334155' : 'font-size:9.5pt;color:#64748b');
        $h .= '<tr><td width="' . (88 - $pad) . '%" style="' . $style . ';border-bottom:0.3pt solid #e6e9ef"><a href="#' . (int)$page . '" style="text-decoration:none;color:inherit">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</a></td>'
           . '<td width="' . (12 + $pad) . '%" align="left" style="' . $style . ';border-bottom:0.3pt solid #e6e9ef">' . ($page ? (int)$page : '') . '</td></tr>';
    }
    return $h . '</table>';
}

/**
 * يرسم الدليل مرة واحدة: $tocEntries = بنود الفهرس بأرقام صفحاتها (أو أرقام وهمية في المرور الأول).
 * يُعيد البنود الفعلية [مستوى, عنوان, صفحة] لاستخدامها في المرور الثاني.
 */
function manual_render_pdf($conn, string $outFile, array $tocEntries): array {
    $root    = dirname(__DIR__);
    $site    = manual_site_name($conn);
    $vi      = mehkam_version_info();
    $logo    = manual_logo_file($conn);
    $over    = manual_module_overrides();
    $entries = [];

    $pdf = new ManualPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->mLogo = $logo; $pdf->mSite = $site; $pdf->mVersion = $vi['version'];
    $pdf->SetCreator('مِحكام'); $pdf->SetAuthor($site);
    $pdf->SetTitle('دليل المستخدم — ' . $site . ' — الإصدار ' . $vi['version']);
    $pdf->setHeaderMargin(0); $pdf->setFooterMargin(0);
    $pdf->SetMargins(16, 24, 16, true);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->setRTL(true);
    $pdf->setFontSubsetting(true);
    $pdf->SetFont('amiri', '', 11);

    /* ── الغلاف ── */
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $W = $pdf->getPageWidth(); $H = $pdf->getPageHeight();
    $pdf->setRTL(false, false);
    $pdf->SetFillColor(12, 27, 54);  $pdf->Rect(0, 0, $W, 170, 'F');
    $pdf->SetFillColor(184, 134, 11); $pdf->Rect(0, 170, $W, 2.2, 'F');
    $pdf->SetFillColor(255, 255, 255);
    $pdf->RoundedRect(($W - 70) / 2, 28, 70, 46, 5, '1111', 'F');
    if ($logo !== '') {
        $pdf->Image($logo, ($W - 58) / 2, 33, 58, 36, '', '', '', true, 300, '', false, false, 0, 'CM', false, false);
    }
    $pdf->setRTL(true, false);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('amiri', 'B', 36);
    $pdf->SetXY(16, 88); $pdf->Cell($W - 32, 16, 'دليل المستخدم', 0, 1, 'C');
    $pdf->SetFont('amiri', '', 15);
    $pdf->SetXY(16, 108); $pdf->Cell($W - 32, 9, 'منصة ' . $site . ' لإدارة مكاتب المحاماة', 0, 1, 'C');
    $pdf->SetFont('amiri', '', 11);
    $pdf->SetTextColor(232, 192, 64);
    $pdf->SetXY(16, 124); $pdf->Cell($W - 32, 8, 'دليل شامل لكل الوحدات والموديولات', 0, 1, 'C');
    $pdf->SetTextColor(12, 27, 54);
    $pdf->SetFont('amiri', 'B', 16);
    $pdf->SetXY(16, 195); $pdf->Cell($W - 32, 10, 'الإصدار ' . $vi['version'], 0, 1, 'C');
    $pdf->SetFont('amiri', '', 11); $pdf->SetTextColor(100, 108, 122);
    $pdf->SetXY(16, 207); $pdf->Cell($W - 32, 8, 'تاريخ الإصدار: ' . ($vi['date'] !== '' ? $vi['date'] : date('Y-m-d')), 0, 1, 'C');
    if ($vi['sha'] !== '') { $pdf->SetXY(16, 215); $pdf->Cell($W - 32, 8, 'رقم البناء: ' . $vi['sha'], 0, 1, 'C'); }
    $pdf->SetFont('amiri', '', 9.5);
    $pdf->SetXY(16, $H - 28); $pdf->Cell($W - 32, 8, 'يتجدّد هذا الدليل تلقائياً مع كل إصدار جديد للمنصة', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);

    /* ── الفهرس ── (الذيل يُرسم عند إغلاق الصفحة: نُبقيه مُطفأً حتى تُغلَق صفحة الغلاف) */
    $pdf->setPrintHeader(true); $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->setPrintFooter(true);
    $pdf->SetFont('amiri', 'B', 22); $pdf->SetTextColor(12, 27, 54);
    $pdf->Cell(0, 14, 'الفهرس', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('amiri', '', 11);
    $pdf->Ln(2);
    $pdf->writeHTML(manual_toc_html($tocEntries), true, false, true, false, '');

    /* ── أدوات الكتابة ── */
    $note = function (int $lvl, string $title) use ($pdf, &$entries) {
        $pdf->Bookmark($title, $lvl, 0, '', '', [60, 60, 60]);
        $entries[] = [$lvl, $title, $pdf->getPage()];
    };
    $chapterNo = 0;
    $chapterTitle = function (string $t) use ($pdf, &$chapterNo, $note) {
        $chapterNo++;
        $pdf->AddPage();
        $note(0, $chapterNo . ' — ' . $t);
        $pdf->writeHTML('<table cellpadding="6" cellspacing="0" width="100%"><tr><td width="100%" bgcolor="' . MANUAL_NAVY . '" style="color:#ffffff;font-size:19pt;font-weight:bold;border-bottom:3pt solid ' . MANUAL_GOLD . '">' . $chapterNo . ' — ' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</td></tr></table><br>', true, false, true, false, '');
        return $chapterNo;
    };
    $sub = function (string $t, int $level, string $num) use ($pdf, $note) {
        $pdf->writeHTML('<div style="color:' . MANUAL_NAVY . ';font-size:' . ($level === 1 ? '15' : '13') . 'pt;font-weight:bold;border-bottom:0.8pt solid #dde3ec">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</div>', true, false, true, false, '');
        $note($level, $t);
    };
    $emit = function (string $html) use ($pdf) { $pdf->writeHTML($html, true, false, true, false, ''); };
    // عنوان فرعي: لا يُترك وحيداً آخر الصفحة (ننتقل لصفحة جديدة إن لم يتّسع معه ما بعده)
    $lbl = function (string $t) use ($pdf, $emit) { $pdf->Ln(1.5); if ($pdf->GetY() + 32 > $pdf->getPageHeight() - $pdf->getBreakMargin()) $pdf->AddPage(); $emit(manual_h_label($t)); };
    $pageEl = function (?array $i) use ($lbl, $emit) { $h = manual_h_page_elements($i); if ($h !== '') { $lbl('ما تجده في الصفحة'); $emit($h); } };

    /* ── الفصول العامة (عن الدليل / البدء السريع / الأدوار) ── */
    $renderBlocks = function (array $blocks) use ($emit, $lbl) {
        foreach ($blocks as $b) {
            if (!empty($b['h'])) $lbl($b['h']);
            if (!empty($b['p'])) $emit('<p style="text-align:justify">' . manual_fmt($b['p']) . '</p>');
            if (!empty($b['steps'])) $emit(manual_h_step_rows($b['steps']) . '<br>');
            if (!empty($b['tips'])) $emit(manual_h_tips($b['tips']));
        }
    };
    foreach (manual_general_chapters() as $ch) { $chapterTitle($ch['title']); $renderBlocks($ch['blocks']); }

    /* ── الوحدات الأساسية ── */
    $cn = $chapterTitle('الوحدات الأساسية');
    $emit('<p>الوحدات التالية هي أساس النظام وتتوفر حسب باقتك وصلاحياتك. لكل وحدة: الغرض منها، وموضعها في القائمة، وخطوات العمل عليها، وما تجده في صفحتها.</p>');
    $k = 0;
    foreach (manual_core_chapters() as $c) {
        $k++;
        $sub($c['title'], 1, $cn . '.' . $k);
        if (!empty($c['menu'])) $emit(manual_h_where($c['menu'] . (!empty($c['owner']) ? '  (لمالك المكتب)' : '')) . '<br>');
        $emit('<p style="text-align:justify">' . manual_fmt($c['about']) . '</p>');
        if (!empty($c['steps'])) { $lbl('خطوات الاستخدام'); $emit(manual_h_step_rows($c['steps']) . '<br>'); }
        $pageEl(manual_introspect($root . '/office/' . $c['page']));
        if (!empty($c['tips'])) $emit(manual_h_tips($c['tips']));
        $emit('<br>');
    }

    /* ── الموديولات الإضافية (ديناميكية من جدول modules) ── */
    $mn = $chapterTitle('الموديولات الإضافية');
    $groups = pkg_modules_grouped($conn);
    $total = 0; foreach ($groups as $rows) $total += count($rows);
    $emit('<p style="text-align:justify">الموديولات إضافات اختيارية تُفعَّل حسب باقتك أو تطلبها من «متجر الموديولات». يضم النظام حالياً <b>' . $total . '</b> موديولاً مصنّفة أدناه. ما يُضاف مستقبلاً من موديولات يدخل هذا الفصل تلقائياً مع الإصدار الجديد.</p>');
    $emit(manual_h_tips(['الموديول الذي لا تراه في قائمتك: إما ليس ضمن باقتك فاطلبه من «متجر الموديولات»، أو لا تملك صلاحية عرضه فاطلبها من مالك المكتب.']));
    $g = 0;
    foreach ($groups as $catName => $mods) {
        $g++;
        $sub($catName . ' (' . count($mods) . ')', 1, $mn . '.' . $g);
        $j = 0;
        foreach ($mods as $m) {
            $j++;
            $num = $mn . '.' . $g . '.' . $j;
            $info = manual_introspect($root . '/office/' . $m['module_key'] . '.php');
            $o = $over[$m['module_key']] ?? null;
            $steps = $o['steps'] ?? manual_generated_steps($m, $info);
            $html = '<table cellpadding="5" cellspacing="0" width="100%" nobr="true"><tr><td bgcolor="#f1f4fa" style="border-right:3pt solid ' . MANUAL_GOLD . ';font-size:12.5pt;font-weight:bold;color:' . MANUAL_NAVY . '">' . htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') . '</td></tr></table>';
            if (!empty($m['description'])) $html .= '<p style="text-align:justify;margin:2px 0">' . htmlspecialchars($m['description'], ENT_QUOTES, 'UTF-8') . '</p>';
            $emit($html);
            $note(2, $m['name']);
            $lbl('طريقة الاستخدام'); $emit(manual_h_step_rows($steps));
            $pageEl($info);
            if (!empty($o['tips'])) $emit(manual_h_tips($o['tips']));
            if (!$info) $emit('<p style="color:#9aa4b2;font-size:9pt">صفحة هذا الموديول قيد الإعداد وستُشرح تفاصيلها في الإصدار القادم من الدليل.</p>');
            $emit('<br>');
        }
    }

    /* ── الباقات + الأسئلة الشائعة + سجل التحديثات ── */
    $plans = manual_plans_chapter();
    $chapterTitle($plans['title']); $renderBlocks($plans['blocks']);

    $chapterTitle('الأسئلة الشائعة');
    foreach (manual_faq() as [$q, $a]) {
        $emit('<table cellpadding="5" cellspacing="0" width="100%" nobr="true"><tr><td bgcolor="#f5f6f8" style="font-weight:bold;color:' . MANUAL_NAVY . '">س: ' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '</td></tr><tr><td style="border-bottom:0.4pt solid #eceff4">' . manual_fmt($a) . '</td></tr></table><br>');
    }

    $chapterTitle('سجل التحديثات');
    $emit('<p>الإصدار الحالي: <b>' . $vi['version'] . '</b>' . ($vi['date'] !== '' ? ' — بتاريخ ' . $vi['date'] : '') . '. يتغيّر رقم الإصدار تلقائياً مع كل تحديث يُنشر على المنصة.</p>');
    foreach (mehkam_changelog() as $e) {
        $emit('<table cellpadding="5" cellspacing="0" width="100%" nobr="true"><tr><td bgcolor="#eef2fb" style="border-right:2.5pt solid ' . MANUAL_NAVY . ';font-weight:bold">' . htmlspecialchars($e['title'], ENT_QUOTES, 'UTF-8') . '  <span style="color:#6b7280;font-weight:normal;font-size:9pt">' . htmlspecialchars($e['date'], ENT_QUOTES, 'UTF-8') . '</span></td></tr></table>' . manual_h_step_rows($e['items']) . '<br>');
    }

    $pdf->Output($outFile, 'F');
    return $entries;
}

/**
 * يبني الدليل بمرورين: الأول لمعرفة صفحة كل بند، والثاني يرسم الفهرس بأرقام الصفحات الصحيحة.
 * (يُعاد المرور إن تغيّر ترقيم الصفحات بين المرورين.)
 */
function manual_build_pdf($conn, string $outFile): void {
    $dummy = array_fill(0, manual_count_entries($conn), [0, 'عنوان', 0]);
    $entries = manual_render_pdf($conn, $outFile, $dummy);
    for ($i = 0; $i < 3; $i++) {
        $next = manual_render_pdf($conn, $outFile, $entries);
        if ($next === $entries) break;
        $entries = $next;
    }
}

/** مسار الدليل الجاهز — يُعاد توليده تلقائياً عند تغيّر الإصدار أو أي من محتوياته */
function manual_pdf_file($conn, bool $force = false): string {
    $dir = dirname(__DIR__) . '/uploads/manual';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $sig  = substr(manual_signature($conn), 0, 12);
    $file = $dir . '/manual-v' . preg_replace('/[^0-9A-Za-z.\-]/', '', mehkam_version()) . '-' . $sig . '.pdf';
    if (!$force && is_file($file) && filesize($file) > 1000) return $file;

    @set_time_limit(180);
    $tmp = $file . '.tmp';
    manual_build_pdf($conn, $tmp);
    if (!is_file($tmp)) throw new \RuntimeException('manual build failed');
    @rename($tmp, $file);
    foreach (glob($dir . '/manual-*.pdf') ?: [] as $old) if ($old !== $file) @unlink($old); // إبقاء أحدث نسخة فقط
    return $file;
}

/** يرسل الدليل للمتصفح (عرض أو تحميل) */
function manual_send($conn, bool $download = false, bool $force = false): void {
    $file = manual_pdf_file($conn, $force);
    $name = 'دليل-المستخدم-' . mehkam_version() . '.pdf';
    if (ob_get_length()) @ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($file));
    header("Content-Disposition: " . ($download ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($file);
    exit;
}
