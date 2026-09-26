<?php
/**
 * includes/pdf.php — توليد PDF على الخادم (TCPDF)
 * ─────────────────────────────────────────────────────────────
 *  - ورقة A4، دعم عربي RTL كامل.
 *  - إن رفع المكتب «ليتر هيد» يُرسم كخلفية صفحة كاملة على كل صفحة،
 *    والمحتوى يتوزّع تلقائياً على عدة صفحات ضمن الهوامش المحدّدة.
 *
 *  الاستخدام:
 *    require_once '../includes/pdf.php';
 *    mehkam_make_pdf($settings, $htmlBody, 'عقد-123.pdf', ['title' => '...']);
 *
 *  $settings = صف office_settings (letterhead_path / letterhead_enabled /
 *             letterhead_top / letterhead_bottom / letterhead_side).
 * ─────────────────────────────────────────────────────────────
 */

if (!class_exists('TCPDF')) {
    // منع أي إعداد افتراضي يكتب مساراً مطلقاً خاطئاً
    if (!defined('K_TCPDF_EXTERNAL_CONFIG')) define('K_TCPDF_EXTERNAL_CONFIG', true);
    if (!defined('K_PATH_MAIN'))  define('K_PATH_MAIN',  dirname(__DIR__) . '/vendor/tcpdf/');
    if (!defined('K_PATH_URL'))   define('K_PATH_URL',   '');
    if (!defined('K_PATH_FONTS')) define('K_PATH_FONTS', K_PATH_MAIN . 'fonts/');
    if (!defined('K_PATH_CACHE')) {
        $_c = dirname(__DIR__) . '/uploads/pdfcache/';
        if (!is_dir($_c)) @mkdir($_c, 0775, true);
        define('K_PATH_CACHE', is_dir($_c) && is_writable($_c) ? $_c : (sys_get_temp_dir() . '/'));
    }
    if (!defined('K_PATH_IMAGES')) define('K_PATH_IMAGES', dirname(__DIR__) . '/');
    if (!defined('K_BLANK_IMAGE')) define('K_BLANK_IMAGE', K_PATH_MAIN . 'examples/images/_blank.png');
    if (!defined('PDF_PAGE_FORMAT')) define('PDF_PAGE_FORMAT', 'A4');
    if (!defined('PDF_PAGE_ORIENTATION')) define('PDF_PAGE_ORIENTATION', 'P');
    if (!defined('PDF_UNIT')) define('PDF_UNIT', 'mm');
    if (!defined('PDF_FONT_NAME_MAIN')) define('PDF_FONT_NAME_MAIN', 'amiri');
    if (!defined('PDF_FONT_SIZE_MAIN')) define('PDF_FONT_SIZE_MAIN', 11);
    if (!defined('PDF_FONT_NAME_DATA')) define('PDF_FONT_NAME_DATA', 'amiri');
    if (!defined('PDF_FONT_SIZE_DATA')) define('PDF_FONT_SIZE_DATA', 9);
    if (!defined('PDF_FONT_MONOSPACED')) define('PDF_FONT_MONOSPACED', 'courier');
    if (!defined('PDF_IMAGE_SCALE_RATIO')) define('PDF_IMAGE_SCALE_RATIO', 1.25);
    if (!defined('PDF_MARGIN_HEADER')) define('PDF_MARGIN_HEADER', 0);
    if (!defined('PDF_MARGIN_FOOTER')) define('PDF_MARGIN_FOOTER', 0);
    if (!defined('PDF_MARGIN_TOP')) define('PDF_MARGIN_TOP', 20);
    if (!defined('PDF_MARGIN_BOTTOM')) define('PDF_MARGIN_BOTTOM', 20);
    if (!defined('PDF_MARGIN_LEFT')) define('PDF_MARGIN_LEFT', 15);
    if (!defined('PDF_MARGIN_RIGHT')) define('PDF_MARGIN_RIGHT', 15);
    if (!defined('HEAD_MAGNIFICATION')) define('HEAD_MAGNIFICATION', 1.1);
    if (!defined('K_CELL_HEIGHT_RATIO')) define('K_CELL_HEIGHT_RATIO', 1.25);
    if (!defined('K_TITLE_MAGNIFICATION')) define('K_TITLE_MAGNIFICATION', 1.3);
    if (!defined('K_SMALL_RATIO')) define('K_SMALL_RATIO', 0.66);
    if (!defined('K_THAI_TOPCHARS')) define('K_THAI_TOPCHARS', true);
    if (!defined('K_TCPDF_CALLS_IN_HTML')) define('K_TCPDF_CALLS_IN_HTML', false);
    if (!defined('K_TCPDF_THROW_EXCEPTION_ERROR')) define('K_TCPDF_THROW_EXCEPTION_ERROR', false);
    require_once dirname(__DIR__) . '/vendor/tcpdf/tcpdf.php';
}

if (!class_exists('MehkamPDF')):

class MehkamPDF extends TCPDF
{
    public $lhImg = '';       // مسار صورة الليتر هيد (مطلق)
    public $lhFallback = '';   // ترويسة نصية بديلة (HTML) إن لا ليتر هيد

    public function Header()
    {
        if ($this->lhImg !== '' && @is_file($this->lhImg)) {
            // صورة الليتر هيد بمقاس الورقة الكامل — نُصفّر الهوامش مؤقتاً
            // حتى لا يُقلّص TCPDF الصورة لتناسب منطقة الطباعة.
            $save = [
                'rtl' => $this->rtl,
                'lM'  => $this->lMargin, 'rM' => $this->rMargin,
                'tM'  => $this->tMargin, 'bM' => $this->bMargin,
                'apb' => $this->AutoPageBreak, 'pbmar' => $this->bMargin,
            ];
            $this->rtl = false;
            $this->lMargin = 0; $this->rMargin = 0;
            $this->tMargin = 0; $this->bMargin = 0;
            $this->SetAutoPageBreak(false);
            $this->SetAlpha(1);
            $this->Image(
                $this->lhImg, 0, 0, $this->w, $this->h,
                '', '', '', false, 300, '', false, false, 0, false, false, false
            );
            // استعادة
            $this->rtl = $save['rtl'];
            $this->lMargin = $save['lM']; $this->rMargin = $save['rM'];
            $this->tMargin = $save['tM']; $this->bMargin = $save['bM'];
            $this->SetAutoPageBreak($save['apb'], $save['pbmar']);
        } elseif ($this->lhFallback !== '') {
            $y = $this->GetY();
            $this->SetXY($this->lMargin, 7);
            $this->writeHTMLCell(0, 0, '', '', $this->lhFallback, 0, 1, false, true, '', true);
            $this->SetY($y);
        }
    }

    public function Footer()
    {
        // لا تذييل نصي — التذييل جزء من صورة الليتر هيد
    }
}

/**
 * @param array  $settings  صف office_settings
 * @param string $html      محتوى الـ HTML (متوافق مع TCPDF — جداول وأنماط بسيطة)
 * @param string $filename  اسم الملف
 * @param array  $opts      title, dest ('D' تنزيل | 'I' عرض), font, size, header_fallback_html
 */
function mehkam_make_pdf(array $settings, string $html, string $filename, array $opts = [])
{
    $lhOn   = !empty($settings['letterhead_enabled']) && !empty($settings['letterhead_path']);
    $lhPath = $lhOn ? (dirname(__DIR__) . '/' . ltrim($settings['letterhead_path'], '/')) : '';
    $lhOn   = $lhOn && is_file($lhPath);

    $hasFb = !$lhOn && !empty($opts['header_fallback_html']);
    // بدون ليتر هيد: نترك مساحة كافية أعلى الصفحة للترويسة النصية البديلة + فراغ تنفّس
    $top  = $lhOn ? max(8,  min(150, (int)($settings['letterhead_top'] ?? 42)))    : ($hasFb ? 32 : 20);
    $bot  = $lhOn ? max(8,  min(150, (int)($settings['letterhead_bottom'] ?? 26))) : 18;
    $side = $lhOn ? max(8,  min(70,  (int)($settings['letterhead_side'] ?? 18)))   : 16;

    $font = $opts['font'] ?? 'amiri';
    $size = (float)($opts['size'] ?? 11);
    $destRaw = $opts['dest'] ?? 'D';
    $dest = in_array($destRaw, ['I', 'S', 'F'], true) ? $destRaw : 'D';

    $pdf = new MehkamPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->lhImg = $lhOn ? $lhPath : '';
    $pdf->lhFallback = $lhOn ? '' : ($opts['header_fallback_html'] ?? '');

    $pdf->SetCreator('مِحكام');
    $pdf->SetAuthor('منصة مِحكام');
    $pdf->SetTitle($opts['title'] ?? $filename);
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(false);
    $pdf->setHeaderMargin(0);
    $pdf->setFooterMargin(0);
    $pdf->SetMargins($side, $top, $side, true);
    $pdf->SetAutoPageBreak(true, $bot);
    $pdf->setImageScale(1.25);
    $pdf->setRTL(true);
    $pdf->setFontSubsetting(true);
    $pdf->SetFont($font, '', $size);
    // كثافة نقاط لصورة الليتر هيد (وضوح أعلى)
    $pdf->setJPEGQuality(92);

    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');

    // رمز QR (ZATCA) — يُرسم مباشرة لأن وسم <barcode> في HTML لا يدعم الأكواد ثنائية الأبعاد
    if (!empty($opts['qr'])) {
        $qsize = (float)($opts['qr_size'] ?? 34);
        $qy = $pdf->GetY() + 5;
        if ($qy > ($pdf->getPageHeight() - $bot - $qsize - 8)) {
            $pdf->AddPage();
            $qy = $pdf->GetY() + 3;
        }
        $qx = ($pdf->getPageWidth() - $qsize) / 2;
        $wasRTL = $pdf->getRTL();
        $pdf->setRTL(false);
        $pdf->write2DBarcode($opts['qr'], 'QRCODE,M', $qx, $qy, $qsize, $qsize, [
            'border' => false, 'padding' => 1.5,
            'fgcolor' => [12, 27, 54], 'bgcolor' => [255, 255, 255],
        ], 'N');
        $pdf->setRTL($wasRTL);
        $mg = $pdf->getMargins();
        $pdf->SetXY($mg['left'], $qy + $qsize + 1.5);
        if (!empty($opts['qr_caption'])) {
            $pdf->SetFont($font, '', 7.5);
            $pdf->SetTextColor(120, 130, 150);
            $pdf->Cell(0, 4, $opts['qr_caption'], 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    // تنظيف أي مخرجات سابقة
    if (ob_get_length()) { @ob_end_clean(); }

    // S = إرجاع الـ PDF كسلسلة (للإرفاق في البريد مثلاً) دون exit
    if ($dest === 'S') {
        return $pdf->Output($filename, 'S');
    }
    // F = حفظ في ملف ثم إرجاع المسار دون exit
    if ($dest === 'F') {
        $pdf->Output($filename, 'F');
        return $filename;
    }
    $pdf->Output($filename, $dest);
    exit;
}

endif;
