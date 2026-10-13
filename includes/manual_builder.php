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

/**
 * معلومات شعار المنصّة المرفوع من «إعدادات النظام» في الأدمن (site_logo) بصيغة صالحة للرسم داخل PDF.
 * يدعم png/jpg/gif/webp/svg. إن تعذّر استخدام الشعار المرفوع لا نستبدله بشعار آخر (نكتفي باسم المنصة)،
 * ولا يُستخدم الشعار الافتراضي إلا إذا لم يرفع الأدمن شعاراً أصلاً.
 * يُرجع: source (uploaded|default|none) ، kind (raster|svg|none) ، path ، reason (سبب التعذّر إن وُجد).
 */
function manual_logo_info($conn): array {
    if (!function_exists('site_logo')) require_once __DIR__ . '/content_helper.php';
    $root = dirname(__DIR__);
    $lg   = site_logo($conn);
    $info = ['source' => $lg !== '' ? 'uploaded' : 'default', 'kind' => 'none', 'path' => '', 'reason' => ''];
    $f    = $lg !== '' ? $root . '/' . ltrim($lg, '/') : $root . '/assets/img/logo-x.png';
    if (!is_file($f)) { $info['reason'] = 'ملف الشعار غير موجود على الخادم'; if ($lg === '') $info['source'] = 'none'; return $info; }

    $ext    = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $hasImg = extension_loaded('gd') || extension_loaded('imagick');
    $dir    = $root . '/uploads/manual';
    $tmpOut = function (string $suffix) use ($dir, $f) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/logo-' . md5($f . filemtime($f)) . $suffix;
    };

    if ($ext === 'svg') {
        $info['kind'] = 'svg'; $info['path'] = $f; return $info;
    }
    if ($ext === 'jpg' || $ext === 'jpeg') {
        $info['kind'] = 'raster'; $info['path'] = $f; return $info;
    }
    if ($ext === 'png') {
        $hdr = @file_get_contents($f, false, null, 0, 33);
        $ct  = ($hdr !== false && strlen($hdr) >= 26) ? ord($hdr[25]) : 2;
        if (($ct === 4 || $ct === 6) && !$hasImg) {
            // PNG بشفافية بلا GD/Imagick: ندمجه على خلفية بيضاء بدالة PHP خالصة بدل التخلي عنه
            $out = $tmpOut('-flat.png');
            if (is_file($out) || manual_png_flatten($f, $out)) { $info['kind'] = 'raster'; $info['path'] = $out; }
            else $info['reason'] = 'صيغة PNG شفاف غير مدعومة بدون امتداد GD — ارفع الشعار بصيغة JPG أو PNG بلا شفافية';
            return $info;
        }
        $info['kind'] = 'raster'; $info['path'] = $f; return $info;
    }
    if ($ext === 'gif' || $ext === 'webp') {
        // يحتاجان GD: نحوّلهما إلى PNG مدمج على الأبيض
        $out = $tmpOut('.png');
        if (!is_file($out) && function_exists('imagecreatefromwebp') && $hasImg && extension_loaded('gd')) {
            $im = $ext === 'webp' ? @imagecreatefromwebp($f) : @imagecreatefromgif($f);
            if ($im) {
                $w = imagesx($im); $h = imagesy($im);
                $bg = imagecreatetruecolor($w, $h); imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
                imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
                @imagepng($bg, $out); imagedestroy($im); imagedestroy($bg);
            }
        }
        if (is_file($out)) { $info['kind'] = 'raster'; $info['path'] = $out; }
        else $info['reason'] = 'صيغة ' . strtoupper($ext) . ' تحتاج امتداد GD في الخادم — ارفع الشعار بصيغة PNG أو JPG أو SVG';
        return $info;
    }
    $info['reason'] = 'صيغة الشعار غير مدعومة';
    return $info;
}

/** مسار ملف الشعار (للبصمة) أو '' */
function manual_logo_file($conn): string {
    $i = manual_logo_info($conn);
    return $i['kind'] !== 'none' ? $i['path'] : '';
}

/**
 * يدمج PNG شفاف (RGBA/GA بعمق 8 بت وبلا تشذير) على خلفية بيضاء ويحفظه RGB — بدون GD.
 * يُرجع false لأي صيغة أخرى فيُكتفى باسم المنصة.
 */
function manual_png_flatten(string $src, string $dst): bool {
    $d = @file_get_contents($src);
    if ($d === false || substr($d, 0, 8) !== "\x89PNG\r\n\x1a\n") return false;
    $pos = 8; $len = strlen($d); $ihdr = null; $idat = '';
    while ($pos + 8 <= $len) {
        $n = unpack('N', substr($d, $pos, 4))[1]; $type = substr($d, $pos + 4, 4);
        $body = substr($d, $pos + 8, $n); $pos += 12 + $n;
        if ($type === 'IHDR') $ihdr = unpack('Nw/Nh/Cdepth/Cct/Ccomp/Cfilter/Cinter', $body);
        elseif ($type === 'IDAT') $idat .= $body;
        elseif ($type === 'IEND') break;
    }
    if (!$ihdr || $ihdr['depth'] !== 8 || $ihdr['inter'] !== 0 || !in_array($ihdr['ct'], [4, 6], true)) return false;
    if ($ihdr['w'] * $ihdr['h'] > 4000000) return false;
    $raw = @gzuncompress($idat);
    if ($raw === false) return false;
    $w = $ihdr['w']; $h = $ihdr['h']; $bpp = $ihdr['ct'] === 6 ? 4 : 2; $stride = $w * $bpp;
    if (strlen($raw) < ($stride + 1) * $h) return false;
    $prev = array_fill(0, $stride, 0); $out = '';
    for ($y = 0; $y < $h; $y++) {
        $ft = ord($raw[$y * ($stride + 1)]);
        $line = array_values(unpack('C*', substr($raw, $y * ($stride + 1) + 1, $stride)));
        for ($i = 0; $i < $stride; $i++) {
            $a = $i >= $bpp ? $line[$i - $bpp] : 0; $b = $prev[$i]; $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
            switch ($ft) {
                case 1: $line[$i] = ($line[$i] + $a) & 255; break;
                case 2: $line[$i] = ($line[$i] + $b) & 255; break;
                case 3: $line[$i] = ($line[$i] + (($a + $b) >> 1)) & 255; break;
                case 4:
                    $p = $a + $b - $c; $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                    $pr = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
                    $line[$i] = ($line[$i] + $pr) & 255; break;
            }
        }
        $prev = $line;
        $row = "\0";
        for ($x = 0; $x < $w; $x++) {
            if ($bpp === 4) { $r = $line[$x * 4]; $g = $line[$x * 4 + 1]; $bl = $line[$x * 4 + 2]; $al = $line[$x * 4 + 3]; }
            else            { $r = $g = $bl = $line[$x * 2]; $al = $line[$x * 2 + 1]; }
            $row .= chr((int)(($r * $al + 255 * (255 - $al)) / 255)) . chr((int)(($g * $al + 255 * (255 - $al)) / 255)) . chr((int)(($bl * $al + 255 * (255 - $al)) / 255));
        }
        $out .= $row;
    }
    $chunk = function ($t, $b) { return pack('N', strlen($b)) . $t . $b . pack('N', crc32($t . $b)); };
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($out, 6)) . $chunk('IEND', '');
    return @file_put_contents($dst, $png) !== false;
}

/**
 * يرسم الشعار داخل الصندوق (x,y,w,h) محافظاً على النسبة ومتوسّطاً (raster أو svg).
 * يُستدعى بينما RTL مُعطَّل (إحداثيات من اليسار).
 */
function manual_draw_logo(TCPDF $pdf, array $info, float $x, float $y, float $w, float $h): void {
    if ($info['kind'] === 'raster') {
        $pdf->Image($info['path'], $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 0, 'CM', false, false);
    } elseif ($info['kind'] === 'svg') {
        $svg = (string)@file_get_contents($info['path'], false, null, 0, 20000);
        $r = 1.0;
        if (preg_match('/viewBox\s*=\s*"[\d.\-]+[ ,]+[\d.\-]+[ ,]+([\d.]+)[ ,]+([\d.]+)"/i', $svg, $m) && (float)$m[2] > 0) $r = (float)$m[1] / (float)$m[2];
        elseif (preg_match('/width\s*=\s*"([\d.]+)/i', $svg, $mw) && preg_match('/height\s*=\s*"([\d.]+)/i', $svg, $mh) && (float)$mh[1] > 0) $r = (float)$mw[1] / (float)$mh[1];
        $dw = min($w, $h * $r); $dh = $dw / max($r, 0.01);
        $pdf->ImageSVG($info['path'], $x + ($w - $dw) / 2, $y + ($h - $dh) / 2, $dw, $dh);
    }
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
    public $mLogo = []; public $mSite = ''; public $mVersion = ''; public $mTocLink = 0;

    public function Header() {
        $w = $this->getPageWidth();
        $r = $this->rtl; $this->rtl = false;
        $this->SetFillColor(12, 27, 54);
        $this->Rect(0, 0, $w, 13, 'F');
        $this->SetFillColor(184, 134, 11);
        $this->Rect(0, 13, $w, 0.8, 'F');
        $hasLogo = !empty($this->mLogo) && $this->mLogo['kind'] !== 'none';
        if ($hasLogo) { // شارة بيضاء خلف الشعار لتظهر كل الشعارات (فاتحة/داكنة) بوضوح على الشريط الكحلي
            $this->SetFillColor(255, 255, 255);
            $this->RoundedRect($w - 30, 2, 18, 9, 1.6, '1111', 'F');
            manual_draw_logo($this, $this->mLogo, $w - 29, 2.6, 16, 7.8);
        }
        $this->rtl = $r;
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('amiri', '', 9);
        // في وضع RTL الإحداثي X يُقاس من الحافة اليمنى: نبدأ بعد الشعار حتى لا يتداخلا
        $off = $hasLogo ? 34 : 14;
        $this->SetXY($off, 3.5);
        $this->Cell($w - $off - 14, 6, 'دليل المستخدم — ' . $this->mSite, 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer() {
        $w = $this->getPageWidth(); $h = $this->getPageHeight();
        $this->SetDrawColor(221, 227, 236);
        $this->Line(14, $h - 15, $w - 14, $h - 15);
        $this->SetFont('amiri', '', 8.5);
        $this->SetTextColor(107, 114, 128);
        $this->SetXY(14, $h - 13);
        $this->Cell($w - 28, 6, 'الإصدار ' . $this->mVersion . '   •   صفحة ' . $this->getAliasNumPage() . ' من ' . $this->getAliasNbPages(), 0, 0, 'C');
        if ($this->mTocLink) { // رابط سريع للفهرس (كتاب إلكتروني)
            $this->SetTextColor(184, 134, 11);
            $this->SetXY($w - 38, $h - 13);
            $this->Cell(24, 6, 'الفهرس', 0, 0, 'L', false, $this->mTocLink);
        }
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
    $logo    = manual_logo_info($conn);
    $over    = manual_module_overrides();
    $entries = [];

    // كتاب إلكتروني: صفحة A5 (مقاس مريح للقراءة على الجوال واللوح) بهوامش وخط أكبر نسبياً
    $pdf = new ManualPDF('P', 'mm', 'A5', true, 'UTF-8', false);
    $pdf->mLogo = $logo; $pdf->mSite = $site; $pdf->mVersion = $vi['version'];
    $pdf->SetCreator('مِحكام'); $pdf->SetAuthor($site);
    $pdf->SetTitle('دليل المستخدم — ' . $site . ' — الإصدار ' . $vi['version']);
    $pdf->SetSubject('الكتاب الإلكتروني لدليل استخدام منصة ' . $site);
    $pdf->SetKeywords('دليل المستخدم, ' . $site . ', مكاتب المحاماة, الإصدار ' . $vi['version']);
    $pdf->setHeaderMargin(0); $pdf->setFooterMargin(0);
    $pdf->SetMargins(13, 22, 13, true);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->setRTL(true);
    $pdf->setFontSubsetting(true);
    $pdf->setDisplayMode('fullpage', 'SinglePage', 'UseOutlines'); // يفتح بلوحة الفهرس الجانبية (Bookmarks) في القارئات
    $pdf->SetFont('amiri', '', 11);
    $tocLink = $pdf->AddLink(); $pdf->mTocLink = $tocLink;

    /* ── الغلاف ── (بلا فاصل صفحات تلقائي حتى لا ينزلق سطر أسفل الغلاف لصفحة جديدة) */
    $pdf->SetAutoPageBreak(false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $W = $pdf->getPageWidth(); $H = $pdf->getPageHeight();
    $navyH = round($H * 0.6);
    $pdf->setRTL(false, false);
    $pdf->SetFillColor(12, 27, 54);   $pdf->Rect(0, 0, $W, $navyH, 'F');
    $pdf->SetFillColor(184, 134, 11); $pdf->Rect(0, $navyH, $W, 2, 'F');
    $pdf->SetFillColor(255, 255, 255);
    $pdf->RoundedRect(($W - 60) / 2, 22, 60, 40, 4.5, '1111', 'F');
    manual_draw_logo($pdf, $logo, ($W - 50) / 2, 26, 50, 32);
    $pdf->setRTL(true, false);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('amiri', 'B', 30);
    $pdf->SetXY(12, 74); $pdf->Cell($W - 24, 14, 'دليل المستخدم', 0, 1, 'C');
    $pdf->SetFont('amiri', '', 13);
    $pdf->SetXY(12, 91); $pdf->Cell($W - 24, 8, 'منصة ' . $site . ' لإدارة مكاتب المحاماة', 0, 1, 'C');
    $pdf->SetFont('amiri', '', 10.5);
    $pdf->SetTextColor(232, 192, 64);
    $pdf->SetXY(12, 105); $pdf->Cell($W - 24, 7, 'الكتاب الإلكتروني الشامل لكل الوحدات والموديولات', 0, 1, 'C');
    $pdf->SetTextColor(12, 27, 54);
    $pdf->SetFont('amiri', 'B', 15);
    $pdf->SetXY(12, $navyH + 14); $pdf->Cell($W - 24, 9, 'الإصدار ' . $vi['version'], 0, 1, 'C');
    $pdf->SetFont('amiri', '', 10.5); $pdf->SetTextColor(100, 108, 122);
    $pdf->SetXY(12, $navyH + 25); $pdf->Cell($W - 24, 7, 'تاريخ الإصدار: ' . ($vi['date'] !== '' ? $vi['date'] : date('Y-m-d')), 0, 1, 'C');
    if ($vi['sha'] !== '') { $pdf->SetXY(12, $navyH + 33); $pdf->Cell($W - 24, 7, 'رقم البناء: ' . $vi['sha'], 0, 1, 'C'); }
    $pdf->SetFont('amiri', '', 9);
    $pdf->SetXY(12, $H - 20); $pdf->Cell($W - 24, 7, 'يتجدّد هذا الكتاب تلقائياً مع كل إصدار جديد للمنصة', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);

    /* ── الفهرس ── (الذيل يُرسم عند إغلاق الصفحة: نُبقيه مُطفأً حتى تُغلَق صفحة الغلاف) */
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->setPrintHeader(true); $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetLink($tocLink, 0, $pdf->getPage());
    $pdf->setPrintFooter(true);
    $pdf->SetFont('amiri', 'B', 20); $pdf->SetTextColor(12, 27, 54);
    $pdf->Cell(0, 12, 'الفهرس', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('amiri', '', 11);
    $pdf->Ln(2);
    $pdf->writeHTML(manual_toc_html($tocEntries), true, false, true, false, '');

    /* ── أدوات الكتابة ── */
    $note = function (int $lvl, string $title) use ($pdf, &$entries) {
        $pdf->Bookmark($title, $lvl, 0, '', '', [60, 60, 60]);
        $entries[] = [$lvl, $title, $pdf->getPage()];
    };
    $chapterNo = 0;
    // صفحة افتتاحية لكل فصل (كالكتب): خلفية كحلية بالرقم والعنوان، ثم يبدأ المحتوى في الصفحة التالية
    $chapterTitle = function (string $t, string $subtitle = '') use ($pdf, &$chapterNo, $note, $W, $H, $logo) {
        $chapterNo++;
        // ذيل الصفحة السابقة يُرسم عند إغلاقها (الآن) فنُبقيه مُشغَّلاً ونُطفئ الرأس فقط لصفحة الافتتاح
        $pdf->setPrintHeader(false); $pdf->setPrintFooter(true);
        $pdf->AddPage();
        $pdf->setPrintFooter(false);
        $note(0, $chapterNo . ' — ' . $t);
        $pdf->setRTL(false, false);
        $pdf->SetFillColor(12, 27, 54);   $pdf->Rect(0, 0, $W, $H, 'F');
        $pdf->SetFillColor(184, 134, 11); $pdf->Rect(($W - 34) / 2, $H * 0.5 + 6, 34, 1.4, 'F');
        $pdf->setRTL(true, false);
        $pdf->SetTextColor(232, 192, 64);
        $pdf->SetFont('amiri', 'B', 64);
        $pdf->SetXY(12, $H * 0.22); $pdf->Cell($W - 24, 34, (string)$chapterNo, 0, 1, 'C');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('amiri', 'B', 24);
        $pdf->SetXY(14, $H * 0.4); $pdf->MultiCell($W - 28, 12, $t, 0, 'C', false, 1);
        if ($subtitle !== '') {
            $pdf->SetFont('amiri', '', 11); $pdf->SetTextColor(200, 208, 222);
            $pdf->SetXY(18, $H * 0.5 + 14); $pdf->MultiCell($W - 36, 7, $subtitle, 0, 'C', false, 1);
        }
        $pdf->SetTextColor(0, 0, 0);
        // الصفحة التالية: محتوى الفصل بالرأس والذيل (ذيل صفحة الافتتاح يبقى مُطفأً لأنه يُرسم عند إغلاقها)
        $pdf->setPrintHeader(true);
        $pdf->AddPage();
        $pdf->setPrintFooter(true);
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
    $cn = $chapterTitle('الوحدات الأساسية', count(manual_core_chapters()) . ' وحدة أساسية لإدارة مكتبك');
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
    $groups = pkg_modules_grouped($conn);
    $total = 0; foreach ($groups as $rows) $total += count($rows);
    $mn = $chapterTitle('الموديولات الإضافية', $total . ' موديولاً في ' . count($groups) . ' أقسام — وما يُضاف مستقبلاً');
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
